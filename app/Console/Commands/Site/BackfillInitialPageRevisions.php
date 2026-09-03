<?php

namespace App\Console\Commands\Site;

use App\Models\GeneratedPage;
use App\Models\Site\PageRevision;
use Illuminate\Console\Command;

class BackfillInitialPageRevisions extends Command
{
    protected $signature = 'site:backfill-initial-page-revisions';

    protected $description = 'Create an initial revision for each generated_pages row lacking published_revision_id. Idempotent.';

    public function handle(): int
    {
        // Strict legacy selector: pages with NEITHER pointer set + content_data present.
        // This protects pages that already have a draft (via PageService) from being silently
        // converted to "published" by this one-shot.
        $pages = GeneratedPage::whereNull('published_revision_id')
            ->whereNull('draft_revision_id')
            ->whereNotNull('content_data')
            ->get();

        $count = 0;

        foreach ($pages as $page) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($page, &$count) {
                $rev = PageRevision::create([
                    'page_id' => $page->id,
                    'content_data' => $page->content_data,
                    'ai_generated' => true,
                    'ai_model_version' => $page->model_used,
                    // Preserve the original page's creation time so revision history is chronologically honest.
                    'created_at' => $page->created_at ?? $page->updated_at ?? now(),
                ]);

                $page->update(['published_revision_id' => $rev->id]);
                $count++;
            });
        }

        $this->info("Backfilled initial revisions for {$count} pages.");

        return self::SUCCESS;
    }
}
