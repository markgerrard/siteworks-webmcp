<?php

namespace App\Services\Site\Editor\Shop;

use App\Enums\Shop\ProductStatus;
use App\Models\Shop\Product;
use App\Services\Site\Editor\EditorState;
use App\Services\Site\Editor\OperationFailed;
use App\Services\Site\Editor\OperationResult;

final class DraftOnlyGuard
{
    public static function assert(Product $product, EditorState $state): void
    {
        if ($product->status === ProductStatus::Draft) {
            return;
        }

        throw new OperationFailed(OperationResult::fail(
            'published_product_immutable',
            'Published and archived products cannot be changed by commerce tools.',
            $state,
        ));
    }
}
