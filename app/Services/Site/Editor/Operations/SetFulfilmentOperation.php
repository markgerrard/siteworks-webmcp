<?php

namespace App\Services\Site\Editor\Operations;

use App\Services\Shop\Fulfilment\FulfilmentConfig;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\PublicPageCache;

final class SetFulfilmentOperation extends BaseOperation
{
    public function __construct(
        private readonly EditorStateFactory $states,
        private readonly PublicPageCache $publicCache,
    ) {}

    public function name(): string
    {
        return 'set_fulfilment';
    }

    public function readOnly(): bool
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
        return 'Writes the live sites.fulfilment JSON (delivery zones, collect, shipping, widget). Does not publish a draft.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['fulfilment', 'composition_revision'],
            'properties' => [
                'fulfilment' => FulfilmentConfig::schema(),
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
        $raw = $input['fulfilment'] ?? null;

        if (! is_array($raw)) {
            return OperationResult::fail('validation', 'fulfilment is invalid.', $state, [
                'fields' => ['fulfilment' => ['must be an object']],
            ]);
        }

        $result = FulfilmentConfig::validate($raw);
        if ($result['ok'] === false) {
            $fields = [];
            foreach ($result['errors'] as $field => $messages) {
                $fields[$field] = $messages;
            }

            return OperationResult::fail('validation', 'fulfilment is invalid.', $state, [
                'fields' => $fields,
            ]);
        }

        $previous = $ctx->site->fulfilment;
        $ctx->site->update(['fulfilment' => $result['value']]);
        $this->publicCache->invalidate($ctx->site);

        $ctx->changes->record(
            'site',
            'sites.fulfilment',
            $previous,
            $result['value'],
            'update',
        );

        return OperationResult::ok([
            'fulfilment' => $result['value'],
        ], $state);
    }
}
