<?php

namespace App\Console\Commands\Site;

use App\Models\GeneratedPage;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use App\Models\Site\SiteDraft;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\CompositionService;
use App\Services\Site\SitePublishService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ShopNavBackfill extends Command
{
    protected $signature = 'site:shop-nav {--backfill}';

    protected $description = 'Backfill the stored Shop nav entry for sites with a current shop snapshot.';

    public function handle(CompositionService $compositions, SitePublishService $publisher): int
    {
        if (! $this->option('backfill')) {
            $this->error('Pass --backfill to patch stored shop navigation.');

            return self::FAILURE;
        }

        $touched = 0;
        $skipped = 0;
        $orphaned = 0;

        ShopSnapshotCurrent::query()
            ->with('site')
            ->orderBy('site_id')
            ->chunkById(100, function ($currentSnapshots) use ($compositions, $publisher, &$touched, &$skipped, &$orphaned): void {
                foreach ($currentSnapshots as $currentSnapshot) {
                    // shop_snapshot_current retains rows for soft-deleted sites — the
                    // derived table is not cleaned up on site deletion. The relation is
                    // null for those, and dereferencing it would abort the whole backfill
                    // partway through, leaving some sites patched and the rest silently
                    // untouched.
                    if ($currentSnapshot->site === null) {
                        $orphaned++;

                        continue;
                    }

                    $site = $currentSnapshot->site;
                    $outcome = $this->backfillSite($site, $compositions, $publisher);

                    if ($outcome === 'published') {
                        $touched++;

                        continue;
                    }

                    if ($outcome === 'matched') {
                        $skipped++;

                        continue;
                    }

                    $this->warn("site {$site->id}: pending merchant edits — Shop entry will appear on next publish");
                    $touched++;
                }
            }, 'site_id');

        $this->info("Backfilled {$touched} sites; {$skipped} already matched.");

        if ($orphaned > 0) {
            $this->warn("Skipped {$orphaned} snapshot row(s) whose site no longer exists.");
        }

        return self::SUCCESS;
    }

    private function backfillSite(
        Site $site,
        CompositionService $compositions,
        SitePublishService $publisher,
    ): string {
        if (! $site->hasPurchasableShop()) {
            return 'matched';
        }

        return DB::transaction(function () use ($site, $compositions, $publisher): string {
            $changed = $compositions->ensureShopNavEntry($site);

            $draft = SiteDraft::query()
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->firstOrFail();

            $pages = GeneratedPage::query()
                ->where('site_id', $site->id)
                ->published()
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'draft_revision_id', 'published_revision_id']);

            $current = SiteVersionCurrent::query()
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->first();
            $published = $current
                ? SiteVersion::query()->lockForUpdate()->find($current->version_id)
                : null;

            if ($published && $this->compositionHasShopEntry($published->composition ?? [])) {
                return 'matched';
            }

            if ($changed) {
                $compositions->bumpAdminRevision($site, invalidatePublicCache: false);
            }

            if (! $published) {
                return 'pending';
            }

            if ($this->withoutShopEntry($draft->composition ?? []) !== $this->withoutShopEntry($published->composition ?? [])) {
                return 'pending';
            }

            $draftPins = $pages->map(function (GeneratedPage $page): ?array {
                $revisionId = $page->draft_revision_id ?? $page->published_revision_id;

                return $revisionId ? [
                    'page_id' => (int) $page->id,
                    'revision_id' => (int) $revisionId,
                ] : null;
            })->all();

            if (in_array(null, $draftPins, true) || $draftPins !== $this->normalizedPins($published->page_revisions ?? [])) {
                return 'pending';
            }

            if (! $changed) {
                $compositions->bumpAdminRevision($site, invalidatePublicCache: false);
            }
            // publishSite also promotes pending hero/logo selections and the hero-scene
            // draft — a SEPARATE draft channel that the composition/page-pin comparison
            // cannot see, and that rollback does not restore. Publishing them under a
            // system backfill would push a merchant's unpublished visuals live.
            if (app(\App\Services\Site\Editor\DraftAssetSelections::class)->any($site)) {
                return 'pending';
            }

            $publisher->publishSite($site, publishNote: 'shop-nav-backfill');

            return 'published';
        }, 3);
    }

    /** @param array<string, mixed> $composition */
    private function compositionHasShopEntry(array $composition): bool
    {
        return collect($composition['nav']['items'] ?? [])
            ->contains(fn ($item): bool => is_array($item) && ($item['type'] ?? null) === 'shop');
    }

    /**
     * @param  array<string, mixed>  $composition
     * @return array<string, mixed>
     */
    private function withoutShopEntry(array $composition): array
    {
        $composition['nav']['items'] = array_values(array_filter(
            $composition['nav']['items'] ?? [],
            fn ($item): bool => ! is_array($item) || ($item['type'] ?? null) !== 'shop',
        ));

        return $composition;
    }

    /**
     * @param  array<int, mixed>  $pins
     * @return array<int, array{page_id: int, revision_id: int}>|null
     */
    private function normalizedPins(array $pins): ?array
    {
        $normalized = [];

        foreach ($pins as $pin) {
            if (! is_array($pin) || ! isset($pin['page_id'], $pin['revision_id'])) {
                return null;
            }

            $normalized[] = [
                'page_id' => (int) $pin['page_id'],
                'revision_id' => (int) $pin['revision_id'],
            ];
        }

        usort($normalized, fn (array $left, array $right): int => $left['page_id'] <=> $right['page_id']);

        return $normalized;
    }
}
