<?php

namespace App\Services\Site\Editor\Operations;

use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorJobStatus;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\Editor\ResultReceipt;

final class GetJobStatusOperation extends BaseOperation
{
    public function __construct(
        private readonly EditorJobStatus $jobs,
        private readonly EditorStateFactory $states,
    ) {}

    public function name(): string
    {
        return 'get_job_status';
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function sideEffects(): string
    {
        return 'Reads async generation job status.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['job_ref'],
            'properties' => [
                'job_ref' => ['type' => 'string'],
            ],
        ];
    }

    public function wrapInAdminChange(): bool
    {
        return false;
    }

    public function address(): string
    {
        return 'site';
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(EditorContext $ctx, array $input): OperationResult
    {
        $state = $this->states->for($ctx->site, null);
        $ref = $input['job_ref'] ?? null;

        if (! is_string($ref) || $ref === '') {
            return OperationResult::fail('validation', 'job_ref is required.', $state, [
                'fields' => ['job_ref' => ['required string']],
            ]);
        }

        $record = $this->jobs->get($ctx->site, $ref);

        if ($record === null) {
            return OperationResult::fail('not_found', 'Job not found.', $state);
        }

        $data = [
            'status' => $record['status'],
            'result' => $record['result'] ?? null,
            // A fixed classification code (`provider_error` / `internal`), never provider text — see
            // EditorJobStatus::failureCode. This value reaches an external model.
            'error' => $record['error'] ?? null,
        ];

        if (array_key_exists('current_revision_id', $record)) {
            $data['current_revision_id'] = $record['current_revision_id'];
        }

        $result = OperationResult::ok($data, $state);
        if (is_array($record['receipt'] ?? null)) {
            $result->receipt = ResultReceipt::fromArray($record['receipt']);
        }

        return $result;
    }
}
