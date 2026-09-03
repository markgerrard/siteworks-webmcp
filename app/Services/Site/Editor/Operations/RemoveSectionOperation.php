<?php

namespace App\Services\Site\Editor\Operations;

use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\OperationResult;
use Illuminate\Validation\ValidationException;

final class RemoveSectionOperation extends BaseOperation
{
    public function __construct(private readonly StructureWrite $structure) {}

    public function name(): string
    {
        return 'remove_section';
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function destructive(): bool
    {
        return true;
    }

    public function sideEffects(): string
    {
        return 'Removes a section from the page draft.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['page_id', 'revision_base', 'structure_epoch'],
            'properties' => [
                'page_id' => ['type' => 'integer'],
                'stored_index' => ['type' => 'integer'],
                'section_id' => [
                    'type' => 'string',
                    'description' => 'Stable section identifier. At least one of stored_index and section_id is required; when both are given they must name the same section.',
                ],
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

        $index = $this->structure->resolveSectionAddress(
            $this->structure->currentSections($page),
            $input,
            'stored_index',
            $state,
        );
        if ($index instanceof OperationResult) {
            return $index;
        }

        return $this->structure->mutate(
            $ctx,
            $page,
            $base,
            $epoch,
            function (array $sections) use ($index): array {
                $section = $sections[$index] ?? null;
                if (! is_array($section)) {
                    throw ValidationException::withMessages([
                        'stored_index' => 'Section index is out of range.',
                    ]);
                }

                $type = $section['type'] ?? null;
                if (! is_string($type) || ! $this->structure->isCatalogued($type)) {
                    $this->structure->unknownType();
                }

                array_splice($sections, $index, 1);

                return array_values($sections);
            },
            $input,
        );
    }
}
