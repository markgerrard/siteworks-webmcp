<?php

namespace App\Services\Site\Editor\Shop;

use App\Models\Shop\Product;
use App\Models\Shop\ProductImage;
use App\Models\Shop\ProductVariant;
use App\Services\Shop\StockService;

final class ShopProductProjection
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * @return array{slug: string, name: string, status: string, revision: int, is_ai_seeded: bool, is_ai_reviewed: bool}
     */
    public function listItem(Product $product): array
    {
        return [
            'slug' => $product->slug,
            'name' => $product->name,
            'status' => $product->status->value,
            'revision' => (int) $product->revision,
            'is_ai_seeded' => (bool) $product->is_ai_seeded,
            'is_ai_reviewed' => (bool) $product->is_ai_reviewed,
        ];
    }

    /**
     * @return array{
     *     slug: string,
     *     name: string,
     *     description: ?string,
     *     status: string,
     *     revision: int,
     *     is_ai_seeded: bool,
     *     is_ai_reviewed: bool,
     *     tags: list<string>,
     *     customer_inputs: list<array<string, mixed>>,
     *     variants: list<array{sku: string, label: ?string, price_pence: int, weight_grams: ?int, on_hand: int, available: int}>,
     *     images: list<array{sort_order: int, url: string, alt: ?string}>,
     *     categories: list<array{slug: string, is_primary: bool}>
     * }
     */
    public function detail(Product $product): array
    {
        $product->loadMissing(['variants', 'images', 'categories']);
        $variantIds = $product->variants->pluck('id')->all();
        $onHand = $this->stock->onHandMap($variantIds);

        return [
            'slug' => $product->slug,
            'name' => $product->name,
            'description' => $product->description,
            'status' => $product->status->value,
            'revision' => (int) $product->revision,
            'is_ai_seeded' => (bool) $product->is_ai_seeded,
            'is_ai_reviewed' => (bool) $product->is_ai_reviewed,
            'tags' => array_values($product->tags ?? []),
            'customer_inputs' => is_array($product->customer_inputs) ? $product->customer_inputs : [],
            'variants' => $product->variants
                ->sortBy('id')
                ->values()
                ->map(function (ProductVariant $variant) use ($onHand): array {
                    $onHandQty = (int) ($onHand[$variant->id] ?? 0);

                    return [
                        'sku' => $variant->sku,
                        'label' => $variant->label,
                        'price_pence' => (int) $variant->price_cents,
                        'weight_grams' => $variant->weight_grams,
                        'on_hand' => $onHandQty,
                        'available' => $this->stock->available($variant->id, lock: false),
                    ];
                })
                ->all(),
            'images' => $product->images
                ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
                ->values()
                ->map(fn (ProductImage $image): array => [
                    'sort_order' => (int) $image->sort_order,
                    'url' => $image->url(),
                    'alt' => $image->alt,
                ])
                ->all(),
            'categories' => $product->categories
                ->sortBy('id')
                ->values()
                ->map(fn ($category): array => [
                    'slug' => $category->slug,
                    'is_primary' => (bool) $category->pivot->is_primary,
                ])
                ->all(),
        ];
    }
}
