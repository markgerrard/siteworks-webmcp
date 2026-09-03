<?php

namespace App\Console\Commands\Site;

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\SiteDraft;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\CompositionDefaults;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateToVersionedArchitecture extends Command
{
    protected $signature = 'site:migrate-to-versioned-architecture {--site=}';

    protected $description = 'Idempotently seed site_drafts + site_versions v1 + current pointer for every live site.';

    public function handle(CompositionDefaults $defaults): int
    {
        $sites = $this->option('site')
            ? Site::where('id', $this->option('site'))->get()
            : Site::all();

        $migrated = 0;
        $skipped = 0;

        foreach ($sites as $site) {
            $outcome = $this->migrateSite($site, $defaults);
            $this->line("site_id={$site->id}: {$outcome}");
            if (str_starts_with($outcome, 'migrated')) {
                $migrated++;
            } else {
                $skipped++;
            }
        }

        $this->info("Migration complete. {$migrated} migrated, {$skipped} skipped.");

        return self::SUCCESS;
    }

    protected function migrateSite(Site $site, CompositionDefaults $defaults): string
    {
        // Idempotent guard
        if (SiteVersionCurrent::where('site_id', $site->id)->exists()) {
            return 'skipped (already migrated)';
        }

        $pages = GeneratedPage::where('site_id', $site->id)->whereNull('archived_at')->get();
        if ($pages->isEmpty()) {
            return 'skipped (no pages)';
        }

        if ($pages->whereNull('published_revision_id')->isNotEmpty()) {
            return 'skipped (pages without revision pointers)';
        }

        DB::transaction(function () use ($site, $pages, $defaults) {
            // 1. Seed draft from defaults (derives nav from existing pages, theme from site)
            $composition = $defaults->forSite($site);
            SiteDraft::create([
                'site_id' => $site->id,
                'composition' => $composition,
                'updated_at' => now(),
            ]);

            // 2. Pin every page's published revision into v1
            $pageRevisions = $pages->map(fn ($p) => [
                'page_id' => $p->id,
                'revision_id' => $p->published_revision_id,
            ])->values()->toArray();

            $version = SiteVersion::create([
                'site_id' => $site->id,
                'version' => 1,
                'composition' => $composition,
                'page_revisions' => $pageRevisions,
                'published_at' => now(),
                'publish_note' => 'auto-migrated from legacy Preview snapshot',
            ]);

            // 3. Set current pointer
            SiteVersionCurrent::create([
                'site_id' => $site->id,
                'version_id' => $version->id,
                'updated_at' => now(),
            ]);
        });

        return 'migrated (v1, '.$pages->count().' pages pinned)';
    }
}
