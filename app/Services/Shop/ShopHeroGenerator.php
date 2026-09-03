<?php

namespace App\Services\Shop;

use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\Category;
use App\Models\Shop\ShopHeroVersion;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ShopHeroGenerator
{
    public function generateForShop(Site $site, ?int $userId = null): ShopHeroVersion
    {
        throw new RuntimeException('Not available in this demo');
    }

    public function generateForCategory(Category $category, ?int $userId = null): ShopHeroVersion
    {
        throw new RuntimeException('Not available in this demo');
    }

    /**
     * Revert the current hero to a past version (shop or category).
     */
    public function selectVersion(ShopHeroVersion $version): void
    {
        if ($version->scope === 'shop') {
            $snapshot = ShopSnapshot::where('site_id', $version->site_id)
                ->orderByDesc('version')
                ->first();

            $snapshot?->update([
                'hero_image_url' => $version->image_url,
                'hero_alt' => $version->hero_alt,
            ]);
        } elseif ($version->scope === 'category-shared') {
            $snapshot = $this->currentSnapshotFor($version->site_id);
            if ($snapshot) {
                $this->mergeSharedCategoryHero($snapshot, [
                    'image_url' => $version->image_url,
                    'hero_alt' => $version->hero_alt,
                ]);
            }
        } else {
            $category = Category::find($version->scope_id);
            $category?->update([
                'hero_image_url' => $version->image_url,
                'hero_alt' => $version->hero_alt,
            ]);
        }

        RebuildShopSnapshot::dispatchSync($version->site_id);

        Log::info('Shop hero version selected', [
            'version_id' => $version->id,
            'scope' => $version->scope,
            'scope_id' => $version->scope_id,
        ]);
    }

    private function currentSnapshotFor(int $siteId): ?ShopSnapshot
    {
        $current = ShopSnapshotCurrent::where('site_id', $siteId)->first();

        return $current
            ? ShopSnapshot::find($current->snapshot_id)
            : ShopSnapshot::where('site_id', $siteId)->orderByDesc('version')->first();
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function mergeSharedCategoryHero(ShopSnapshot $snapshot, array $patch): void
    {
        $block = is_array($snapshot->shared_category_hero) ? $snapshot->shared_category_hero : [];
        $snapshot->update(['shared_category_hero' => array_merge($block, $patch)]);
    }
}
