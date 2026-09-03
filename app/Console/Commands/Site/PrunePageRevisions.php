<?php

namespace App\Console\Commands\Site;

use App\Models\GeneratedPage;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrunePageRevisions extends Command
{
    protected $signature = 'site:prune-page-revisions';

    protected $description = 'Prune page revisions: keep last N OR all from last D days, whichever more inclusive. Pinned revisions never pruned.';

    public function handle(): int
    {
        $keepCount = (int) config('site.revision_keep_count', 50);
        $keepDays = (int) config('site.revision_keep_days', 90);
        $keepSinceDate = $keepDays > 0 ? now()->subDays($keepDays) : null;

        $pageIds = PageRevision::select('page_id')->distinct()->pluck('page_id');
        $totalPruned = 0;

        // Record the start time before the loop: any revision created after this
        // moment is excluded from deletion even if it falls outside the keep window,
        // closing the race where a concurrent editField creates a revision between
        // our pointer snapshot and the delete query.
        $pruneStartTime = now();

        foreach ($pageIds as $pageId) {
            $pruned = DB::transaction(function () use ($pageId, $keepCount, $keepSinceDate, $pruneStartTime) {
                // Lock the page row so a concurrent editField can't update
                // draft_revision_id between our pointer read and our delete.
                $page = GeneratedPage::lockForUpdate()->find($pageId);
                if (! $page) {
                    return 0;
                }

                // Always keep current live/draft pointers.
                $pinned = array_filter([$page->published_revision_id, $page->draft_revision_id]);

                // Also keep every revision pinned by any site_version for this page's site,
                // so rollback to an older version never hits a pruned-revision FK violation.
                $versionPinned = SiteVersion::where('site_id', $page->site_id)
                    ->get(['page_revisions'])
                    ->flatMap(fn ($v) => collect($v->page_revisions)
                        ->where('page_id', $pageId)
                        ->pluck('revision_id'))
                    ->filter()
                    ->all();

                // Recent N kept by id
                $recentIds = PageRevision::where('page_id', $pageId)
                    ->orderByDesc('created_at')
                    ->take($keepCount)
                    ->pluck('id')
                    ->all();

                // Within-window kept by id
                $windowIds = $keepSinceDate
                    ? PageRevision::where('page_id', $pageId)->where('created_at', '>=', $keepSinceDate)->pluck('id')->all()
                    : [];

                $keepIds = array_unique(array_merge($pinned, $versionPinned, $recentIds, $windowIds));

                return PageRevision::where('page_id', $pageId)
                    ->whereNotIn('id', $keepIds)
                    // Never delete revisions created after prune started — they may
                    // have been added by a concurrent editField after our lock was
                    // released between pages.
                    ->where('created_at', '<', $pruneStartTime)
                    ->delete();
            });

            $totalPruned += $pruned;
        }

        $this->info("Pruned {$totalPruned} revisions across {$pageIds->count()} pages.");

        return self::SUCCESS;
    }
}
