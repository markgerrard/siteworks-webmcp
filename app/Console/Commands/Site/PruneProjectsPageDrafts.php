<?php

namespace App\Console\Commands\Site;

use App\Models\ProjectsPageDraft;
use Illuminate\Console\Command;

class PruneProjectsPageDrafts extends Command
{
    protected $signature = 'site:prune-projects-page-drafts {--days=2 : Age threshold in days}';

    protected $description = 'Prune projects_page_drafts rows older than the threshold (default 2 days). Stash table for the GenerateProjectsPageJob two-stage refactor; rows past their useful life otherwise grow unbounded.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $deleted = ProjectsPageDraft::where('created_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} projects_page_drafts rows older than {$days}d (before {$cutoff->toDateTimeString()}).");

        return self::SUCCESS;
    }
}
