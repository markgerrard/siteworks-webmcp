<?php

namespace App\Console\Commands\Media;

use App\Services\Media\MediaLibraryService;
use Illuminate\Console\Command;

class PurgeProvisionalMediaCommand extends Command
{
    protected $signature = 'media:purge-provisional {--hours=24 : Age threshold in hours}';

    protected $description = 'Hard-delete provisional site_media rows older than the threshold (default 24h).';

    public function handle(MediaLibraryService $library): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $cutoff = now()->subHours($hours);
        $deleted = $library->purgeProvisional($cutoff);

        $this->info("Purged {$deleted} provisional media older than {$hours}h (before {$cutoff->toDateTimeString()}).");

        return self::SUCCESS;
    }
}
