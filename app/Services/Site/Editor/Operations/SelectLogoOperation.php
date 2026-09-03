<?php

namespace App\Services\Site\Editor\Operations;

use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;

final class SelectLogoOperation extends BaseOperation
{
    public function __construct(
        private readonly RestoreImageVersionOperation $restore,
        private readonly EditorStateFactory $states,
    ) {}

    public function name(): string
    {
        return 'select_logo';
    }

    public function readOnly(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function delegatesTo(): array
    {
        return ['restore_image_version'];
    }

    public function address(): string
    {
        return 'site';
    }

    public function sideEffects(): string
    {
        return 'Writes a draft logo selection; does not flip is_selected.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['concept_id', 'composition_revision'],
            'properties' => [
                'concept_id' => ['type' => 'integer'],
                'composition_revision' => ['type' => 'integer'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(EditorContext $ctx, array $input): OperationResult
    {
        $state = $this->states->for($ctx->site, null);
        $conceptId = self::intOrNull($input['concept_id'] ?? null);

        if ($conceptId === null) {
            return OperationResult::fail('validation', 'concept_id is required.', $state, [
                'fields' => ['concept_id' => ['required integer']],
            ]);
        }

        return $this->delegate($this->restore, $ctx, [
            'scope' => 'logo',
            'version_id' => $conceptId,
            'composition_revision' => $input['composition_revision'] ?? null,
        ]);
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
