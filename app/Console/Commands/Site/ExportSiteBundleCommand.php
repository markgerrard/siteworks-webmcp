<?php

namespace App\Console\Commands\Site;

use App\Services\Site\SiteBundle\SiteBundleExportService;
use Illuminate\Console\Command;

class ExportSiteBundleCommand extends Command
{
    protected $signature = 'site:export-bundle
        {site : Site id}
        {--out= : Output directory (default: storage/app/exports/site-bundles/{site})}';

    protected $description = 'Export one site\'s complete render + portal state to a portable JSON bundle + files directory (e.g. to seed a standalone demo).';

    public function handle(SiteBundleExportService $exporter): int
    {
        $siteId = (int) $this->argument('site');
        $out = (string) ($this->option('out') ?: storage_path("app/exports/site-bundles/{$siteId}"));

        try {
            $manifest = $exporter->export($siteId, $out);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Exported site id={$siteId} to {$out}");
        foreach ($manifest['tables'] as $table => $count) {
            $this->line("  {$table}: {$count}");
        }
        $this->line("  files copied: {$manifest['files_copied']}");

        return self::SUCCESS;
    }
}
