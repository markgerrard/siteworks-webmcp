<?php

namespace App\Console\Commands\Site;

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Services\Site\ContentShapeTranslator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot: translate legacy `generated_pages.content_data` /
 * `generated_page_revisions.content_data` rows from the old map shape
 * (`['hero' => [...], 'services' => [...], ...]`) to the new shape
 * (`['sections' => [...], 'meta' => [...]]`) expected by PageRenderer.
 *
 * Idempotent — rows already containing a `sections` key are skipped.
 * Operates in a per-site transaction so a mid-run crash leaves
 * the site either entirely translated or entirely untouched.
 */
class MigrateLegacyContentShape extends Command
{
    protected $signature = 'site:migrate-legacy-content-shape {--dry-run} {--site=}';

    protected $description = 'Translate legacy content_data map shape into the new sections-array shape. Idempotent.';

    public function handle(ContentShapeTranslator $translator): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $sites = $this->option('site')
            ? Site::where('id', $this->option('site'))->get()
            : Site::all();

        if ($sites->isEmpty()) {
            $this->warn('No sites matched.');

            return self::SUCCESS;
        }

        $totalTranslated = 0;
        $totalSkipped = 0;

        foreach ($sites as $site) {
            [$translated, $skipped] = $this->migrateSite($site, $translator, $dryRun);
            $totalTranslated += $translated;
            $totalSkipped += $skipped;
            $this->line("site_id={$site->id}: translated {$translated} revisions, {$skipped} skipped (already translated)");
        }

        $verb = $dryRun ? 'Would translate' : 'Translated';
        $this->info("{$verb} {$totalTranslated} revisions across {$sites->count()} sites; {$totalSkipped} already-translated rows skipped.");

        return self::SUCCESS;
    }

    /**
     * Migrate one site. Returns [translated, skipped] counts.
     *
     * @return array{0: int, 1: int}
     */
    protected function migrateSite(Site $site, ContentShapeTranslator $translator, bool $dryRun): array
    {
        $translated = 0;
        $skipped = 0;

        $pageIds = GeneratedPage::where('site_id', $site->id)->pluck('id');
        if ($pageIds->isEmpty()) {
            return [0, 0];
        }

        $apply = function () use ($site, $pageIds, $translator, $dryRun, &$translated, &$skipped): void {
            // Revisions
            $revisions = PageRevision::whereIn('page_id', $pageIds)->get();
            foreach ($revisions as $revision) {
                $data = $revision->content_data ?? [];
                if (isset($data['sections'])) {
                    $skipped++;

                    continue;
                }
                $new = $translator->translate($data);
                if (! $dryRun) {
                    $revision->content_data = $new;
                    $revision->save();
                }
                $translated++;
            }

            // Mirrored generated_pages.content_data — the column is not retired
            // yet, so keep it aligned with the revision shape.
            $pages = GeneratedPage::where('site_id', $site->id)->get();
            foreach ($pages as $page) {
                $data = $page->content_data ?? [];
                if ($data === [] || isset($data['sections'])) {
                    continue;
                }
                $new = $translator->translate($data);
                if (! $dryRun) {
                    $page->content_data = $new;
                    $page->save();
                }
            }
        };

        if ($dryRun) {
            $apply();
        } else {
            DB::transaction($apply);
        }

        return [$translated, $skipped];
    }
}
