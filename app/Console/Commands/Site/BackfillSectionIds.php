<?php

namespace App\Console\Commands\Site;

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Services\Site\Editor\SectionIdentifiers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillSectionIds extends Command
{
    protected $signature = 'site:backfill-section-ids {--dry-run} {--site=}';

    protected $description = 'Backfill section ids into every generated_page_revision and the generated_pages mirror. Idempotent.';

    public function handle(SectionIdentifiers $identifiers): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $sites = $this->option('site')
            ? Site::where('id', $this->option('site'))->get()
            : Site::all();

        if ($sites->isEmpty()) {
            $this->warn('No sites matched.');

            return self::SUCCESS;
        }

        $totalWritten = 0;
        $totalSkipped = 0;

        foreach ($sites as $site) {
            [$written, $skipped] = $this->backfillSite($site, $identifiers, $dryRun);
            $totalWritten += $written;
            $totalSkipped += $skipped;
            $this->line("site_id={$site->id}: wrote {$written} revisions, {$skipped} skipped (already done)");
        }

        $verb = $dryRun ? 'Would write' : 'Wrote';
        $this->info("{$verb} {$totalWritten} revisions across {$sites->count()} sites; {$totalSkipped} already-done rows skipped.");

        return self::SUCCESS;
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function backfillSite(Site $site, SectionIdentifiers $identifiers, bool $dryRun): array
    {
        $written = 0;
        $skipped = 0;

        $pageIds = GeneratedPage::where('site_id', $site->id)->pluck('id');
        if ($pageIds->isEmpty()) {
            return [0, 0];
        }

        $apply = function () use ($pageIds, $identifiers, $dryRun, &$written, &$skipped): void {
            // Every revision row, not just current pointers.
            // chunkById for memory safety on full corpus.
            PageRevision::whereIn('page_id', $pageIds)->chunkById(500, function ($revisions) use ($identifiers, $dryRun, &$written, &$skipped) {
                foreach ($revisions as $revision) {
                    $data = $revision->content_data ?? [];

                    // Skip legacy-shape rows (no sections key or not a list).
                    if (! isset($data['sections']) || ! array_is_list($data['sections'])) {
                        $skipped++;

                        continue;
                    }

                    // Check if all sections already have ids.
                    if ($this->allSectionsHaveIds($data)) {
                        $skipped++;

                        continue;
                    }

                    if ($dryRun) {
                        $written++;

                        continue;
                    }

                    $data = $identifiers->ensure($data);
                    $revision->content_data = $data;
                    $revision->save();
                    $written++;

                    // Honour the live pointer at write time. A snapshot taken
                    // before the transaction (or even at its start, under
                    // READ COMMITTED) can name a revision that is no longer
                    // current; the predicate makes that a no-op.
                    $this->mirrorIfStillCurrentPointer($revision, $data);
                }
            });
        };

        if ($dryRun) {
            $apply();
        } else {
            DB::transaction($apply);
        }

        return [$written, $skipped];
    }

    /**
     * Copy ensured content onto generated_pages only when this revision
     * is still the live pointer (draft, else published). No-op if an
     * intervening edit has advanced the page.
     *
     * @param  array<string, mixed>  $data
     */
    protected function mirrorIfStillCurrentPointer(PageRevision $revision, array $data): void
    {
        GeneratedPage::query()
            ->where('id', $revision->page_id)
            ->where(function ($query) use ($revision): void {
                $query->where('draft_revision_id', $revision->id)
                    ->orWhere(function ($query) use ($revision): void {
                        $query->whereNull('draft_revision_id')
                            ->where('published_revision_id', $revision->id);
                    });
            })
            ->update(['content_data' => $data]);
    }

    protected function allSectionsHaveIds(array $contentData): bool
    {
        $sections = $contentData['sections'] ?? [];

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            if (! isset($section['id'])) {
                return false;
            }
        }

        return true;
    }
}
