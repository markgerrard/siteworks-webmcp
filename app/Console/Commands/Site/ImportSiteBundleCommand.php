<?php

namespace App\Console\Commands\Site;

use App\Services\Site\SiteBundle\SiteBundleImportService;
use Illuminate\Console\Command;

class ImportSiteBundleCommand extends Command
{
    protected $signature = 'site:import-bundle
        {path : Path to a directory containing bundle.json (and a files/ directory)}
        {--disk=local : Filesystem disk to copy bundle files into}
        {--rewrite=* : Repeatable FROM=TO prefix rewrite applied to every string value of every imported row}';

    protected $description = 'Import a site bundle produced by site:export-bundle into the current (expected-empty) database.';

    public function handle(SiteBundleImportService $importer): int
    {
        $path = (string) $this->argument('path');
        $disk = (string) $this->option('disk');
        $rewrites = [];
        foreach ((array) $this->option('rewrite') as $pair) {
            $eq = strpos((string) $pair, '=');
            if ($eq === false) {
                $this->error("Invalid --rewrite={$pair}; expected FROM=TO");

                return self::FAILURE;
            }
            $rewrites[] = [substr((string) $pair, 0, $eq), substr((string) $pair, $eq + 1)];
        }

        try {
            $result = $importer->import($path, $disk, $rewrites);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result['missing_tables'] !== []) {
            $this->warn('Skipped tables not present in this schema: '.implode(', ', $result['missing_tables']));
        }
        foreach ($result['dropped_columns'] as $table => $columns) {
            $this->warn("Dropped columns not present in {$table}: ".implode(', ', $columns));
        }

        $this->info("Imported site id={$result['site_id']}");
        foreach ($result['imported'] as $table => $count) {
            $this->line("  {$table}: {$count}");
        }
        $this->line("  files copied: {$result['files_copied']}");

        return self::SUCCESS;
    }
}
