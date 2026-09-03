<?php

namespace App\Services\Shop;

use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\Product;
use App\Models\Shop\ShopDraft;
use App\Models\Site;
use App\Observers\Shop\CatalogObserver;
use App\Support\Shop\AutoTagConfig;
use App\Support\Shop\ProductTagVocabulary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ProductTagSettings
{
    /**
     * @param  list<array{slug: string, label: string, show_as_badge: bool, tone: string}>  $productTags
     * @param  array<string, mixed>  $autoTags
     */
    public function save(Site $site, array $productTags, array $autoTags, ?string $expectedRevision = null): void
    {
        DB::transaction(function () use ($site, $productTags, $autoTags, $expectedRevision): void {
            $locked = Site::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
            if ($expectedRevision !== null && ! hash_equals(self::revision($locked), $expectedRevision)) {
                throw new InvalidArgumentException('Tags were changed elsewhere — reload and try again.');
            }

            $parsed = ProductTagVocabulary::parse($productTags);
            $removed = array_values(array_diff(
                array_column(ProductTagVocabulary::normalize($locked->product_tags), 'slug'),
                array_column($parsed, 'slug'),
            ));

            $locked->update([
                'product_tags' => $parsed,
                'auto_tags' => AutoTagConfig::parse($autoTags),
            ]);

            if ($removed !== []) {
                $this->sweepRemovedSlugs($locked, $removed);
            }
        });

        RebuildShopSnapshot::dispatch($site->id);
    }

    /**
     * @param  list<string>  $removed
     */
    private function sweepRemovedSlugs(Site $site, array $removed): void
    {
        $nested = CatalogObserver::isMuted();
        if (! $nested) {
            CatalogObserver::mute();
        }

        $touched = false;
        try {
            $products = Product::query()->where('site_id', $site->id)->lockForUpdate()->get();
            foreach ($products as $product) {
                $tags = is_array($product->tags) ? $product->tags : [];
                $cleaned = array_values(array_filter(
                    $tags,
                    fn (mixed $slug): bool => is_string($slug) && ! in_array($slug, $removed, true),
                ));
                if ($cleaned === $tags) {
                    continue;
                }
                $product->update([
                    'tags' => $cleaned,
                    'revision' => (int) $product->revision + 1,
                ]);
                $touched = true;
            }
        } finally {
            if (! $nested) {
                CatalogObserver::unmute();
                CatalogObserver::takeDirtySiteIds();
            }
        }

        if (! $touched) {
            return;
        }

        ShopDraft::query()->insertOrIgnore([[
            'site_id' => $site->id,
            'catalogue_revision' => 0,
            'updated_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]]);
        $draft = ShopDraft::query()->where('site_id', $site->id)->lockForUpdate()->firstOrFail();
        $draft->catalogue_revision = (int) $draft->catalogue_revision + 1;
        $draft->save();
    }

    public static function revision(Site $site): string
    {
        return hash('sha256', json_encode([
            $site->product_tags ?? [],
            $site->auto_tags ?? [],
        ], JSON_THROW_ON_ERROR));
    }
}
