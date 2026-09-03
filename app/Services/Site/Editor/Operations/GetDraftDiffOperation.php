<?php

namespace App\Services\Site\Editor\Operations;

use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\HeroVideoVersion;
use App\Models\LogoConcept;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\DraftAssetSelections;
use App\Services\Site\Editor\DraftDiffer;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;

final class GetDraftDiffOperation extends BaseOperation
{
    public function __construct(
        private readonly DraftDiffer $differ,
        private readonly DraftAssetSelections $draftAssets,
        private readonly EditorStateFactory $states,
    ) {}

    public function name(): string
    {
        return 'get_draft_diff';
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function wrapInAdminChange(): bool
    {
        return false;
    }

    public function address(): string
    {
        return 'site';
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function sideEffects(): string
    {
        return 'Reads unpublished page, composition, and asset-selection diffs. Values are truncated at 512 bytes; media bytes are never returned. Never publishes.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'page_id' => ['type' => 'integer'],
                'include_values' => ['type' => 'boolean'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(EditorContext $ctx, array $input): OperationResult
    {
        $state = $this->states->for($ctx->site, null);
        $pageId = null;

        if (array_key_exists('page_id', $input) && $input['page_id'] !== null) {
            $pageId = $this->positiveInt($input['page_id']);
            if ($pageId === null) {
                return OperationResult::fail('not_found', 'Page not found.', $state);
            }

            $page = GeneratedPage::query()
                ->where('site_id', $ctx->site->id)
                ->whereNull('archived_at')
                ->find($pageId);

            if ($page === null) {
                return OperationResult::fail('not_found', 'Page not found.', $state);
            }

            $state = $this->states->for($ctx->site, $page);
        }

        $includeValues = ($input['include_values'] ?? true) !== false;

        $pages = $this->sorted([
            ...$this->pageContentEntries($ctx->site, $pageId),
            ...$this->projectItemEntries($ctx->site, $pageId),
        ]);
        $composition = $this->compositionEntries($ctx->site);
        $assets = $this->assetEntries($ctx->site);

        if (! $includeValues) {
            $pages = $this->stripValues($pages);
            $composition = $this->stripValues($composition);
            $assets = $this->stripValues($assets);
        }

        $pageIds = array_values(array_unique(array_filter(
            array_column($pages, 'page_id'),
            fn (mixed $id): bool => $id !== null,
        )));

        return OperationResult::ok([
            'pages' => $pages,
            'composition' => $composition,
            'assets' => $assets,
            'summary' => [
                'pages' => count($pageIds),
                'fields' => count($pages) + count($composition),
                'assets' => count($assets),
            ],
        ], $state);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pageContentEntries(Site $site, ?int $pageId): array
    {
        $pages = GeneratedPage::query()
            ->where('site_id', $site->id)
            ->whereNull('archived_at')
            ->whereNotNull('draft_revision_id')
            ->when($pageId !== null, fn ($query) => $query->where('id', $pageId))
            ->orderBy('id')
            ->with(['draftRevision', 'publishedRevision'])
            ->get();

        $entries = [];
        foreach ($pages as $page) {
            $before = is_array($page->publishedRevision?->content_data) ? $page->publishedRevision->content_data : [];
            $after = is_array($page->draftRevision?->content_data) ? $page->draftRevision->content_data : [];
            $entries = [...$entries, ...$this->differ->diffContent($before, $after, $page->id)];
        }

        return $entries;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function projectItemEntries(Site $site, ?int $pageId): array
    {
        $items = ProjectItem::query()
            ->where('site_id', $site->id)
            ->whereNotNull('published_snapshot')
            ->when($pageId !== null, fn ($query) => $query->where('page_id', $pageId))
            ->orderBy('id')
            ->with('page')
            ->get();

        $entries = [];
        foreach ($items as $item) {
            if ($item->page !== null && $item->page->archived_at !== null) {
                continue;
            }
            if (! $item->hasUnpublishedDrift()) {
                continue;
            }

            $before = is_array($item->published_snapshot) ? $item->published_snapshot : [];
            $after = $item->buildPublishSnapshot();
            $ownerId = $item->page_id !== null ? (int) $item->page_id : null;
            $entries = [...$entries, ...$this->differ->diffContent(
                ['project_item' => [(string) $item->id => $before]],
                ['project_item' => [(string) $item->id => $after]],
                $ownerId,
            )];
        }

        return $entries;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function compositionEntries(Site $site): array
    {
        $site->loadMissing(['currentVersion.version', 'siteDraft']);
        $published = is_array($site->currentVersion?->version?->composition)
            ? $site->currentVersion->version->composition
            : [];
        $draft = is_array($site->siteDraft?->composition) ? $site->siteDraft->composition : [];

        return $this->differ->diffComposition($published, $draft);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function assetEntries(Site $site): array
    {
        $published = $this->publishedSelectionRows($site);
        $drafts = $this->draftAssets->all($site)
            ->map(fn ($row): array => $this->selectionRow($row->toArray()))
            ->all();

        $after = $this->keyed($published);
        foreach ($this->keyed($drafts) as $key => $payload) {
            $after[$key] = $payload;
        }

        return $this->differ->diffSelections($published, array_values($after));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function publishedSelectionRows(Site $site): array
    {
        $rows = [];

        $heroes = HeroVersion::query()
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'page_type', 'slot']);
        foreach ($heroes as $hero) {
            $rows[] = [
                'family' => 'hero',
                'page_type' => (string) $hero->page_type,
                'slot' => (string) $hero->slot,
                'version_id' => $hero->id,
            ];
        }

        $logo = LogoConcept::query()
            ->where('site_id', $site->id)
            ->where('is_selected', true)
            ->orderBy('id')
            ->first();
        if ($logo !== null) {
            $rows[] = [
                'family' => 'logo',
                'page_type' => '',
                'slot' => '',
                'version_id' => $logo->id,
            ];
        }

        $video = HeroVideoVersion::query()
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
        if ($video !== null) {
            $enabled = (bool) $site->home_hero_video_enabled;
            $rows[] = [
                'family' => 'hero_video',
                'page_type' => 'home',
                'slot' => 'hero',
                'version_id' => $enabled ? $video->id : null,
                'mode' => $enabled ? 'on' : 'off',
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function selectionRow(array $row): array
    {
        $clean = [
            'family' => (string) ($row['family'] ?? ''),
            'page_type' => (string) ($row['page_type'] ?? ''),
            'slot' => (string) ($row['slot'] ?? ''),
            'version_id' => $row['version_id'] ?? null,
        ];
        if (is_string($row['mode'] ?? null) && $row['mode'] !== '') {
            $clean['mode'] = $row['mode'];
        }
        if (array_key_exists('placement', $row) && $row['placement'] !== null && $row['placement'] !== []) {
            $clean['placement'] = $row['placement'];
        }

        return $clean;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function keyed(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $parts = [(string) $row['family']];
            $pageType = (string) ($row['page_type'] ?? '');
            $slot = (string) ($row['slot'] ?? '');
            if ($pageType !== '') {
                $parts[] = $pageType;
            }
            if ($slot !== '') {
                $parts[] = $slot;
            }
            $map[implode('.', $parts)] = $row;
        }

        return $map;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function stripValues(array $entries): array
    {
        return array_map(function (array $entry): array {
            unset($entry['before'], $entry['after']);

            return $entry;
        }, $entries);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function sorted(array $entries): array
    {
        usort($entries, function (array $left, array $right): int {
            $page = ($left['page_id'] ?? PHP_INT_MAX) <=> ($right['page_id'] ?? PHP_INT_MAX);
            if ($page !== 0) {
                return $page;
            }

            $index = ($left['stored_index'] ?? PHP_INT_MAX) <=> ($right['stored_index'] ?? PHP_INT_MAX);
            if ($index !== 0) {
                return $index;
            }

            return $left['path'] <=> $right['path'];
        });

        return array_values($entries);
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
