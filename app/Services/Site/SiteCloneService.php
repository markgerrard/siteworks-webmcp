<?php

namespace App\Services\Site;

use App\Services\Site\SiteClone\ContentJsonIdRemapper;
use App\Services\Site\SiteClone\PathRewriter;
use App\Services\Site\SiteClone\SceneJsonRemapper;
use App\Services\Site\SiteClone\SiteCloneCatalog;
use App\Services\Site\SiteClone\SiteCloneOptions;
use App\Services\Site\SiteClone\SiteCloneSpacesCopier;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SiteCloneService
{
    /**
     * Dest column listings keyed by "{connection}.{table}".
     *
     * @var array<string, list<string>>
     */
    private array $destColumnCache = [];

    public function __construct(private SiteCloneSpacesCopier $spacesCopier) {}

    public function run(Command $command, SiteCloneOptions $options, int $srcId): int
    {
        $source = DB::connection($options->sourceConnection);
        $dest = DB::connection();

        $srcSite = $source->table('sites')->where('id', $srcId)->first();
        if (! $srcSite) {
            $command->error("No site with id={$srcId} on {$options->sourceLabel}.");

            return Command::FAILURE;
        }

        $command->info("Source: id={$srcSite->id} '{$srcSite->business_name}' preview_domain='{$srcSite->preview_domain}'");

        if ($options->destClientId !== null && ! $dest->table('clients')->where('id', $options->destClientId)->exists()) {
            $command->error("No destination client with id={$options->destClientId}.");

            return Command::FAILURE;
        }

        $newId = $this->reserveNextSiteId($dest);
        $writtenKeys = [];
        if (! $options->skipSpaces) {
            [$failedKeys, $writtenKeys] = $this->mirrorSpaces($command, $srcId, $newId, $options->sourcePrefix, $options->destPrefix);
            if ($failedKeys !== []) {
                $command->error('Spaces mirror failed for '.count($failedKeys).' object(s):');
                foreach ($failedKeys as $key) {
                    $command->error("  {$key}");
                }
                $this->rollbackSpaces($command, $writtenKeys);

                return Command::FAILURE;
            }
        }

        $idMaps = [];
        try {
            $dest->transaction(function () use ($command, $source, $dest, $srcSite, $srcId, $newId, $options, &$idMaps): void {
                $this->copySite($command, $dest, $srcSite, $newId, $options);
                $idMaps['sites'] = [$srcId => $newId];

                foreach (SiteCloneCatalog::CHILD_TABLES as $table) {
                    $idMaps[$table] = $this->copyChild($command, $source, $dest, $table, $srcId, $newId, $idMaps);
                    $count = count($idMaps[$table]);
                    $command->line("  {$table}: copied {$count}");
                }

                $this->backFillGeneratedPageParents($source, $dest, $idMaps);
                $this->backFillRevisionFks($source, $dest, $idMaps);
                $this->backFillOverlayLogoConceptFk($dest, $srcSite, $newId, $idMaps);
                $this->rewritePaths($dest, $newId, $srcId, $options->sourcePrefix, $options->destPrefix);
                $this->remapJsonIds($dest, $newId, $idMaps);
                $this->remapEmbeddedItemAndPairIds($command, $dest, $newId, $idMaps);
                $this->remintSectionIds($command, $dest, $newId);
            });
        } catch (\Throwable $e) {
            $command->error('Database clone failed: '.$e->getMessage());
            $this->rollbackSpaces($command, $writtenKeys);

            return Command::FAILURE;
        }

        $newSite = $dest->table('sites')->where('id', $newId)->first();
        $command->info('');
        if ($options->legacyDevOutput) {
            $command->info("Cloned to dev as id={$newId} preview_domain='{$newSite->preview_domain}'");
            $command->info("Visit: https://{$newSite->preview_domain}.d.brand-a.example/");
        } else {
            $command->info("Cloned as id={$newId} preview_domain='{$newSite->preview_domain}'");
        }

        return Command::SUCCESS;
    }

    private function reserveNextSiteId(Connection $dest): int
    {
        return (int) $dest->select("SELECT nextval('projects_id_seq') AS id")[0]->id;
    }

    /**
     * Delete exactly the objects this run wrote — never a prefix: a prefix-level
     * cleanup guessed from config can land on another site's objects.
     *
     * @param  list<string>  $writtenKeys
     */
    private function rollbackSpaces(Command $command, array $writtenKeys): void
    {
        if ($writtenKeys === []) {
            return;
        }

        $undeleted = [];
        foreach ($writtenKeys as $key) {
            $error = $this->spacesCopier->deleteObject($key);
            if ($error !== null) {
                $undeleted[] = "{$key}: {$error}";
            }
        }

        $removed = count($writtenKeys) - count($undeleted);
        $command->warn("Spaces rollback: removed {$removed} mirrored object(s).");
        if ($undeleted !== []) {
            $command->error('Orphan cleanup: these mirrored objects could not be deleted — remove them by exact key:');
            foreach ($undeleted as $line) {
                $command->error("  {$line}");
            }
        }
    }

    /**
     * Test seam: inject extra source-only keys (or otherwise mutate a
     * fetched source row) before dest-schema intersection.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function augmentSourceRow(string $table, array $row): array
    {
        return $row;
    }

    private function copySite(Command $command, Connection $dest, object $srcSite, int $newId, SiteCloneOptions $options): void
    {
        $row = $this->augmentSourceRow('sites', (array) $srcSite);
        $row['id'] = $newId;
        $row['custom_domain'] = null;
        $row['custom_domain_status'] = null;
        $row['custom_domain_cf_id'] = null;
        $row['custom_domain_cf_zone'] = null;
        $row['created_by_user_id'] = null;
        $row['assigned_to_user_id'] = null;
        $row['client_id'] = $options->destClientId;
        $row['preview_domain'] = $options->preservePreviewDomain
            ? $this->resolvePreviewDomain($dest, $srcSite->preview_domain)
            : $options->previewDomain;

        $row = $this->intersectDestColumns($command, $dest, 'sites', $row);

        $dest->table('sites')->insert($row);
    }

    private function resolvePreviewDomain(Connection $dest, ?string $base): ?string
    {
        if (! $base) {
            return null;
        }
        if (! $dest->table('sites')->where('preview_domain', $base)->exists()) {
            return $base;
        }
        for ($i = 1; $i < 100; $i++) {
            $suffix = $i === 1 ? '-clone' : "-clone-{$i}";
            $candidate = mb_substr($base, 0, 250 - mb_strlen($suffix)).$suffix;
            if (! $dest->table('sites')->where('preview_domain', $candidate)->exists()) {
                return $candidate;
            }
        }
        throw new \RuntimeException("Could not find a free preview_domain based on '{$base}'.");
    }

    /**
     * @param  array<string, array<int|string, int>>  $idMaps
     * @return array<int|string, int>
     */
    private function copyChild(Command $command, Connection $source, Connection $dest, string $table, int $srcId, int $newId, array $idMaps): array
    {
        if ($table === 'generated_page_revisions') {
            $pageIds = array_keys($idMaps['generated_pages'] ?? []);
            if (! $pageIds) {
                return [];
            }
            $rows = $source->table($table)->whereIn('page_id', $pageIds)->get()->map(fn ($r) => (array) $r)->all();
        } else {
            $rows = $source->table($table)->where('site_id', $srcId)->get()->map(fn ($r) => (array) $r)->all();
        }
        if (! $rows) {
            return [];
        }

        $hasIdColumn = array_key_exists('id', $rows[0]);
        $idMap = [];
        $reportedDrift = false;

        foreach ($rows as $row) {
            $row = $this->augmentSourceRow($table, $row);
            $row = $this->transformGloballyUniqueColumns($table, $row, $newId);
            $oldId = $hasIdColumn ? $row['id'] : null;
            if (array_key_exists('site_id', $row)) {
                $row['site_id'] = $newId;
            }

            if ($hasIdColumn) {
                unset($row['id']);
            }

            $row = $this->remapFks($table, $row, $idMaps);
            $row = $this->intersectDestColumns($command, $dest, $table, $row, report: ! $reportedDrift);
            $reportedDrift = true;

            if ($hasIdColumn) {
                $newRowId = $dest->table($table)->insertGetId($row);
                $idMap[$oldId] = $newRowId;
            } else {
                if ($table === 'site_versions_current' && isset($row['version_id'])) {
                    $row['version_id'] = $idMaps['site_versions'][$row['version_id']] ?? $row['version_id'];
                }
                $dest->table($table)->insert($row);
            }
        }

        return $idMap;
    }

    /**
     * previews.slug is unique across all sites, so derive a clone-local value.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function transformGloballyUniqueColumns(string $table, array $row, int $newId): array
    {
        if ($table === 'previews' && isset($row['slug'])) {
            $row['slug'] .= '-c'.$newId;
        }
        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, array<int|string, int>>  $idMaps
     * @return array<string, mixed>
     */
    private function remapFks(string $table, array $row, array $idMaps): array
    {
        $rules = [
            'generated_pages' => [
                'parent_id' => null,
                'draft_revision_id' => null,
                'published_revision_id' => null,
            ],
            'generated_page_revisions' => [
                'page_id' => 'generated_pages',
                'created_by_user_id' => null,
                // Nulled, not remapped: remapFks() runs per row DURING the copy with no ordering, so
                // the self-referencing revision id map does not exist yet when a row is written — a
                // second pass after the table is copied would be needed to preserve lineage. Nulling
                // matches the created_by_user_id idiom above and is the honest state: a cloned site's
                // revision lineage genuinely does not exist, and undo_revision refuses parentless
                // revisions rather than guessing. Leaving it verbatim would point clones at the SOURCE
                // site's revisions — a cross-tenant parent on the next undo.
                'parent_revision_id' => null,
            ],
            'project_items' => [
                'page_id' => 'generated_pages',
                'detail_page_id' => 'generated_pages',
                'category_id' => 'project_categories',
                'image_id' => 'site_media',
            ],
            'site_media' => [
                'project_item_id' => null,
            ],
            'site_drafts' => [
                'updated_by_user_id' => null,
            ],
            'site_versions' => [
                'published_by_user_id' => null,
            ],
            'before_after_pairs' => [
                'page_id' => 'generated_pages',
                'before_image_id' => 'site_media',
                'after_image_id' => 'site_media',
            ],
            'imported_media' => [
                'assigned_page_id' => 'generated_pages',
            ],
            'site_personalisation_faces' => [
                'uploaded_by_user_id' => null,
            ],
        ];

        if (! isset($rules[$table])) {
            return $row;
        }

        foreach ($rules[$table] as $col => $targetTable) {
            if (! array_key_exists($col, $row)) {
                continue;
            }
            if ($targetTable === null) {
                $row[$col] = null;

                continue;
            }
            $oldRef = $row[$col];
            if ($oldRef === null) {
                continue;
            }
            $row[$col] = $idMaps[$targetTable][$oldRef] ?? null;
        }

        return $row;
    }

    /**
     * @param  array<string, array<int|string, int>>  $idMaps
     */
    private function backFillGeneratedPageParents(Connection $source, Connection $dest, array $idMaps): void
    {
        $pageMap = $idMaps['generated_pages'] ?? [];
        if ($pageMap === []) {
            return;
        }

        $sourcePages = $source
            ->table('generated_pages')
            ->whereIn('id', array_keys($pageMap))
            ->whereNotNull('parent_id')
            ->get(['id', 'parent_id']);

        foreach ($sourcePages as $sourcePage) {
            $newParentId = $pageMap[$sourcePage->parent_id] ?? null;
            if ($newParentId !== null) {
                $dest->table('generated_pages')
                    ->where('id', $pageMap[$sourcePage->id])
                    ->update(['parent_id' => $newParentId]);
            }
        }
    }

    /**
     * @param  array<string, array<int|string, int>>  $idMaps
     */
    private function backFillRevisionFks(Connection $source, Connection $dest, array $idMaps): void
    {
        $pageMap = $idMaps['generated_pages'] ?? [];
        $revMap = $idMaps['generated_page_revisions'] ?? [];
        if (! $pageMap || ! $revMap) {
            return;
        }

        $origPages = $source
            ->table('generated_pages')
            ->whereIn('id', array_keys($pageMap))
            ->get(['id', 'draft_revision_id', 'published_revision_id']);

        foreach ($origPages as $page) {
            $newPageId = $pageMap[$page->id];
            $update = [];
            if ($page->draft_revision_id && isset($revMap[$page->draft_revision_id])) {
                $update['draft_revision_id'] = $revMap[$page->draft_revision_id];
            }
            if ($page->published_revision_id && isset($revMap[$page->published_revision_id])) {
                $update['published_revision_id'] = $revMap[$page->published_revision_id];
            }
            if ($update) {
                $dest->table('generated_pages')->where('id', $newPageId)->update($update);
            }
        }
    }

    /**
     * The sites row is inserted before logo_concepts exist, so its
     * overlay_logo_concept_id still points at the source concept.
     *
     * @param  array<string, array<int|string, int>>  $idMaps
     */
    private function backFillOverlayLogoConceptFk(Connection $dest, object $srcSite, int $newId, array $idMaps): void
    {
        $srcConceptId = $srcSite->overlay_logo_concept_id ?? null;
        if (! $srcConceptId) {
            return;
        }

        $dest->table('sites')->where('id', $newId)->update([
            'overlay_logo_concept_id' => $idMaps['logo_concepts'][$srcConceptId] ?? null,
        ]);
    }

    /**
     * Drop keys that are not dest columns. Reports source-only names once
     * per table so schema drift is visible.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function intersectDestColumns(Command $command, Connection $dest, string $table, array $row, bool $report = true): array
    {
        $destColumns = $this->destColumns($dest, $table);
        $dropped = array_values(array_diff(array_keys($row), $destColumns));
        if ($dropped === []) {
            return $row;
        }

        if ($report) {
            sort($dropped);
            $command->warn("  {$table}: dropped source-only columns: ".implode(', ', $dropped));
        }

        return Arr::only($row, $destColumns);
    }

    /**
     * @return list<string>
     */
    private function destColumns(Connection $dest, string $table): array
    {
        $key = $dest->getName().'.'.$table;

        return $this->destColumnCache[$key] ??= $dest->getSchemaBuilder()->getColumnListing($table);
    }

    private function rewritePaths(Connection $dest, int $newId, int $srcId, string $sourcePrefix, string $destPrefix): void
    {
        foreach (SiteCloneCatalog::PATH_REWRITES as $table => $columns) {
            $destColumns = $this->destColumns($dest, $table);
            $columns = array_values(array_filter(
                $columns,
                fn (string $column): bool => in_array($column, $destColumns, true),
            ));
            if ($columns === []) {
                continue;
            }

            $whereSiteId = $table === 'sites' ? 'id' : 'site_id';
            $select = array_values(array_unique(array_merge(['id'], $columns)));

            $rows = $dest->table($table)->where($whereSiteId, $newId)->get($select);
            foreach ($rows as $row) {
                $update = [];
                foreach ($columns as $col) {
                    $raw = $row->{$col} ?? null;
                    if (! is_string($raw) || $raw === '') {
                        continue;
                    }
                    $rewritten = PathRewriter::rewrite($raw, $srcId, $newId, $sourcePrefix, $destPrefix);
                    if ($rewritten !== $raw) {
                        $update[$col] = $rewritten;
                    }
                }
                if ($update) {
                    $dest->table($table)->where('id', $row->id)->update($update);
                }
            }
        }

        $previews = $dest->table('previews')->where('site_id', $newId)->get(['id', 'snapshot']);
        $this->rewriteSerializedJsonRows($dest, 'previews', 'snapshot', $previews, $srcId, $newId, $sourcePrefix, $destPrefix);

        $pages = $dest->table('generated_pages')->where('site_id', $newId)->get(['id', 'content_data']);
        $this->rewriteSerializedJsonRows($dest, 'generated_pages', 'content_data', $pages, $srcId, $newId, $sourcePrefix, $destPrefix);

        $pageIds = $pages->pluck('id')->all();
        if ($pageIds !== []) {
            $revisions = $dest->table('generated_page_revisions')->whereIn('page_id', $pageIds)->get(['id', 'content_data']);
            $this->rewriteSerializedJsonRows($dest, 'generated_page_revisions', 'content_data', $revisions, $srcId, $newId, $sourcePrefix, $destPrefix);
        }
    }

    /**
     * String-level path rewrite over serialized JSON columns. Hand-set URLs
     * inside content JSON (e.g. a reviews background_image) carry the source
     * prefix/site id but are invisible to the id-based remap passes.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     */
    private function rewriteSerializedJsonRows(Connection $dest, string $table, string $column, $rows, int $srcId, int $newId, string $sourcePrefix, string $destPrefix): void
    {
        foreach ($rows as $row) {
            if ($row->{$column} === null) {
                continue;
            }
            $raw = is_string($row->{$column}) ? $row->{$column} : json_encode($row->{$column});
            if (! is_string($raw)) {
                continue;
            }
            $rewritten = PathRewriter::rewrite($raw, $srcId, $newId, $sourcePrefix, $destPrefix);
            if ($rewritten !== $raw) {
                $dest->table($table)->where('id', $row->id)->update([$column => $rewritten]);
            }
        }
    }

    /**
     * @param  array<string, array<int|string, int>>  $idMaps
     */
    private function remapJsonIds(Connection $dest, int $newId, array $idMaps): void
    {
        $keyMap = [
            'page_id' => 'generated_pages',
            'homepage_page_id' => 'generated_pages',
            'revision_id' => 'generated_page_revisions',
        ];

        $tables = [
            'site_versions' => ['composition', 'page_revisions'],
            'site_drafts' => ['composition'],
        ];

        foreach ($tables as $table => $columns) {
            $rows = $dest->table($table)->where('site_id', $newId)->get();
            foreach ($rows as $row) {
                $update = [];
                foreach ($columns as $col) {
                    $decoded = $this->decodeJson($row->{$col} ?? null);
                    if (! is_array($decoded)) {
                        continue;
                    }
                    $remapped = $this->walkAndRemap($decoded, $keyMap, $idMaps);
                    $update[$col] = json_encode($remapped, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
                if ($update) {
                    $dest->table($table)->where('id', $row->id)->update($update);
                }
            }
        }

        $site = $dest->table('sites')->where('id', $newId)->first();
        if ($site) {
            $siteUpdate = [];
            foreach (['home_hero_scene', 'home_hero_scene_draft'] as $col) {
                $decoded = $this->decodeJson($site->{$col} ?? null);
                if (! is_array($decoded)) {
                    continue;
                }
                $remapped = SceneJsonRemapper::remap($decoded, $idMaps);
                $siteUpdate[$col] = json_encode($remapped, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            if ($siteUpdate) {
                $dest->table('sites')->where('id', $newId)->update($siteUpdate);
            }
        }

        foreach ($idMaps['hero_video_versions'] ?? [] as $newVideoId) {
            $row = $dest->table('hero_video_versions')->where('id', $newVideoId)->first();
            if (! $row) {
                continue;
            }
            $decoded = $this->decodeJson($row->metadata ?? null);
            if (! is_array($decoded)) {
                continue;
            }
            $remapped = SceneJsonRemapper::remapComponentIds($decoded, $idMaps);
            $dest->table('hero_video_versions')->where('id', $newVideoId)->update([
                'metadata' => json_encode($remapped, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    /**
     * Post-pass: revisions (and other copied JSON) are inserted before
     * project_items / before_after_pairs, so section item_ids / pair_ids
     * still hold source ids until those maps exist.
     *
     * @param  array<string, array<int|string, int>>  $idMaps
     */
    private function remapEmbeddedItemAndPairIds(Command $command, Connection $dest, int $newId, array $idMaps): void
    {
        $remapper = new ContentJsonIdRemapper;

        $pageIds = array_values($idMaps['generated_pages'] ?? []);
        if ($pageIds !== []) {
            $revisions = $dest->table('generated_page_revisions')
                ->whereIn('page_id', $pageIds)
                ->get(['id', 'content_data']);
            foreach ($revisions as $revision) {
                $this->rewriteJsonColumn($dest, 'generated_page_revisions', $revision->id, 'content_data', $revision->content_data, $idMaps, $remapper);
            }

            $pages = $dest->table('generated_pages')
                ->where('site_id', $newId)
                ->get(['id', 'content_data']);
            foreach ($pages as $page) {
                $this->rewriteJsonColumn($dest, 'generated_pages', $page->id, 'content_data', $page->content_data, $idMaps, $remapper);
            }
        }

        $previews = $dest->table('previews')
            ->where('site_id', $newId)
            ->get(['id', 'snapshot']);
        foreach ($previews as $preview) {
            $this->rewriteJsonColumn($dest, 'previews', $preview->id, 'snapshot', $preview->snapshot, $idMaps, $remapper);
        }

        if ($remapper->itemIdsRemapped > 0 || $remapper->pairIdsRemapped > 0 || $remapper->unmappedDropped > 0) {
            $command->line(sprintf(
                '  content JSON: remapped %d item_ids, %d pair_ids (dropped %d unmapped)',
                $remapper->itemIdsRemapped,
                $remapper->pairIdsRemapped,
                $remapper->unmappedDropped,
            ));
        }
    }

    /**
     * Post-pass: re-mint section ids on cloned content_data so no
     * section id is shared between source and clone.
     */
    private function remintSectionIds(Command $command, Connection $dest, int $newId): void
    {
        $identifiers = app(\App\Services\Site\Editor\SectionIdentifiers::class);
        $reminted = 0;

        $revisions = $dest->table('generated_page_revisions')
            ->whereIn('page_id', function ($q) use ($dest, $newId) {
                $q->select('id')->from('generated_pages')->where('site_id', $newId);
            })
            ->get(['id', 'content_data']);
        foreach ($revisions as $revision) {
            $decoded = $this->decodeJson($revision->content_data);
            if (! is_array($decoded)) {
                continue;
            }
            $result = $identifiers->remint($decoded);
            if ($result !== $decoded) {
                $dest->table('generated_page_revisions')->where('id', $revision->id)->update([
                    'content_data' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);
                $reminted++;
            }
        }

        $pages = $dest->table('generated_pages')
            ->where('site_id', $newId)
            ->get(['id', 'content_data']);
        foreach ($pages as $page) {
            $decoded = $this->decodeJson($page->content_data);
            if (! is_array($decoded)) {
                continue;
            }
            $result = $identifiers->remint($decoded);
            if ($result !== $decoded) {
                $dest->table('generated_pages')->where('id', $page->id)->update([
                    'content_data' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);
                $reminted++;
            }
        }

        if ($reminted > 0) {
            $command->line("  section ids: reminted {$reminted} rows (content_data)");
        }
    }

    /**
     * @param  array<string, array<int|string, int>>  $idMaps
     */
    private function rewriteJsonColumn(
        Connection $dest,
        string $table,
        int $rowId,
        string $column,
        mixed $raw,
        array $idMaps,
        ContentJsonIdRemapper $remapper,
    ): void {
        $decoded = $this->decodeJson($raw);
        if (! is_array($decoded)) {
            return;
        }

        [$remapped, $changed] = $remapper->remap($decoded, $idMaps);
        if (! $changed) {
            return;
        }

        $dest->table($table)->where('id', $rowId)->update([
            $column => json_encode($remapped, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, string>  $keyMap
     * @param  array<string, array<int|string, int>>  $idMaps
     * @return array<string, mixed>
     */
    private function walkAndRemap(array $node, array $keyMap, array $idMaps): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->walkAndRemap($value, $keyMap, $idMaps);

                continue;
            }
            if (isset($keyMap[$key]) && (is_int($value) || (is_string($value) && ctype_digit($value)))) {
                $table = $keyMap[$key];
                $node[$key] = $idMaps[$table][(int) $value] ?? $value;
            }
        }

        return $node;
    }

    private function decodeJson(mixed $raw): mixed
    {
        if ($raw === null || is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return json_decode($raw, true);
    }

    /**
     * @return array{0: list<string>, 1: list<string>} [failed source keys, written dest keys]
     */
    private function mirrorSpaces(Command $command, int $srcId, int $newId, string $sourcePrefix, string $destPrefix): array
    {
        $pairs = [
            [$sourcePrefix, $destPrefix],
            ['sites', 'sites'],
        ];
        if ($sourcePrefix === 'sites') {
            $pairs = [['sites', $destPrefix === '' ? 'sites' : $destPrefix]];
        }
        // Portraits live under their own root (PortraitIngestService).
        $pairs[] = ['site-media', 'site-media'];

        $failedKeys = [];
        $writtenKeys = [];

        foreach ($pairs as [$srcRoot, $dstRoot]) {
            $srcKeyPrefix = "{$srcRoot}/{$srcId}/";
            $dstKeyPrefix = "{$dstRoot}/{$newId}/";
            $keys = $this->spacesCopier->listKeys($srcKeyPrefix);
            $command->info('Mirroring '.$srcKeyPrefix.' → '.$dstKeyPrefix.' ('.count($keys).' objects)');

            $bar = $command->getOutput()->createProgressBar(count($keys));
            $bar->start();
            foreach ($keys as $oldKey) {
                $newKey = $dstKeyPrefix.substr($oldKey, strlen($srcKeyPrefix));
                $error = $this->spacesCopier->copyObject($oldKey, $newKey);
                if ($error !== null) {
                    $failedKeys[] = $oldKey;
                    $command->newLine();
                    $command->warn("  copy failed {$oldKey}: {$error}");
                } else {
                    $writtenKeys[] = $newKey;
                }
                $bar->advance();
            }
            $bar->finish();
            $command->newLine();
        }

        return [$failedKeys, $writtenKeys];
    }
}
