<?php

namespace App\Services\Site\Editor\Operations;

use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\LogoConcept;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\SiteMedia;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\DraftAssetSelections;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use BackedEnum;
use DateTimeInterface;

final class ListImageVersionsOperation extends BaseOperation
{
    public function __construct(
        private readonly DraftAssetSelections $selections,
        private readonly EditorStateFactory $states,
    ) {}

    public function name(): string
    {
        return 'list_image_versions';
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

    public function sideEffects(): string
    {
        return 'Lists hero, logo, or media versions for a site.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['scope'],
            'properties' => [
                'scope' => ['type' => 'string', 'enum' => ['hero', 'logo', 'media']],
                'stored_index' => ['type' => 'integer', 'minimum' => 0],
                'page_type' => ['type' => 'string'],
                'slot' => ['type' => 'string'],
                'page_id' => ['type' => 'integer'],
                'field_path' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(EditorContext $ctx, array $input): OperationResult
    {
        $state = $this->states->for($ctx->site, null);
        $scope = $input['scope'] ?? null;

        if (! in_array($scope, ['hero', 'logo', 'media'], true)) {
            return OperationResult::fail('validation', 'scope must be hero, logo, or media.', $state, [
                'fields' => ['scope' => ['must be hero, logo, or media']],
            ]);
        }

        $versions = match ($scope) {
            'hero' => $this->heroVersions($ctx->site, $input),
            'logo' => $this->logoVersions($ctx->site),
            'media' => $this->mediaVersions($ctx->site, $input),
        };

        if ($versions instanceof OperationResult) {
            return $versions;
        }

        return OperationResult::ok(['versions' => $versions], $state);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array{id: int, url: string, created_at: string, source: string, active: bool, drafted: bool}>|OperationResult
     */
    private function heroVersions(Site $site, array $input): array|OperationResult
    {
        $pageType = is_string($input['page_type'] ?? null) ? $input['page_type'] : null;
        $slot     = is_string($input['slot'] ?? null) ? $input['slot'] : null;

        // Resolve page_id → page_type: site-scoped, non-archived.
        $pageId = self::intOrNull($input['page_id'] ?? null);

        if ($pageId !== null) {
            $page = GeneratedPage::query()
                ->where('site_id', $site->id)
                ->whereNull('archived_at')
                ->find($pageId);

            if (! $page) {
                return OperationResult::fail('not_found', 'Page not found.', $this->states->for($site, null));
            }

            $resolvedType = $page->page_type;

            if ($pageType !== null && $pageType !== $resolvedType) {
                return OperationResult::fail('validation', 'page_type does not match page_id.', $this->states->for($site, null), [
                    'fields' => [
                        'page_type' => ['must match the page identified by page_id'],
                        'page_id' => ['does not match the supplied page_type'],
                    ],
                ]);
            }

            $pageType = $resolvedType;
        }

        // Default slot for the hero scope.
        if ($slot === null || $slot === '') {
            $slot = 'hero';
        }

        if ($pageType === null || $pageType === '') {
            return OperationResult::fail('validation', 'page_type or page_id is required for hero scope.', $this->states->for($site, null), [
                'fields' => [
                    'page_type' => ['required when page_id is absent'],
                    'page_id' => ['provide page_id as an alternative to page_type'],
                ],
            ]);
        }

        $draftedId = $this->selections->heroFor($site, $pageType, $slot)?->id;

        return HeroVersion::query()
            ->where('site_id', $site->id)
            ->where('page_type', $pageType)
            ->where('slot', $slot)
            ->orderByDesc('id')
            ->get()
            ->map(fn (HeroVersion $version): array => $this->payload(
                $version->id,
                (string) $version->url,
                $version->created_at,
                $version->source,
                (bool) $version->is_active,
                $draftedId === $version->id,
            ))
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, url: string, created_at: string, source: string, active: bool, drafted: bool}>
     */
    private function logoVersions(Site $site): array
    {
        $draftedId = $this->selections->logoFor($site)?->id;

        return LogoConcept::query()
            ->where('site_id', $site->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (LogoConcept $concept): array => $this->payload(
                $concept->id,
                $concept->url(),
                $concept->created_at,
                $concept->source,
                (bool) $concept->is_selected,
                $draftedId === $concept->id,
            ))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array{id: int, url: string, created_at: string, source: string, active: bool, drafted: bool}>
     */
    private function mediaVersions(Site $site, array $input): array|OperationResult
    {
        $draftValue = null;
        $publishedValue = null;
        $pageId = self::intOrNull($input['page_id'] ?? null);
        $fieldPath = is_string($input['field_path'] ?? null) ? $input['field_path'] : null;

        if ($pageId !== null && $fieldPath !== null && $fieldPath !== '') {
            $page = GeneratedPage::query()
                ->where('site_id', $site->id)
                ->whereNull('archived_at')
                ->find($pageId);

            if (! $page) {
                // Archived or foreign page: never fall through to "here is the whole site's media".
                return OperationResult::fail('not_found', 'Page not found.', $this->states->for($site, null));
            }

            {
                $storedIndex = self::optionalIndex($input['stored_index'] ?? null);
                $draftValue = $this->fieldValue($this->currentEditableContent($page), $fieldPath, $storedIndex);
                $publishedValue = $page->published_revision_id
                    ? $this->fieldValue(PageRevision::query()->find($page->published_revision_id)?->content_data ?? [], $fieldPath, $storedIndex)
                    : $draftValue;
            }
        }

        return SiteMedia::query()
            ->where('site_id', $site->id)
            ->orderByDesc('id')
            ->get()
            ->map(function (SiteMedia $media) use ($draftValue, $publishedValue): array {
                $active = self::mediaMatches($media, $draftValue);
                $drafted = $active && $draftValue !== $publishedValue;

                return $this->payload(
                    $media->id,
                    (string) $media->url,
                    $media->created_at,
                    $media->source,
                    $active,
                    $drafted,
                );
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function fieldValue(array $content, string $fieldPath, ?int $storedIndex = null): mixed
    {
        // With a stored_index the "current" badge is exact; without one (legacy callers) fall back to the
        // first section carrying the field — Tasks 18/20 always pass stored_index.
        $sections = array_values($content['sections'] ?? []);
        if ($storedIndex !== null) {
            $section = $sections[$storedIndex] ?? null;

            return is_array($section) ? data_get($section, $fieldPath) : null;
        }

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $value = data_get($section, $fieldPath);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function currentEditableContent(GeneratedPage $page): array
    {
        $rid = $page->draft_revision_id ?? $page->published_revision_id;

        if ($rid) {
            return PageRevision::query()->find($rid)?->content_data ?? $page->content_data ?? [];
        }

        return $page->content_data ?? [];
    }

    /**
     * @return array{id: int, url: string, created_at: string, source: string, active: bool, drafted: bool}
     */
    private function payload(int $id, string $url, mixed $createdAt, mixed $source, bool $active, bool $drafted): array
    {
        return [
            'id' => $id,
            'url' => $url,
            'created_at' => $createdAt instanceof DateTimeInterface ? $createdAt->format(DateTimeInterface::ATOM) : '',
            'source' => $source instanceof BackedEnum ? (string) $source->value : (is_string($source) ? $source : ''),
            'active' => $active,
            'drafted' => $drafted,
        ];
    }

    private static function mediaMatches(SiteMedia $media, mixed $value): bool
    {
        if (is_int($value) || (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/', $value) === 1)) {
            return (int) $value === (int) $media->id;
        }

        return is_string($value) && $value === $media->url;
    }

    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(0|-?[1-9][0-9]*)$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private static function optionalIndex(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
