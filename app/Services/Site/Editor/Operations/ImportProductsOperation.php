<?php

namespace App\Services\Site\Editor\Operations;

use App\Services\Shop\ProductImportFailed;
use App\Services\Shop\ProductImporter;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationFailed;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\Editor\ResultReceipt;
use App\Services\Site\Editor\Shop\ShopEntityResolver;
use App\Services\Site\Editor\Shop\ShopWriteLockset;
use App\Services\Site\Editor\Shop\ShopWriteOperation;

final class ImportProductsOperation extends ShopWriteOperation
{
    public function __construct(
        private readonly ShopEntityResolver $resolver,
        private readonly EditorStateFactory $states,
        private readonly ProductImporter $importer,
    ) {}

    public function name(): string
    {
        return 'import_products';
    }

    public function sideEffects(): string
    {
        return 'Imports catalogue products as drafts from json, csv, or md. This does not publish anything — every imported product stays hidden on the live site until a human publishes it. Committing (dry_run false) requires a fresh idempotency_key: a reused key returns the earlier commit\'s receipt instead of importing again.';
    }

    /**
     * @return list<string>
     */
    public function allowedRoles(): ?array
    {
        return ['staff', 'client'];
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['schema_version', 'format', 'data', 'catalogue_revision'],
            'properties' => [
                'schema_version' => ['type' => 'integer'],
                'format' => ['type' => 'string', 'enum' => ['json', 'csv', 'md']],
                'data' => ['type' => 'string'],
                'source_label' => ['type' => 'string'],
                'dry_run' => ['type' => 'boolean'],
                'force_create' => ['type' => 'boolean', 'description' => 'Create rows that match an existing product by name instead of reporting them as matched. Matches are still noted for the reviewer.'],
                'expected_revision' => ['type' => 'integer'],
                'catalogue_revision' => ['type' => 'integer'],
                'idempotency_key' => ['type' => 'string'],
                'plan_token' => ['type' => 'string'],
            ],
        ];
    }

    public function revisionMismatchCode(): string
    {
        return 'revision_conflict';
    }

    public function managesOwnRevision(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<int>
     */
    public function subjectProductIds(array $input): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function handleShopWrite(EditorContext $ctx, array $input, ShopWriteLockset $locks): OperationResult
    {
        $state = $this->states->for($ctx->site, null);
        $this->resolver->requireShop($ctx->site);

        try {
            $data = $this->importer->run(
                $ctx->site,
                $input,
                $ctx->actor->id,
                $ctx->channel->isAgent(),
            );
        } catch (ProductImportFailed $exception) {
            throw new OperationFailed(OperationResult::fail(
                $exception->errorCode,
                $exception->getMessage(),
                $state,
                $exception->extra,
            ));
        }

        $result = OperationResult::ok($data, $state);
        $result->receipt = ResultReceipt::fromArray([
            'new_revision' => $data['new_revision'],
            'effective' => null,
            'changed' => [],
            'warnings' => [],
        ]);

        return $result;
    }
}
