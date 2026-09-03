<?php

namespace App\Console\Commands\Shop;

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\ShopSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MonitorSnapshotThresholds extends Command
{
    protected $signature = 'shop:monitor-snapshot-thresholds';

    protected $description = 'Flag sites whose shop snapshot crosses size/build-time/product-count thresholds';

    public function handle(): int
    {
        $siteIds = ShopSnapshot::select('site_id')->distinct()->pluck('site_id');

        foreach ($siteIds as $siteId) {
            $latest = ShopSnapshot::where('site_id', $siteId)
                ->where('status', ShopSnapshotStatus::Success)
                ->orderByDesc('version')
                ->first();

            if (! $latest) {
                continue;
            }

            if ($latest->size_bytes > 500_000) {
                Log::warning("shop snapshot threshold breach: site_id={$siteId} size_bytes={$latest->size_bytes}");
            }

            $recent = ShopSnapshot::where('site_id', $siteId)
                ->where('status', ShopSnapshotStatus::Success)
                ->orderByDesc('version')
                ->take(20)
                ->pluck('build_duration_ms')
                ->sort()
                ->values();

            if ($recent->count() >= 5) {
                $p95Index = min((int) ceil($recent->count() * 0.95), $recent->count() - 1);
                $p95 = $recent[$p95Index] ?? null;
                if ($p95 && $p95 > 500) {
                    Log::warning("shop snapshot threshold breach: site_id={$siteId} rebuild_p95_ms={$p95}");
                }
            }

            if ($latest->product_count > 500) {
                Log::info("shop snapshot advisory: site_id={$siteId} product_count={$latest->product_count}");
            }
        }

        return self::SUCCESS;
    }
}
