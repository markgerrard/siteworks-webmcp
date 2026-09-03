<?php

namespace App\Services\Shop;

use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use Illuminate\Support\Facades\Cache;

class SnapshotReader
{
    public const CACHE_TTL_SECONDS = 3600;

    public function forSite(int $siteId): ?array
    {
        return Cache::remember(
            $this->key($siteId),
            self::CACHE_TTL_SECONDS,
            function () use ($siteId) {
                $pointer = ShopSnapshotCurrent::where('site_id', $siteId)->first();
                if (! $pointer) {
                    return null;
                }

                return ShopSnapshot::find($pointer->snapshot_id)?->json;
            }
        );
    }

    public function invalidate(int $siteId): void
    {
        Cache::forget($this->key($siteId));
    }

    private function key(int $siteId): string
    {
        return "shop:snapshot:{$siteId}";
    }
}
