<?php

namespace App\Services\Site\Editor\Operations;

use App\Enums\Shop\ProductStatus;
use App\Models\Shop\Product;
use App\Services\Shop\ProductSearchService;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorState;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationFailed;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\Editor\Shop\ShopEntityResolver;
use App\Services\Site\Editor\Shop\ShopProductCursor;
use App\Services\Site\Editor\Shop\ShopProductProjection;
use App\Services\Site\Editor\Shop\ShopReadOperation;
use Illuminate\Database\Eloquent\Builder;

final class ShopListProductsOperation extends ShopReadOperation
{
    public function __construct(
        ShopEntityResolver $resolver,
        EditorStateFactory $states,
        private readonly ProductSearchService $search,
        private readonly ShopProductProjection $projection,
    ) {
        parent::__construct($resolver, $states);
    }

    public function name(): string
    {
        return 'list_products';
    }

    public function sideEffects(): string
    {
        return 'Reads the merchant catalogue. Drafts stay hidden on the live storefront until a human publishes them.';
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
                'status' => ['type' => 'string', 'enum' => ['draft', 'published', 'archived', 'any']],
                'category_slug' => ['type' => 'string'],
                'q' => ['type' => 'string'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                'cursor' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function handleShopRead(EditorContext $ctx, array $input): OperationResult
    {
        $state = $this->commerceState($ctx->site);
        $limit = $this->limitFrom($input, $state);
        $offset = 0;

        if (is_string($input['cursor'] ?? null) && $input['cursor'] !== '') {
            $offset = ShopProductCursor::decode($ctx->site, $input['cursor'], $state);
        }

        $includeDrafts = $this->includeDrafts($ctx);
        $query = Product::query()->where('site_id', $ctx->site->id);

        $status = $input['status'] ?? null;
        if (is_string($status) && $status !== '' && $status !== 'any') {
            if (! in_array($status, ['draft', 'published', 'archived'], true)) {
                throw new OperationFailed(OperationResult::fail(
                    'validation',
                    'status is invalid.',
                    $state,
                    ['fields' => ['status' => ['must be draft, published, archived, or any']]],
                ));
            }
            $query->where('status', $status);
        } elseif (! $includeDrafts) {
            $query->where('status', ProductStatus::Published);
        }

        if (is_string($input['category_slug'] ?? null) && $input['category_slug'] !== '') {
            $category = $this->resolver->category($ctx->site, $input['category_slug']);
            $query->whereHas('categories', fn (Builder $categories) => $categories->whereKey($category->id));
        }

        if (is_string($input['q'] ?? null) && $input['q'] !== '') {
            $matches = $this->search->search($ctx->site->id, $input['q'], $includeDrafts, 50);
            $query->whereIn('id', $matches->modelKeys() === [] ? [0] : $matches->modelKeys());
        }

        $rows = $query->orderBy('id')->offset($offset)->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $products = $rows->take($limit)->map(fn (Product $product): array => $this->projection->listItem($product))->values()->all();

        return OperationResult::ok([
            'catalogue_revision' => $this->catalogueRevision($ctx->site),
            'products' => $products,
            'next_cursor' => $hasMore ? ShopProductCursor::encode($ctx->site->id, $offset + $limit) : null,
        ], $state);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function limitFrom(array $input, EditorState $state): int
    {
        if (! array_key_exists('limit', $input) || $input['limit'] === null) {
            return 20;
        }

        $limit = $input['limit'];
        if (is_string($limit) && preg_match('/^[1-9][0-9]*$/', $limit) === 1) {
            $limit = (int) $limit;
        }

        if (! is_int($limit) || $limit < 1 || $limit > 50) {
            throw new OperationFailed(OperationResult::fail(
                'validation',
                'limit must be an integer between 1 and 50.',
                $state,
                ['fields' => ['limit' => ['integer 1-50']]],
            ));
        }

        return $limit;
    }
}
