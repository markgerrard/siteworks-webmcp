<?php

namespace App\Console\Commands\Shop;

use App\Services\Shop\PersonalisationImageStore;
use Illuminate\Console\Command;

class PrunePersonalisationOrphans extends Command
{
    protected $signature = 'shop:prune-personalisation-orphans {--days= : Age threshold in days} {--dry-run : Report deletions without deleting files}';

    protected $description = 'Delete abandoned cart personalisation images older than the configured age';

    public function handle(PersonalisationImageStore $images): int
    {
        $days = max(1, (int) ($this->option('days') ?: config('shop_input_presets.orphan_days', 14)));
        $dryRun = (bool) $this->option('dry-run');
        $deleted = $images->pruneOrphans(now()->subDays($days), $dryRun);
        $verb = $dryRun ? 'Would delete' : 'Deleted';
        $this->info("{$verb} {$deleted} orphaned personalisation image(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
