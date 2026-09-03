<?php

namespace App\Services\Site\Editor\Operations;

use App\Exceptions\Site\StaleRevisionException;
use App\Models\GeneratedPage;
use App\Models\Site\PageRevision;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\PageRenderer;
use App\Services\Site\PageService;
use App\Services\Site\SectionSchema;

final class SetTitleEmphasisOperation extends BaseOperation
{
    public function __construct(
        private readonly PageService $pages,
        private readonly SectionSchema $schema,
        private readonly PageRenderer $renderer,
        private readonly EditorStateFactory $states,
    ) {}

    public function name(): string
    {
        return 'set_title_emphasis';
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function sideEffects(): string
    {
        return 'Writes title emphasis ranges on a page section, atomically with an optional title.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['page_id', 'stored_index', 'ranges', 'revision_base', 'structure_epoch'],
            'properties' => [
                'page_id' => ['type' => 'integer'],
                'stored_index' => ['type' => 'integer', 'minimum' => 0],
                'ranges' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['start', 'length'],
                        'properties' => [
                            'start' => ['type' => 'integer', 'minimum' => 0],
                            'length' => ['type' => 'integer', 'minimum' => 1],
                        ],
                    ],
                ],
                'title' => ['type' => 'string'],
                'revision_base' => ['type' => 'integer'],
                'structure_epoch' => ['type' => 'integer'],
                'parent_origin' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(EditorContext $ctx, array $input): OperationResult
    {
        $state = $this->states->for($ctx->site, null);

        $base = self::intOrNull($input['revision_base'] ?? null);
        if ($base === null) {
            return OperationResult::fail('validation', 'revision_base is required.', $state, [
                'fields' => ['revision_base' => ['required integer']],
            ]);
        }

        $pageId = self::intOrNull($input['page_id'] ?? null);
        $storedIndex = self::intOrNull($input['stored_index'] ?? null);
        if ($pageId === null || $storedIndex === null || $storedIndex < 0) {
            return OperationResult::fail('validation', 'page_id and stored_index are required.', $state, [
                'fields' => [
                    'page_id' => $pageId === null ? ['required integer'] : [],
                    'stored_index' => ($storedIndex === null || $storedIndex < 0) ? ['required integer'] : [],
                ],
            ]);
        }

        $epoch = self::intOrNull($input['structure_epoch'] ?? null);
        if ($epoch === null) {
            return OperationResult::fail('validation', 'structure_epoch is required.', $state, [
                'fields' => ['structure_epoch' => ['required integer']],
            ]);
        }

        $page = GeneratedPage::query()
            ->where('site_id', $ctx->site->id)
            ->whereNull('archived_at')
            ->find($pageId);

        if (! $page) {
            return OperationResult::fail('not_found', 'Page not found.', $state);
        }

        $state = $this->states->for($ctx->site, $page);
        $content = $this->currentEditableContent($page);
        $section = $content['sections'][$storedIndex] ?? null;

        if (! is_array($section) || ! is_string($section['type'] ?? null)) {
            return OperationResult::fail('validation', 'Section index is out of range.', $state, [
                'fields' => ['stored_index' => ['Section index is out of range.']],
            ]);
        }

        $sectionType = $section['type'];
        if ($this->schema->resolveField($sectionType, 'accent_ranges') === null) {
            return OperationResult::fail('validation', 'Section type does not support accent ranges.', $state, [
                'fields' => ['stored_index' => ['Section type does not support accent ranges.']],
            ]);
        }

        $titleProvided = array_key_exists('title', $input);
        $title = $titleProvided ? $input['title'] : ($section['title'] ?? '');
        if (! is_string($title)) {
            return OperationResult::fail('validation', 'title must be a string.', $state, [
                'fields' => ['title' => ['must be a string']],
            ]);
        }

        if ($titleProvided) {
            $titleErrors = $this->schema->validateField($sectionType, 'title', $title);
            if ($titleErrors !== []) {
                return OperationResult::fail('validation', 'Field failed schema validation.', $state, [
                    'fields' => ['title' => $titleErrors],
                ]);
            }
        }

        $ranges = $this->coerceRanges($input['ranges'] ?? null);
        if ($ranges === null) {
            return OperationResult::fail('validation', 'ranges must be a list of {start, length} objects.', $state, [
                'fields' => ['ranges' => ['must be a list of {start, length} ranges']],
            ]);
        }

        $writes = [];
        if ($titleProvided) {
            $writes["sections.{$storedIndex}.title"] = $title;
        }

        $titleLines = $section['title_lines'] ?? null;
        $hasTitleLines = is_array($titleLines) && $titleLines !== [];

        if ($hasTitleLines) {
            $ctx->warnings->add(
                'accent_ranges_dropped',
                'title_lines is present; accent ranges apply to title only and were dropped.',
                path: "sections.{$storedIndex}.accent_ranges",
            );
        } else {
            $rangeErrors = $this->schema->validateRangesAgainstTitle($ranges, $title);
            if ($rangeErrors !== []) {
                return OperationResult::fail('validation', 'Field failed schema validation.', $state, [
                    'fields' => ['ranges' => $rangeErrors],
                ]);
            }
            $writes["sections.{$storedIndex}.accent_ranges"] = $ranges;
        }

        if ($writes === []) {
            return OperationResult::ok([
                'stored_index' => $storedIndex,
                'draft_revision_id' => $page->draft_revision_id ?? $page->published_revision_id,
                'html' => $this->renderHtml($ctx, $page, $input),
            ], $state);
        }

        try {
            $revision = $this->pages->editFields(
                $page,
                $writes,
                $ctx->actor->id,
                $base,
                $epoch,
            );
        } catch (StaleRevisionException) {
            $fresh = $page->fresh();

            return OperationResult::fail('stale_revision', 'Page revision base is stale.', $state, [
                'current_revision_id' => $fresh->draft_revision_id ?? $fresh->published_revision_id,
            ]);
        }

        return OperationResult::ok([
            'stored_index' => $storedIndex,
            'draft_revision_id' => $revision->id,
            'html' => $this->renderHtml($ctx, $page, $input),
        ], $this->states->for($ctx->site, $page->fresh()));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function renderHtml(EditorContext $ctx, GeneratedPage $page, array $input): string
    {
        return $this->renderer->render(
            $ctx->site,
            $page->id,
            mode: 'admin-edit',
            signedNav: $ctx->channel === ActorChannel::Ui,
            parentOrigin: is_string($input['parent_origin'] ?? null) ? \App\Support\EditorParentOrigin::resolve($input['parent_origin']) : null,
            formPanel: true,
            useDraftAssets: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function currentEditableContent(GeneratedPage $page): array
    {
        $rid = $page->draft_revision_id ?? $page->published_revision_id;

        if ($rid) {
            return PageRevision::find($rid)?->content_data ?? $page->content_data ?? [];
        }

        return $page->content_data ?? [];
    }

    /**
     * @return list<array{start: int, length: int}>|null
     */
    private function coerceRanges(mixed $ranges): ?array
    {
        if (! is_array($ranges) || ! array_is_list($ranges)) {
            return null;
        }

        $coerced = [];
        foreach ($ranges as $range) {
            if (! is_array($range)) {
                return null;
            }
            $start = self::intOrNull($range['start'] ?? null);
            $length = self::intOrNull($range['length'] ?? null);
            if ($start === null || $length === null) {
                return null;
            }
            $coerced[] = ['start' => $start, 'length' => $length];
        }

        return $coerced;
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
}
