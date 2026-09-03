<?php

namespace App\Services\Site\Editor\Operations;

use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\OperationResult;

final class InspectDraftOperation extends BaseOperation
{
    public function __construct(
        private readonly GetDraftDiffOperation $getDraftDiff,
        private readonly ValidateDraftOperation $validateDraft,
    ) {}

    public function name(): string
    {
        return 'inspect_draft';
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

    /**
     * @return list<string>
     */
    public function delegatesTo(): array
    {
        return ['get_draft_diff', 'validate_draft'];
    }

    public function sideEffects(): string
    {
        return 'Composes unpublished diffs and pre-publish findings; never triggers a capture and carries no screenshot flag. Never publishes.';
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
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(EditorContext $ctx, array $input): OperationResult
    {
        $diff = $this->delegate($this->getDraftDiff, $ctx, $input);
        if (! $diff->ok) {
            return $diff;
        }

        $validate = $this->delegate($this->validateDraft, $ctx, $input);
        if (! $validate->ok) {
            return $validate;
        }

        return OperationResult::ok([
            'diff' => $diff->data,
            'findings' => $validate->data['findings'],
        ], $validate->state);
    }
}
