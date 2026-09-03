<?php

namespace App\Services\Site\SiteBundle;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class SiteBundleExportService
{
    /**
     * @return array<string, mixed> the manifest that was written into bundle.json
     */
    public function export(int $siteId, string $outDir): array
    {
        $site = DB::table('sites')->where('id', $siteId)->first();
        if (! $site) {
            throw new \RuntimeException("No site with id={$siteId}.");
        }

        File::ensureDirectoryExists($outDir);
        File::ensureDirectoryExists($outDir.'/files');

        $tables = [];
        $tableCounts = [];
        $generatedPageIds = [];

        foreach (SiteBundleCatalog::TABLES as $table) {
            $rows = $this->exportRows($table, $siteId, $generatedPageIds);

            if ($table === 'generated_pages') {
                $generatedPageIds = array_column($rows, 'id');
            }

            $tables[$table] = $rows;
            $tableCounts[$table] = count($rows);
        }

        $filesCopied = $this->copyFiles($tables, $outDir);

        $manifest = [
            'source_site_id' => $siteId,
            'exported_at' => now()->toIso8601String(),
            'app_commit' => $this->currentCommit(),
            'tables' => $tableCounts,
            'files_copied' => count($filesCopied),
        ];

        $bundle = [
            'manifest' => $manifest,
            'tables' => $tables,
        ];

        File::put(
            $outDir.'/bundle.json',
            json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        return $manifest;
    }

    /**
     * @param  list<int>  $generatedPageIds  populated once generated_pages has been exported;
     *                                       only needed by generated_page_revisions
     * @return list<array<string, mixed>>
     */
    private function exportRows(string $table, int $siteId, array $generatedPageIds): array
    {
        $scopeColumn = SiteBundleCatalog::SCOPE_COLUMN[$table] ?? 'site_id';

        $query = DB::table($table);
        if (in_array($table, SiteBundleCatalog::SCOPED_VIA_PARENT_ROWS, true)) {
            if ($generatedPageIds === []) {
                return [];
            }
            $query->whereIn($scopeColumn, $generatedPageIds);
        } else {
            $query->where($scopeColumn, $siteId);
        }

        $primaryKey = SiteBundleCatalog::PRIMARY_KEY[$table] ?? 'id';

        if (in_array($table, SiteBundleCatalog::LATEST_ONLY, true)) {
            $query->orderByDesc('published_at')->orderByDesc($primaryKey)->limit(1);
        } else {
            $query->orderBy($primaryKey);
        }

        $jsonColumns = $this->jsonColumnsFor($table);

        return $query->get()->map(function ($row) use ($jsonColumns): array {
            $row = (array) $row;
            foreach ($jsonColumns as $column) {
                if (array_key_exists($column, $row) && is_string($row[$column]) && $row[$column] !== '') {
                    $decoded = json_decode($row[$column], true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $row[$column] = $decoded;
                    }
                }
            }

            return $row;
        })->all();
    }

    /**
     * @return list<string>
     */
    private function jsonColumnsFor(string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        if (DB::getDriverName() === 'pgsql') {
            $rows = DB::select(
                "select column_name from information_schema.columns where table_name = ? and data_type in ('json', 'jsonb')",
                [$table],
            );

            return $cache[$table] = array_map(fn ($r) => $r->column_name, $rows);
        }

        // SQLite (the public demo) stores json() columns as text: decode any column whose
        // value looks like a JSON object/array. Only well-formed JSON is decoded, so a plain
        // string that happens to start with "{" survives unchanged.
        return $cache[$table] = array_values(array_filter(
            Schema::getColumnListing($table),
            function (string $column) use ($table): bool {
                $sample = DB::table($table)->whereNotNull($column)->value($column);

                return is_string($sample) && ($sample[0] ?? '') !== '' && in_array($sample[0], ['{', '['], true)
                    && json_decode($sample, true) !== null && json_last_error() === JSON_ERROR_NONE;
            },
        ));
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $tables
     * @return list<string> the disk-relative keys copied
     */
    private function copyFiles(array $tables, string $outDir): array
    {
        $keys = []; // "disk|key" => [disk, key]

        foreach (SiteBundleCatalog::FILE_COLUMNS as [$table, $column, $diskResolver]) {
            $disk = BundleMediaResolver::diskName($diskResolver);
            foreach ($tables[$table] ?? [] as $row) {
                $key = BundleMediaResolver::relativeKey($row[$column] ?? null, $disk);
                if ($key !== null) {
                    $keys["{$disk}|{$key}"] = [$disk, $key];
                }
            }
        }

        foreach (SiteBundleCatalog::CONTENT_SCAN_COLUMNS as [$table, $column, $diskResolver]) {
            $disk = BundleMediaResolver::diskName($diskResolver);
            foreach ($tables[$table] ?? [] as $row) {
                foreach (BundleMediaResolver::collectStrings($row[$column] ?? null) as $candidate) {
                    $key = BundleMediaResolver::relativeKey($candidate, $disk);
                    if ($key !== null) {
                        $keys["{$disk}|{$key}"] = [$disk, $key];
                    }
                }
            }
        }

        $copied = [];
        foreach ($keys as [$disk, $key]) {
            $contents = Storage::disk($disk)->get($key);
            if ($contents === null) {
                continue;
            }
            $dest = $outDir.'/files/'.$key;
            File::ensureDirectoryExists(dirname($dest));
            File::put($dest, $contents);
            $copied[] = $key;
        }

        return $copied;
    }

    private function currentCommit(): ?string
    {
        $head = @exec('git rev-parse HEAD 2>/dev/null', $out, $status);

        return ($status === 0 && $head !== false && $head !== '') ? $head : null;
    }
}
