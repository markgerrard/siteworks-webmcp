<?php

namespace App\Observers\Shop;

use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\Category;
use App\Models\Shop\FeaturedProduct;
use App\Models\Shop\Product;
use App\Models\Shop\ProductImage;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ProductVariantImage;
use App\Models\Site;

class CatalogObserver
{
    /**
     * Request/job-scoped mute. When true, catalogue saves record dirty site
     * ids instead of dispatching RebuildShopSnapshot. Not Model::withoutEvents().
     */
    public static bool $muted = false;

    private static int $muteDepth = 0;

    /**
     * @var array<int, true>
     */
    private static array $dirtySiteIds = [];

    public static function mute(): void
    {
        if (self::$muteDepth === 0) {
            self::$dirtySiteIds = [];
        }
        self::$muteDepth++;
        self::$muted = true;
    }

    public static function unmute(): void
    {
        self::$muteDepth = max(0, self::$muteDepth - 1);
        if (self::$muteDepth === 0) {
            self::$muted = false;
        }
    }

    public static function isMuted(): bool
    {
        return self::$muted;
    }

    /**
     * @return list<int>
     */
    public static function takeDirtySiteIds(): array
    {
        $ids = array_map(intval(...), array_keys(self::$dirtySiteIds));
        self::$dirtySiteIds = [];

        return $ids;
    }

    public function saved($model): void
    {
        $this->dispatchForModel($model);
    }

    public function deleted($model): void
    {
        $this->dispatchForModel($model);
    }

    private function dispatchForModel($model): void
    {
        $siteId = match (true) {
            $model instanceof Product => $model->site_id,
            $model instanceof Category => $model->site_id,
            $model instanceof FeaturedProduct => $model->site_id,
            $model instanceof ProductVariant => $model->product?->site_id,
            $model instanceof ProductImage => $model->product?->site_id,
            $model instanceof ProductVariantImage => $model->variant?->product?->site_id,
            default => null,
        };

        if (! $siteId) {
            return;
        }

        if (! Site::shopEnabledFor((int) $siteId)) {
            return;
        }

        if (self::$muted) {
            self::$dirtySiteIds[(int) $siteId] = true;

            return;
        }

        // Defer until the surrounding transaction commits (CategoryTreeService writes inside
        // DB::transaction; the redis worker must never rebuild from pre-commit state).
        // No-op when there is no open transaction.
        RebuildShopSnapshot::dispatch($siteId)->afterCommit();
    }
}
