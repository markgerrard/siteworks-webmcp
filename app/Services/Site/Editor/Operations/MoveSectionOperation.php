<?php

namespace App\Services\Site\Editor\Operations;

use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\OperationResult;
use Illuminate\Validation\ValidationException;

final class MoveSectionOperation extends BaseOperation
{
    public function __construct(private readonly StructureWrite $structure) {}

    public function name(): string
    {
        return 'move_section';
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function sideEffects(): string
    {
        return 'Reorders a section in the page draft.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['page_id', 'to', 'revision_base', 'structure_epoch'],
            'properties' => [
                'page_id' => ['type' => 'integer'],
                'from' => ['type' => 'integer'],
                'section_id' => [
                    'type' => 'string',
                    'description' => 'Stable identifier of the section to move. At least one of from and section_id is required; when both are given they must name the same section.',
                ],
                'to' => [
                    'type' => 'integer',
                    'description' => 'Destination slot (a position, not a section).',
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

        $from = $this->structure->resolveSectionAddress(
            $this->structure->currentSections($page),
            $input,
            'from',
            $state,
        );
        if ($from instanceof OperationResult) {
            return $from;
        }

        $to = $this->structure->intOrNull($input['to'] ?? null);
        if ($to === null || $to < 0) {
            return OperationResult::fail('validation', 'to must be a non-negative integer.', $state, [
                'fields' => ['to' => ['required integer']],
            ]);
        }

        return $this->structure->mutate(
            $ctx,
            $page,
            $base,
            $epoch,
            function (array $sections) use ($from, $to): array {
                $section = $sections[$from] ?? null;
                if (! is_array($section)) {
                    throw ValidationException::withMessages([
                        'from' => 'Section index is out of range.',
                    ]);
                }

                $type = $section['type'] ?? null;
                if (! is_string($type) || ! $this->structure->isCatalogued($type)) {
                    $this->structure->unknownType();
                }

                if ($to >= count($sections)) {
                    throw ValidationException::withMessages([
                        'to' => 'Section index is out of range.',
                    ]);
                }

                if ($from === $to) {
                    return $sections;
                }

                $extracted = array_splice($sections, $from, 1);
                array_splice($sections, $to, 0, $extracted);

                return array_values($sections);
            },
            $input,
        );
    }
}
