<?php

namespace App\Services\Site\Editor\Operations;

use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\Editor\Shop\ShopEntityResolver;
use App\Services\Site\Editor\Shop\ShopProductProjection;
use App\Services\Site\Editor\Shop\ShopReadOperation;

final class ShopGetProductOperation extends ShopReadOperation
{
    public function __construct(
        ShopEntityResolver $resolver,
        EditorStateFactory $states,
        private readonly ShopProductProjection $projection,
    ) {
        parent::__construct($resolver, $states);
    }

    public function name(): string
    {
        return 'get_product';
    }

    public function sideEffects(): string
    {
        return 'Reads one catalogue product. This does not publish anything — draft products stay hidden on the live site until a human publishes them.';
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
                'slug' => ['type' => 'string'],
                'product_id' => ['type' => 'integer'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function handleShopRead(EditorContext $ctx, array $input): OperationResult
    {
        $state = $this->commerceState($ctx->site);
        $product = $this->resolver->product($ctx->site, $input);
        $ctx->warnings->add(
            'preview_unavailable',
            'A signed shop preview URL is not available yet.',
            severity: 'info',
        );

        return OperationResult::ok([
            'catalogue_revision' => $this->catalogueRevision($ctx->site),
            ...$this->projection->detail($product),
        ], $state);
    }
}
