<?php

namespace App\Services\Site\Editor\Operations;

use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\Editor\SectionCatalog;
use App\Services\Site\PageLayoutRegistry;
use App\Services\Site\SectionSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AddSectionOperation extends BaseOperation
{
    public function __construct(
        private readonly StructureWrite $structure,
        private readonly SectionCatalog $catalog,
        private readonly SectionSchema $schema,
        private readonly PageLayoutRegistry $layouts,
    ) {}

    public function name(): string
    {
        return 'add_section';
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function sideEffects(): string
    {
        return 'Adds a section to the page draft.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['page_id', 'type', 'revision_base', 'structure_epoch'],
            'properties' => [
                'page_id' => ['type' => 'integer'],
                'type' => ['type' => 'string'],
                'position' => ['type' => 'integer'],
                'before_section_id' => [
                    'type' => 'string',
                    'description' => 'Insert before the section this id names. At least one of position, before_section_id and after_section_id is required; the anchors are mutually exclusive.',
                ],
                'after_section_id' => [
                    'type' => 'string',
                    'description' => 'Insert after the section this id names. At least one of position, before_section_id and after_section_id is required; the anchors are mutually exclusive.',
                ],
                'variant' => ['type' => ['string', 'null']],
                'fields' => ['type' => 'object'],
                'revision_base' => ['type' => 'integer'],
                'structure_epoch' => ['type' => 'integer'],
            ],
        ];
    }

    public function handle(EditorContext $ctx, array $input): OperationResult
    {
        $prepared = $this->structure->prepare($ctx, $input);
        if ($prepared instanceof OperationResult) {
            return $prepared;
        }

        ['page' => $page, 'base' => $base, 'epoch' => $epoch, 'state' => $state] = $prepared;
        $pageKind = $this->layouts->layoutKindForPage($page) ?? '';

        $type = $input['type'] ?? null;
        if (! is_string($type) || $type === '') {
            return OperationResult::fail('validation', 'type is required.', $state, [
                'fields' => ['type' => ['required']],
            ]);
        }

        if (! $this->structure->isCatalogued($type) || ! $this->catalog->allowedOn($type, (string) $page->page_type)) {
            return $this->structure->failUnknownType($state);
        }

        // No target section exists to address — the id form is an anchor naming a
        // neighbour, resolved to an insertion offset. The two anchors are mutually
        // exclusive, and an anchor cannot be combined with an explicit position:
        // both name the insertion point, and never silently preferring one address
        // over another is the rule the disagreement case applies too.
        $beforeId = self::nonEmptyStringOrNull($input['before_section_id'] ?? null);
        $afterId = self::nonEmptyStringOrNull($input['after_section_id'] ?? null);
        $hasAnchor = $beforeId !== null || $afterId !== null;

        if ($beforeId !== null && $afterId !== null) {
            return OperationResult::fail('validation', 'before_section_id and after_section_id are mutually exclusive; provide one.', $state, [
                'fields' => [
                    'before_section_id' => ['mutually exclusive with after_section_id'],
                    'after_section_id' => ['mutually exclusive with before_section_id'],
                ],
            ]);
        }

        $position = $this->structure->intOrNull($input['position'] ?? null);

        if ($position !== null && $hasAnchor) {
            return OperationResult::fail('validation', 'position cannot be combined with before_section_id/after_section_id; provide one.', $state, [
                'fields' => [
                    'position' => ['mutually exclusive with the anchor'],
                    $beforeId !== null ? 'before_section_id' : 'after_section_id' => ['mutually exclusive with position'],
                ],
            ]);
        }

        if ($position === null && ! $hasAnchor) {
            return OperationResult::fail('validation', 'position must be a non-negative integer.', $state, [
                'fields' => ['position' => ['required integer, or a before_section_id/after_section_id anchor']],
            ]);
        }

        if ($position !== null && $position < 0) {
            return OperationResult::fail('validation', 'position must be a non-negative integer.', $state, [
                'fields' => ['position' => ['required integer']],
            ]);
        }

        if ($hasAnchor) {
            $anchorId = $beforeId ?? $afterId;
            $anchorOffset = $this->structure->offsetForSectionId($this->structure->currentSections($page), $anchorId);

            if ($anchorOffset === null) {
                return OperationResult::fail('not_found', 'section_id does not name a section on this page.', $state, [
                    'fields' => [$beforeId !== null ? 'before_section_id' : 'after_section_id' => ['unknown section id']],
                ]);
            }

            $position = $beforeId !== null ? $anchorOffset : $anchorOffset + 1;
        }

        $fields = $input['fields'] ?? [];
        if ($fields !== [] && (! is_array($fields) || array_is_list($fields))) {
            return OperationResult::fail('validation', 'fields must be a map of path to value.', $state, [
                'fields' => ['fields' => ['must be an object']],
            ]);
        }

        return $this->structure->mutate(
            $ctx,
            $page,
            $base,
            $epoch,
            function (array $sections) use ($ctx, $type, $position, $fields, $input, $pageKind): array {
                $existing = 0;
                foreach ($sections as $section) {
                    if (($section['type'] ?? null) === $type) {
                        $existing++;
                    }
                }

                if ($this->catalog->isSingleton($type) && $existing >= 1) {
                    throw ValidationException::withMessages([
                        'type' => 'Section type is a singleton.',
                    ]);
                }

                $max = $this->catalog->maxPerPage($type);
                if ($max !== null && $existing >= $max) {
                    throw ValidationException::withMessages([
                        'type' => "At most {$max} of this section type allowed on the page.",
                    ]);
                }

                if ($position > count($sections)) {
                    throw ValidationException::withMessages([
                        'position' => 'Position is out of range.',
                    ]);
                }

                $payload = $this->catalog->defaultPayload($type, $ctx->site);
                // The server mints the section's identity here; an id in caller input is
                // never a value to store (invariant 1). fields are restricted to the
                // catalog's initial_fields, none of which is 'id', so nothing below can
                // overwrite this.
                $payload['id'] = (string) Str::ulid();
                $initial = $this->catalog->initialFields($type);

                $unknown = array_values(array_filter(array_keys($fields), fn ($path) => ! is_string($path) || ! in_array($path, $initial, true)));
                if ($unknown !== []) {
                    // Never silently discard agent content: only the catalog's initial_fields are settable at add time.
                    throw \Illuminate\Validation\ValidationException::withMessages(
                        array_fill_keys(array_map(fn ($k) => 'fields.'.$k, $unknown), 'Not settable on add_section; use edit_field after adding.'),
                    );
                }

                foreach ($fields as $path => $value) {

                    $errors = $this->schema->validateField($type, $path, $value);
                    if ($errors !== []) {
                        throw ValidationException::withMessages([$path => $errors]);
                    }

                    Arr::set($payload, $path, $value);
                }

                if (array_key_exists('variant', $input)) {
                    $variant = $input['variant'];
                    // Recipe vocabulary for this page kind (Task 12 seam) merged with the inline-family vocabulary.
                    $options = array_values(array_unique([...$this->layouts->variantOptionsFor($pageKind, $type), ...$this->schema->variantOptionsFor($type)]));
                    if ($variant !== null && ! in_array($variant, $options, true)) {
                        throw ValidationException::withMessages([
                            'variant' => 'must be a registered variant',
                        ]);
                    }
                    $payload['variant'] = $variant;
                }

                $referenceErrors = $this->catalog->validateReferences($ctx->site, $payload);
                if ($referenceErrors !== []) {
                    throw ValidationException::withMessages(['fields' => $referenceErrors]);
                }

                array_splice($sections, $position, 0, [$payload]);

                return array_values($sections);
            },
            $input,
        );
    }

    private static function nonEmptyStringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
