<?php

namespace App\Console\Commands\Site;

use App\Models\Site;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use Illuminate\Console\Command;

class SmokeTestVersionedRenderer extends Command
{
    protected $signature = 'site:smoke-test-versioned-renderer {--site=}';

    protected $description = 'Render the homepage for every migrated site via the new PageRenderer. Reports OK / SKIP / FAIL per site.';

    public function handle(PageRenderer $renderer): int
    {
        $sites = $this->option('site')
            ? Site::where('id', $this->option('site'))->get()
            : Site::all();

        $ok = $skip = $fail = 0;

        foreach ($sites as $site) {
            $current = SiteVersionCurrent::where('site_id', $site->id)->first();
            if (! $current) {
                $this->line("site_id={$site->id}: SKIP (no current pointer)");
                $skip++;
                continue;
            }

            $version = SiteVersion::find($current->version_id);
            $homeId = $version?->composition['homepage_page_id'] ?? null;
            if (! $homeId) {
                $this->line("site_id={$site->id}: SKIP (no homepage in composition)");
                $skip++;
                continue;
            }

            try {
                $html = $renderer->render($site, $homeId, mode: 'public');
                if (strlen($html) > 100) {
                    $this->line("site_id={$site->id}: OK (".strlen($html).' bytes)');
                    $ok++;
                } else {
                    $this->line("site_id={$site->id}: FAIL (rendered html suspiciously short: ".strlen($html).' bytes)');
                    $fail++;
                }
            } catch (\Throwable $e) {
                $this->line("site_id={$site->id}: FAIL ({$e->getMessage()})");
                $fail++;
            }
        }

        $this->info("Smoke test complete. OK: {$ok}, SKIP: {$skip}, FAIL: {$fail}");

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}
