<?php

namespace App\Services\Site\Editor\Operations;

use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\SitePublishService;

final class PublishSummaryOperation extends BaseOperation
{
    public function __construct(
        private readonly SitePublishService $publish,
        private readonly EditorStateFactory $states,
    ) {}

    public function name(): string
    {
        return 'publish_summary';
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function sideEffects(): string
    {
        return 'Reads pending publish summary; never publishes.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [],
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
        return OperationResult::ok(
            $this->publish->publishSummary($ctx->site),
            $this->states->for($ctx->site, null),
        );
    }
}
