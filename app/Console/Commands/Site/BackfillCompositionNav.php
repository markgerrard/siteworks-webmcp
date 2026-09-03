<?php

namespace App\Console\Commands\Site;

use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\SiteDraft;
use App\Models\Site\SiteVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillCompositionNav extends Command
{
    protected $signature = 'site:backfill-composition-nav {--dry-run} {--site=}';

    protected $description = 'Port legacy Preview.snapshot.navigation.items (incl. group items) into site_drafts.composition and current site_versions.composition. Skips sites with no legacy preview or already-ported groups.';

    public function handle(): int
    {
        $sites = $this->option('site')
            ? Site::where('id', $this->option('site'))->get()
            : Site::all();

        $touched = 0;
        $skipped = 0;

        foreach ($sites as $site) {
            $outcome = $this->backfillSite($site);
            $this->line("site_id={$site->id}: {$outcome}");
            if (str_starts_with($outcome, 'backfilled')) {
                $touched++;
            } else {
                $skipped++;
            }
        }

        $this->info(($this->option('dry-run') ? 'Dry-run: would backfill ' : 'Backfilled ')."{$touched} sites; {$skipped} skipped.");

        return self::SUCCESS;
    }

    protected function backfillSite(Site $site): string
    {
        $preview = Preview::where('site_id', $site->id)->latest('id')->first();
        if (! $preview) {
            return 'skipped (no legacy preview)';
        }

        $legacyItems = $preview->snapshot['navigation']['items'] ?? null;
        if (empty($legacyItems)) {
            return 'skipped (legacy nav empty)';
        }

        // Build page_type → page_id map
        $pageIdByType = GeneratedPage::where('site_id', $site->id)
            ->pluck('id', 'page_type')
            ->all();

        $newItems = $this->transformItems($legacyItems, $pageIdByType);

        // Don't write if the resulting shape is identical to current composition
        $draft = SiteDraft::where('site_id', $site->id)->first();
        if ($draft && ($draft->composition['nav']['items'] ?? null) === $newItems) {
            return 'skipped (composition already matches)';
        }

        if ($this->option('dry-run')) {
            return 'backfilled (dry-run: would set '.count($newItems).' nav items, '.$this->countGroups($newItems).' groups)';
        }

        DB::transaction(function () use ($site, $newItems) {
            $draft = SiteDraft::where('site_id', $site->id)->first();
            if ($draft) {
                $comp = $draft->composition ?? [];
                $comp['nav']['items'] = $newItems;
                $draft->update(['composition' => $comp]);
            }

            // Also update current published version so the public site reflects immediately
            $current = \App\Models\Site\SiteVersionCurrent::where('site_id', $site->id)->first();
            if ($current) {
                $version = SiteVersion::find($current->version_id);
                if ($version) {
                    $comp = $version->composition ?? [];
                    $comp['nav']['items'] = $newItems;
                    $version->update(['composition' => $comp]);
                }
            }
        });

        return 'backfilled ('.count($newItems).' nav items, '.$this->countGroups($newItems).' groups)';
    }

    protected function transformItems(array $legacyItems, array $pageIdByType): array
    {
        $out = [];

        foreach ($legacyItems as $item) {
            if (($item['type'] ?? null) === 'group') {
                $children = [];
                foreach ($item['children'] ?? [] as $child) {
                    $pid = $pageIdByType[$child['page'] ?? ''] ?? null;
                    if (! $pid) {
                        continue;
                    }
                    $children[] = [
                        'type' => 'page',
                        'page_id' => $pid,
                        'label' => $child['nav_label'] ?? $child['page'] ?? '',
                    ];
                }
                if (empty($children)) {
                    continue;
                }
                $out[] = [
                    'type' => 'group',
                    'label' => $item['nav_label'] ?? 'Group',
                    'children' => $children,
                ];

                continue;
            }

            // flat page item
            $pid = $pageIdByType[$item['page'] ?? ''] ?? null;
            if (! $pid) {
                continue;
            }
            $out[] = [
                'type' => 'page',
                'page_id' => $pid,
                'label' => $item['nav_label'] ?? $item['page'] ?? '',
            ];
        }

        return $out;
    }

    protected function countGroups(array $items): int
    {
        return count(array_filter($items, fn ($i) => ($i['type'] ?? null) === 'group'));
    }
}
