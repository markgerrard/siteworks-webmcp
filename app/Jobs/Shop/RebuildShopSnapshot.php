<?php

namespace App\Jobs\Shop;

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use App\Services\Shop\RenderContext;
use App\Services\Shop\SnapshotBuilder;
use App\Services\Site\CompositionService;
use App\Services\Site\PublicPageCache;
use App\Support\ChromeKnobs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Rebuild the shop_snapshots row for a site.
 *
 * Idempotent: same input DB state → same output JSON.
 * Concurrent-safe: version allocation runs under a per-site lockForUpdate.
 *
 * Debouncing not currently applied at the job level — see spec section 5
 * (Tier A debounce). ShouldBeUniqueUntilProcessing was removed because its
 * cache-backed lock introduced flakiness in tests; re-introduce later via
 * either the observer (dedupe by (site_id, second)) or a DB advisory lock.
 * Safe to run duplicate rebuilds for now: extra writes, identical output.
 */
class RebuildShopSnapshot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $siteId) {}

    /**
     * Products in a snapshot that the storefront would show the public.
     *
     * @param  array<string, mixed>|string|null  $json
     */
    private static function countPublishedInSnapshot($json): int
    {
        if (is_string($json)) {
            $json = json_decode($json, true);
        }
        if (! is_array($json)) {
            return 0;
        }

        $count = 0;
        foreach ($json['products'] ?? [] as $product) {
            if (($product['status'] ?? 'published') === 'published') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * PublicCatalogueProjection of two snapshot payloads, as spec §3.1.4 / §9 G6.
     *
     * @param  array<string, mixed>|string|null  $previous
     * @param  array<string, mixed>|string|null  $next
     */
    private static function publicProjectionChanged($previous, $next): bool
    {
        $filter = new RenderContext(false);

        $previousJson = is_string($previous) ? json_decode($previous, true) : $previous;
        $nextJson = is_string($next) ? json_decode($next, true) : $next;
        $previousPublic = self::publicProjection($filter->filterSnapshot(is_array($previousJson) ? $previousJson : []));
        $nextPublic = self::publicProjection($filter->filterSnapshot(is_array($nextJson) ? $nextJson : []));

        return $previousPublic !== $nextPublic;
    }

    /**
     * @param  array<string, mixed>  $filtered
     * @return array{categories: mixed, products: mixed, featured_slugs: mixed, hero_image_url: mixed, hero_alt: mixed, hero_height: mixed, bg_position_y: mixed, text_zone: mixed, hero_width: mixed, hero_enabled: mixed, hero_headline: mixed, hero_text_style: mixed, shared_category_hero: mixed}
     */
    private static function publicProjection(array $filtered): array
    {
        return [
            'categories' => $filtered['categories'] ?? [],
            'products' => $filtered['products'] ?? [],
            'featured_slugs' => $filtered['featured_slugs'] ?? [],
            'hero_image_url' => $filtered['hero_image_url'] ?? null,
            'hero_alt' => $filtered['hero_alt'] ?? null,
            'hero_height' => $filtered['hero_height'] ?? null,
            'bg_position_y' => $filtered['bg_position_y'] ?? null,
            'text_zone' => $filtered['text_zone'] ?? null,
            'hero_width' => $filtered['hero_width'] ?? null,
            'hero_enabled' => $filtered['hero_enabled'] ?? null,
            'hero_headline' => $filtered['hero_headline'] ?? null,
            'hero_text_style' => $filtered['hero_text_style'] ?? null,
            'shared_category_hero' => $filtered['shared_category_hero'] ?? null,
        ];
    }

    public function handle(SnapshotBuilder $builder, ?CompositionService $compositionService = null): void
    {
        if (! Site::shopEnabledFor($this->siteId)) {
            return;
        }

        // Allocate version + building row atomically under a site-scoped lock so
        // concurrent dispatches (unique-lock released but a second job already in
        // queue) can't both compute max+1 and collide on the UNIQUE(site_id, version).
        $row = DB::transaction(function () {
            ShopSnapshot::where('site_id', $this->siteId)
                ->lockForUpdate()
                ->select('id')
                ->get();

            $lastVersion = ShopSnapshot::where('site_id', $this->siteId)->max('version') ?? 0;

            return ShopSnapshot::create([
                'site_id' => $this->siteId,
                'version' => $lastVersion + 1,
                'status' => ShopSnapshotStatus::Building,
                'built_at' => now(),
                'built_by_job_id' => $this->job?->getJobId(),
            ]);
        });

        $newVersion = $row->version;

        $start = microtime(true);

        try {
            $json = $builder->build($this->siteId);
            $json['meta']['version'] = $newVersion;
            $json['meta']['product_count'] = count($json['products'] ?? []);
            $json['meta']['build_duration_ms'] = (int) ((microtime(true) - $start) * 1000);

            // Compute size_bytes last (self-referential: size of encoded JSON
            // as it will be stored). Off-by-a-few for the size_bytes field
            // itself, which is fine for monitoring thresholds.
            $encoded = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $json['meta']['size_bytes'] = strlen($encoded);

            $row->update([
                'json' => $json,
                'status' => ShopSnapshotStatus::Success,
                'size_bytes' => strlen($encoded),
                'build_duration_ms' => $json['meta']['build_duration_ms'],
                'product_count' => $json['meta']['product_count'],
                'hero_image_url' => $json['hero_image_url'] ?? null,
                'hero_alt' => $json['hero_alt'] ?? null,
                'hero_height' => $json['hero_height'] ?? 'medium',
                'bg_position_y' => $json['bg_position_y'] ?? 50,
                'text_zone' => $json['text_zone'] ?? 'middle-left',
                'hero_width' => $json['hero_width'] ?? 'boxed',
                'hero_enabled' => $json['hero_enabled'] ?? true,
                'hero_headline' => $json['hero_headline'] ?? null,
                'hero_text_style' => $json['hero_text_style'] ?? null,
                'hero_accent_word' => $json['hero_accent_word'] ?? null,
                'shared_category_hero' => $json['shared_category_hero'] ?? null,
            ]);

            // The product count of the snapshot that was current BEFORE this rebuild.
            // Zero (or absent) means the site had nothing to sell until now.
            // Count PUBLISHED products only, on both sides. product_count is
            // draft-inclusive (SnapshotBuilder snapshots Draft AND Published) and products
            // default to Draft — so computing the transition from it spent the trigger on
            // the draft CREATE and left it silent on the publish that actually made the
            // site a shop. A normally-created shop then never gained its Shop nav entry:
            // the recurring counter hasPurchasableShop() documents as unusable.
            $previousCurrent = ShopSnapshotCurrent::query()->where('site_id', $this->siteId)->first();
            $previousJson = $previousCurrent
                ? ShopSnapshot::query()->whereKey($previousCurrent->snapshot_id)->value('json')
                : [];
            $previousPublished = $previousCurrent
                ? self::countPublishedInSnapshot($previousJson)
                : 0;

            $becamePurchasable = $previousPublished === 0 && self::countPublishedInSnapshot($json) > 0;

            $navInvalidated = false;

            DB::transaction(function () use ($row, $compositionService, $becamePurchasable, &$navInvalidated): void {
                ShopSnapshotCurrent::updateOrCreate(
                    ['site_id' => $this->siteId],
                    ['snapshot_id' => $row->id, 'updated_at' => now()]
                );

                // Fire on the TRANSITION to purchasable, not on row creation and not on
                // every rebuild. Both of those are wrong in opposite directions:
                //
                //  - `wasRecentlyCreated` is already spent for every existing site, because
                //    the reconcile created a shop_snapshot_current row for all of them
                //    BEFORE any had products. A site gaining its first product later would
                //    never get a Shop nav entry at all.
                //  - ensuring on every rebuild resurrects an entry the merchant deliberately
                //    removed, which is the merchant-intent case ShopNavEntryTest pins.
                //
                // The transition fires exactly once, when the site first has something to
                // sell, and never again — so removal sticks. "First" is sites.shop_first_purchasable_at
                // (D20), not a scan of shop_snapshots: those rows are garbage-collected by
                // shop:prune-snapshots (KEEP_SUCCESS = 5), and a 50-row horizon misses the
                // same fact even without prune. The timestamp is set in this transaction,
                // never cleared, and the UPDATE is skipped when it is already set.
                if (! $becamePurchasable) {
                    return;
                }

                $site = Site::query()
                    ->whereKey($this->siteId)
                    ->lockForUpdate()
                    ->first();

                if ($site === null || $site->shop_first_purchasable_at !== null) {
                    return;
                }

                $site->shop_first_purchasable_at = now();
                $site->save();

                $navInvalidated = ($compositionService ?? app(CompositionService::class))->ensureShopNavEntry($site);
            });

            app(\App\Services\Shop\SnapshotReader::class)->invalidate($this->siteId);
            app(\App\Services\Site\TrustSummary::class)->forget($this->siteId);
            if (self::publicProjectionChanged($previousJson, $json)) {
                app(\App\Services\Shop\CloudflarePurger::class)->purgeShop($this->siteId);
                // Rendered-page cache has exactly one invalidation owner per rebuild: the
                // first-purchasable nav insertion already bumps it, so this branch must not
                // double-bump it; otherwise bump when a page consumes the snapshot
                // (featured_products) or the header expands Shop from the snapshot
                // (shop_nav_style dropdown/mega).
                // Plain shop pages are not in PublicPageCache; the header on public pages is.
                if (! $navInvalidated && (self::sitePagesConsumeSnapshot($this->siteId) || self::siteHeaderConsumesSnapshot($this->siteId))) {
                    $siteForCache = Site::query()->find($this->siteId);
                    if ($siteForCache !== null) {
                        app(PublicPageCache::class)->invalidate($siteForCache);
                    }
                }
            }
        } catch (\Throwable $e) {
            $row->update([
                'status' => ShopSnapshotStatus::Failed,
                'build_duration_ms' => (int) ((microtime(true) - $start) * 1000),
                'build_error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * True when any page on the site carries a section rendered from the shop snapshot.
     */
    public static function sitePagesConsumeSnapshot(int $siteId): bool
    {
        $needle = '%"featured_products"%';

        return \App\Models\GeneratedPage::query()
            ->where('site_id', $siteId)
            ->where(function ($q) use ($needle) {
                // Published revision is the public truth; GeneratedPage.content_data is a draft
                // mirror once a page has been published, so it only counts for never-published pages.
                $q->whereHas('publishedRevision', fn ($r) => $r->whereRaw('cast(content_data as text) like ?', [$needle]))
                    ->orWhere(fn ($legacy) => $legacy->whereNull('published_revision_id')->whereRaw('cast(content_data as text) like ?', [$needle]));
            })
            ->exists();
    }

    /**
     * True when the public header expands Shop from the live snapshot, so a
     * category change must miss PublicPageCache (the header is baked into
     * cached page HTML; SnapshotReader is a separate key).
     */
    public static function siteHeaderConsumesSnapshot(int $siteId): bool
    {
        $site = Site::query()->find($siteId);
        if ($site === null || ! $site->hasPurchasableShop()) {
            return false;
        }

        return ChromeKnobs::shopNavStyle($site) !== 'link';
    }
}
