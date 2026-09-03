<?php

namespace App\Services\Site\Editor\Operations;

use App\Services\Shop\ProductImportContract;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\Editor\Shop\ShopEntityResolver;
use App\Services\Site\Editor\Shop\ShopReadOperation;

final class DescribeImportProductsOperation extends ShopReadOperation
{
    public function __construct(
        ShopEntityResolver $resolver,
        EditorStateFactory $states,
    ) {
        parent::__construct($resolver, $states);
    }

    public function name(): string
    {
        return 'describe_import_products';
    }

    /**
     * @return list<string>
     */
    public function allowedRoles(): ?array
    {
        return ['staff', 'client'];
    }

    public function sideEffects(): string
    {
        return 'Describes the import_products contract: canonical fields, per-format examples, and limits. Read-only; writes nothing.';
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

    /**
     * @param  array<string, mixed>  $input
     */
    protected function handleShopRead(EditorContext $ctx, array $input): OperationResult
    {
        return OperationResult::ok(ProductImportContract::describe(), $this->commerceState($ctx->site));
    }
}
