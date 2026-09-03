<?php

namespace App\Services\Site\Editor\Operations;

use App\Models\GeneratedPage;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\DraftValidator;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;

final class ValidateDraftOperation extends BaseOperation
{
    public function __construct(
        private readonly DraftValidator $validator,
        private readonly EditorStateFactory $states,
    ) {}

    public function name(): string
    {
        return 'validate_draft';
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function wrapInAdminChange(): bool
    {
        return false;
    }

    public function address(): string
    {
        return 'site';
    }

    public function sideEffects(): string
    {
        return 'Reads structured pre-publish findings without publishing or fetching external URLs; some section variants paint literal colours a theme write cannot move.';
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
                'checks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'enum' => DraftValidator::CODES,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(EditorContext $ctx, array $input): OperationResult
    {
        $state = $this->states->for($ctx->site, null);
        $pageId = $input['page_id'] ?? null;

        if ($pageId !== null) {
            if (! is_int($pageId) && ! (is_string($pageId) && preg_match('/^[1-9][0-9]*$/', $pageId) === 1)) {
                return OperationResult::fail('not_found', 'Page not found.', $state);
            }

            $page = GeneratedPage::query()
                ->where('site_id', $ctx->site->id)
                ->whereNull('archived_at')
                ->find((int) $pageId);

            if ($page === null) {
                return OperationResult::fail('not_found', 'Page not found.', $state);
            }

            $state = $this->states->for($ctx->site, $page);
            $pageId = $page->id;
        }

        $checks = $input['checks'] ?? null;
        if ($checks !== null) {
            if (! is_array($checks)) {
                return OperationResult::fail('validation', 'checks must be an array of finding codes.', $state, [
                    'fields' => ['checks' => ['must be an array of known finding codes']],
                ]);
            }

            foreach ($checks as $code) {
                if (! is_string($code) || ! in_array($code, DraftValidator::CODES, true)) {
                    return OperationResult::fail('validation', 'Unknown finding code in checks.', $state, [
                        'fields' => ['checks' => ['contains an unknown finding code']],
                    ]);
                }
            }

            $checks = array_values($checks);
        }

        return OperationResult::ok([
            'findings' => $this->validator->findings($ctx->site, $pageId, $checks),
        ], $state);
    }
}
