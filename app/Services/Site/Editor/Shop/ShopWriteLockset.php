<?php

namespace App\Services\Site\Editor\Shop;

use App\Models\Shop\Product;
use App\Models\Shop\ShopDraft;

final class ShopWriteLockset
{
    /**
     * @param  list<Product>  $products
     */
    public function __construct(
        public readonly array $products,
        public readonly ShopDraft $draft,
    ) {}
}
