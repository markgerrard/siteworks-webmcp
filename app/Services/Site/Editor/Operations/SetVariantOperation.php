<?php

namespace App\Services\Site\Editor\Operations;

use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\Editor\SectionCatalog;
use App\Services\Site\PageLayoutRegistry;
use App\Services\Site\SectionSchema;
use Illuminate\Validation\ValidationException;

final class SetVariantOperation extends BaseOperation
{
    public function __construct(
        private readonly StructureWrite $structure,
        private readonly SectionCatalog $catalog,
        private readonly SectionSchema $schema,
        private readonly PageLayoutRegistry $layouts,
    ) {}

    public function name(): string
    {
        return 'set_variant';
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function sideEffects(): string
    {
        return 'Sets a section variant on the page draft.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['page_id', 'variant', 'revision_base', 'structure_epoch'],
            'properties' => [
                'page_id' => ['type' => 'integer'],
                'stored_index' => ['type' => 'integer'],
                'section_id' => [
                    'type' => 'string',
                    'description' => 'Stable section identifier. At least one of stored_index and section_id is required; when both are given they must name the same section.',
                ],
                'variant' => ['type' => ['string', 'null']],
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

        // The RESOLVED offset feeds both the read and the write below — never the raw positional input.
        $index = $this->structure->resolveSectionAddress(
            $this->structure->currentSections($page),
            $input,
            'stored_index',
            $state,
        );
        if ($index instanceof OperationResult) {
            return $index;
        }

        if (! array_key_exists('variant', $input) || ($input['variant'] !== null && ! is_string($input['variant']))) {
            return OperationResult::fail('validation', 'variant must be a string or null.', $state, [
                'fields' => ['variant' => ['string or null']],
            ]);
        }

        $variant = $input['variant'];

        return $this->structure->mutate(
            $ctx,
            $page,
            $base,
            $epoch,
            function (array $sections) use ($index, $variant, $pageKind): array {
                $section = $sections[$index] ?? null;
                if (! is_array($section)) {
                    throw ValidationException::withMessages([
                        'stored_index' => 'Section index is out of range.',
                    ]);
                }

                $type = $section['type'] ?? null;
                if (! is_string($type) || ! $this->structure->isCatalogued($type) || $this->catalog->isInjectedOnly($type)) {
                    $this->structure->unknownType();
                }

                // Recipe vocabulary for this page kind (Task 12 seam) merged with the inline-family vocabulary.
                $options = array_values(array_unique([...$this->layouts->variantOptionsFor($pageKind, $type), ...$this->schema->variantOptionsFor($type)]));
                if ($variant !== null && ! in_array($variant, $options, true)) {
                    throw ValidationException::withMessages([
                        'variant' => 'must be a registered variant',
                    ]);
                }

                $sections[$index]['variant'] = $variant;

                return array_values($sections);
            },
            $input,
        );
    }
}
