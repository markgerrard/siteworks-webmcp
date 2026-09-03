<?php

namespace App\Services\Site\SiteBundle;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Finder\SplFileInfo;

class SiteBundleImportService
{
    /**
     * @param  list<array{0: string, 1: string}>  $rewrites
     * @return array{site_id: int, imported: array<string, int>, missing_tables: list<string>, dropped_columns: array<string, list<string>>, files_copied: int}
     */
    public function import(string $bundlePath, string $disk = 'local', array $rewrites = []): array
    {
        $bundleFile = rtrim($bundlePath, '/').'/bundle.json';
        if (! File::exists($bundleFile)) {
            throw new \RuntimeException("No bundle.json found at {$bundlePath}.");
        }

        $bundle = json_decode(File::get($bundleFile), true);
        if (! is_array($bundle) || ! isset($bundle['manifest'], $bundle['tables'])) {
            throw new \RuntimeException("{$bundleFile} is not a valid site bundle.");
        }

        $siteId = (int) $bundle['manifest']['source_site_id'];

        if (Schema::hasTable('sites') && DB::table('sites')->where('id', $siteId)->exists()) {
            throw new \RuntimeException(
                "Site id={$siteId} already exists in this database — refusing to import a bundle that's already been imported.",
            );
        }

        $missingTables = [];
        $presentTables = [];
        foreach (SiteBundleCatalog::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                $missingTables[] = $table;

                continue;
            }
            $presentTables[] = $table;
        }

        if (! in_array('sites', $presentTables, true)) {
            throw new \RuntimeException('The target database has no sites table — run the schema migration before importing a bundle.');
        }

        $imported = [];
        $pendingBackfills = [];
        $droppedColumns = [];

        DB::transaction(function () use ($bundle, $presentTables, $rewrites, &$imported, &$pendingBackfills, &$droppedColumns): void {
            foreach ($presentTables as $table) {
                $rows = $bundle['tables'][$table] ?? [];
                $imported[$table] = 0;
                // The bundle may come from a database that carries columns the target
                // schema never had (e.g. a dev-lineage export into a trimmed demo schema).
                // Unknown columns are dropped and reported, never guessed at.
                $known = array_flip(Schema::getColumnListing($table));

                foreach ($rows as $row) {
                    $row = $this->rewriteRow($row, $rewrites);
                    [$insertRow, $backfill] = $this->prepareRow($table, $row);
                    foreach (array_keys($insertRow) as $column) {
                        if (! isset($known[$column])) {
                            unset($insertRow[$column]);
                            $droppedColumns[$table][$column] = true;
                        }
                    }
                    $backfill = array_intersect_key($backfill, $known);
                    DB::table($table)->insert($insertRow);
                    $imported[$table]++;

                    if ($backfill !== []) {
                        $pendingBackfills[] = [$table, $row['id'], $backfill];
                    }
                }
            }

            foreach ($pendingBackfills as [$table, $id, $backfill]) {
                $update = array_filter($backfill, fn ($v) => $v !== null);
                if ($update !== []) {
                    DB::table($table)->where('id', $id)->update($update);
                }
            }
        });

        $filesCopied = $this->copyFiles($bundlePath, $disk);

        return [
            'site_id' => $siteId,
            'imported' => $imported,
            'missing_tables' => $missingTables,
            'dropped_columns' => array_map(fn (array $cols) => array_keys($cols), $droppedColumns),
            'files_copied' => $filesCopied,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{0: string, 1: string}>  $rewrites
     * @return array<string, mixed>
     */
    private function rewriteRow(array $row, array $rewrites): array
    {
        if ($rewrites === []) {
            return $row;
        }

        foreach ($row as $column => $value) {
            $row[$column] = $this->rewriteValue($value, $rewrites);
        }

        return $row;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $rewrites
     */
    private function rewriteValue(mixed $value, array $rewrites): mixed
    {
        if (is_string($value)) {
            foreach ($rewrites as [$from, $to]) {
                $value = str_replace($from, $to, $value);
            }

            return $value;
        }

        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $value[$key] = $this->rewriteValue($child, $rewrites);
            }

            return $value;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function prepareRow(string $table, array $row): array
    {
        $insertRow = $row;

        foreach (SiteBundleCatalog::ALWAYS_NULL_ON_IMPORT[$table] ?? [] as $column) {
            if (array_key_exists($column, $insertRow)) {
                $insertRow[$column] = null;
            }
        }

        $backfill = [];
        foreach (SiteBundleCatalog::FORWARD_REF_BACKFILL[$table] ?? [] as $column) {
            if (array_key_exists($column, $insertRow)) {
                $backfill[$column] = $insertRow[$column];
                $insertRow[$column] = null;
            }
        }

        foreach ($insertRow as $column => $value) {
            if (is_array($value)) {
                $insertRow[$column] = json_encode($value);
            }
        }

        return [$insertRow, $backfill];
    }

    private function copyFiles(string $bundlePath, string $disk): int
    {
        $filesDir = rtrim($bundlePath, '/').'/files';
        if (! File::isDirectory($filesDir)) {
            return 0;
        }

        $copied = 0;
        /** @var SplFileInfo $file */
        foreach (File::allFiles($filesDir) as $file) {
            $relative = ltrim(str_replace('\\', '/', $file->getRelativePathname()), '/');
            Storage::disk($disk)->put($relative, File::get($file->getPathname()));
            $copied++;
        }

        return $copied;
    }
}
