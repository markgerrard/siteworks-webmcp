<?php

namespace App\Console\Commands\Shop;

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use Illuminate\Console\Command;

class PruneOldSnapshots extends Command
{
    protected $signature = 'shop:prune-snapshots';

    protected $description = 'Prune old success snapshots (keep last 5) and failed snapshots older than 7 days';

    public const KEEP_SUCCESS = 5;

    public const FAILED_RETENTION_DAYS = 7;

    public function handle(): int
    {
        $siteIds = ShopSnapshot::select('site_id')->distinct()->pluck('site_id');

        foreach ($siteIds as $siteId) {
            $currentId = ShopSnapshotCurrent::where('site_id', $siteId)->value('snapshot_id');

            $keepIds = ShopSnapshot::where('site_id', $siteId)
                ->where('status', ShopSnapshotStatus::Success)
                ->orderByDesc('version')
                ->take(self::KEEP_SUCCESS)
                ->pluck('id')
                ->toArray();

            if ($currentId) {
                $keepIds[] = $currentId;
            }

            ShopSnapshot::where('site_id', $siteId)
                ->where('status', ShopSnapshotStatus::Success)
                ->whereNotIn('id', array_unique($keepIds))
                ->delete();

            ShopSnapshot::where('site_id', $siteId)
                ->where('status', ShopSnapshotStatus::Failed)
                ->where('built_at', '<', now()->subDays(self::FAILED_RETENTION_DAYS))
                ->delete();
        }

        return self::SUCCESS;
    }
}
