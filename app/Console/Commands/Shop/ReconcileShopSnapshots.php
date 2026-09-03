<?php

namespace App\Console\Commands\Shop;

use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\Product;
use App\Models\Site;
use Illuminate\Console\Command;

class ReconcileShopSnapshots extends Command
{
    protected $signature = 'shop:reconcile {--site=}';

    protected $description = 'Rebuild shop snapshots for all sites (or one via --site).';

    public function handle(): int
    {
        $sites = $this->option('site')
            ? [(int) $this->option('site')]
            : Product::select('site_id')->distinct()->pluck('site_id')->map(fn ($id) => (int) $id);

        $sites = Site::query()
            ->where('shop_enabled', true)
            ->whereIn('id', $sites)
            ->pluck('id')
            ->all();

        foreach ($sites as $siteId) {
            RebuildShopSnapshot::dispatch((int) $siteId);
        }

        $this->info('Dispatched '.count($sites).' rebuild jobs.');

        return self::SUCCESS;
    }
}
