<?php

namespace App\Services\Site\Editor\Operations;

use App\Exceptions\Site\StaleRevisionException;
use App\Models\GeneratedPage;
use App\Models\Site\PageRevision;
use App\Models\SiteMedia;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\PageRenderer;
use App\Services\Site\PageService;
use App\Services\Site\SectionSchema;
use Illuminate\Validation\ValidationException;

final class EditFieldOperation extends BaseOperation
{
    public function __construct(
        private readonly PageService $pages,
        private readonly SectionSchema $schema,
        private readonly PageRenderer $renderer,
        private readonly EditorStateFactory $states,
    ) {}

    public function name(): string
    {
        return 'edit_field';
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function sideEffects(): string
    {
        return 'Writes a single draft field on a page section.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['page_id', 'stored_index', 'field_path', 'value', 'revision_base'],
            'properties' => [
                'page_id' => ['type' => 'integer'],
                'stored_index' => ['type' => 'integer', 'minimum' => 0],
                'field_path' => ['type' => 'string', 'maxLength' => 200],
                'value' => [
                    'anyOf' => [
                        ['type' => 'string'],
                        ['type' => 'object'],
                    ],
                    'description' => 'Field value. For rich fields, pass a TipTap document object.',
                ],
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
        $fieldPath = is_string($input['field_path'] ?? null) ? $input['field_path'] : null;

        if ($pageId === null || $storedIndex === null || $fieldPath === null || $fieldPath === '') {
            return OperationResult::fail('validation', 'page_id, stored_index and field_path are required.', $state, [
                'fields' => [
                    'page_id' => $pageId === null ? ['required integer'] : [],
                    'stored_index' => $storedIndex === null ? ['required integer'] : [],
                    'field_path' => ($fieldPath === null || $fieldPath === '') ? ['required string'] : [],
                ],
            ]);
        }

        if (! array_key_exists('value', $input)) {
            return OperationResult::fail('validation', 'value is required.', $state, [
                'fields' => ['value' => ['required']],
            ]);
        }

        $epoch = null;
        if (array_key_exists('structure_epoch', $input)) {
            $epoch = self::intOrNull($input['structure_epoch']);
            if ($epoch === null) {
                return OperationResult::fail('validation', 'structure_epoch must be an integer.', $state, [
                    'fields' => ['structure_epoch' => ['integer']],
                ]);
            }
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
        $value = $input['value'];

        try {
            if (in_array($fieldPath, $this->schema->repeatableLists($sectionType), true)) {
                if (! is_array($value)) {
                    return OperationResult::fail('validation', 'Repeatable list value must be an array.', $state, [
                        'fields' => ['value' => ['must be an array']],
                    ]);
                }

                $revision = $this->pages->editRepeatableEntries(
                    $page,
                    $storedIndex,
                    $fieldPath,
                    $value,
                    $ctx->actor->id,
                    $base,
                    $epoch,
                );
            } else {
                $errors = $this->schema->validateField($sectionType, $fieldPath, $value);
                if ($errors !== []) {
                    return OperationResult::fail('validation', 'Field failed schema validation.', $state, [
                        'fields' => [$fieldPath => $errors],
                    ]);
                }

                $fieldRules = $this->schema->resolveField($sectionType, $fieldPath);
                if (($fieldRules['type'] ?? null) === 'ranges') {
                    $errors = $this->schema->validateRangesAgainstTitle($value, (string) ($section['title'] ?? ''));
                    if ($errors !== []) {
                        return OperationResult::fail('validation', 'Field failed schema validation.', $state, [
                            'fields' => [$fieldPath => $errors],
                        ]);
                    }
                }

                if ($fieldPath === 'title' && array_key_exists('accent_ranges', $section)) {
                    $ctx->warnings->add(
                        'accent_ranges_dropped',
                        'Title changed; stored accent ranges were dropped.',
                        path: "sections.{$storedIndex}.accent_ranges",
                    );
                }

                if (($fieldRules['type'] ?? null) === 'image' && str_ends_with($fieldPath, '_id')) {
                    $mediaId = $value;
                    if (! is_int($mediaId)
                        || ! SiteMedia::query()->where('site_id', $ctx->site->id)->whereKey($mediaId)->exists()) {
                        return OperationResult::fail('validation', 'The selected media must belong to this site.', $state, [
                            'fields' => [$fieldPath => ['The selected media must belong to this site.']],
                        ]);
                    }
                }

                $revision = $this->pages->editField(
                    $page,
                    "sections.{$storedIndex}.{$fieldPath}",
                    $value,
                    $ctx->actor->id,
                    $base,
                    $epoch,
                );
            }
        } catch (StaleRevisionException) {
            $fresh = $page->fresh();

            return OperationResult::fail('stale_revision', 'Page revision base is stale.', $state, [
                'current_revision_id' => $fresh->draft_revision_id ?? $fresh->published_revision_id,
            ]);
        } catch (ValidationException $exception) {
            return OperationResult::fail('validation', 'Field failed schema validation.', $state, [
                'fields' => $exception->errors(),
            ]);
        }

        $html = $this->renderer->render(
            $ctx->site,
            $page->id,
            mode: 'admin-edit',
            // Signed nav is a UI-channel affordance. Each nav href here is an 8-hour
            // `editor-preview` temporarySignedRoute, and that signature is the only authorization the
            // preview route checks — so on an agent channel the result html would carry standing
            // credentials for the whole draft site out to a third-party model. Only the human's own iframe
            // navigates with them; the agent fronts use the html for a section/form swap, never for nav.
            signedNav: $ctx->channel === ActorChannel::Ui,
            parentOrigin: is_string($input['parent_origin'] ?? null) ? \App\Support\EditorParentOrigin::resolve($input['parent_origin']) : null, // allowlisted surface only
            formPanel: true,
            // PageRenderer defaults this to false. The coordinator prefers this html over the controller's
            // for its section swap, so without it an edit made after select_logo / restore_image_version
            // repainted the PUBLISHED logo or hero into the iframe until a full reload — the draft
            // selection is only read when useDraftAssets is true. EditorPreviewController already passes it.
            useDraftAssets: true,
        );

        return OperationResult::ok([
            'stored_index' => $storedIndex, // Front 2 targets the section swap with this
            'draft_revision_id' => $revision->id,
            'html' => $html,
        ], $this->states->for($ctx->site, $page->fresh()));
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
     * Accepts ints and canonical integer strings; rejects bools/floats/other.
     */
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
