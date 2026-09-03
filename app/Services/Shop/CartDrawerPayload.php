<?php

namespace App\Services\Shop;

use App\Enums\Shop\ProductStatus;
use App\Models\Shop\Cart;
use App\Models\Shop\Product;
use App\Models\Site;
use App\Support\ShopMoney;
use Illuminate\Support\Collection;

class CartDrawerPayload
{
    public function __construct(
        protected StockService $stock,
        protected ShippingService $shipping,
        protected PersonalisationImageStore $images,
    ) {}

    /**
     * @return array{
     *     count: int,
     *     subtotal_display: string,
     *     items: list<array{id: int, name: string, variant_label: string, price_display: string, qty: int, image_url: ?string, product_url: string}>,
     *     upsell: list<array{slug: string, name: string, price_display: string, image_url: ?string, add_variant_id: ?int}>,
     *     free_shipping: array{threshold_display: string, remaining_display: string, progress_pct: int}|null
     * }
     */
    public function for(Site $site, Cart $cart, ?Product $recentlyAdded = null): array
    {
        $cart->loadMissing('items.variant.product.images', 'items.variant.product.variants', 'items.variant.product.categories');
        $currency = $site->shop_currency ?? 'GBP';

        $items = [];
        $subtotal = 0;
        foreach ($cart->items as $item) {
            $product = $item->variant->product;
            $subtotal += $item->qty * $item->unit_price_cents;
            $row = [
                'id' => $item->id,
                'name' => $product->name,
                'category' => (string) ($product->categories->firstWhere('pivot.is_primary', true)?->name ?? $product->categories->first()?->name ?? ''),
                'variant_label' => $item->variant->shopperFacingLabel(),
                'price_display' => ShopMoney::formatWithVat((int) $item->unit_price_cents, $currency),
                'qty' => (int) $item->qty,
                'image_url' => $product->images->first()?->url('thumb'),
                'product_url' => \App\Support\Shop\ShopUrls::product($product),
            ];
            $personalisation = LinePersonalisation::displayRows($item->personalisation);
            if ($personalisation !== []) {
                $row['personalisation'] = $this->decoratePersonalisation($site, $personalisation);
                $row['personalisation_edit'] = $item->personalisation;
                $row['customer_inputs'] = is_array($product->customer_inputs) ? $product->customer_inputs : [];
            }
            $items[] = $row;
        }

        return [
            'count' => (int) $cart->items->sum('qty'),
            'subtotal_display' => ShopMoney::formatWithVat($subtotal, $currency),
            'items' => $items,
            'upsell' => $this->upsell($site, $cart, $recentlyAdded, $currency),
            'free_shipping' => $this->shipping->freeShippingProgress((int) $site->id, $subtotal, $currency),
        ];
    }

    /**
     * @return list<array{slug: string, name: string, price_display: string, image_url: ?string, add_variant_id: ?int}>
     */
    private function upsell(Site $site, Cart $cart, ?Product $recentlyAdded, string $currency): array
    {
        $excludeIds = $cart->items
            ->map(fn ($item) => $item->variant?->product_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $recent = $recentlyAdded;
        if ($recent === null) {
            $latest = $cart->items
                ->sortByDesc(fn ($item) => $item->updated_at?->getTimestamp() ?? 0)
                ->first();
            $recent = $latest?->variant?->product;
        }
        if ($recent !== null) {
            $recent->loadMissing('categories');
        }

        $categoryId = $recent?->categories->firstWhere('pivot.is_primary', true)?->id
            ?? $recent?->categories->first()?->id;

        $picked = $this->candidateProducts($site, $excludeIds, $categoryId);
        if ($picked->isEmpty() && $categoryId) {
            $picked = $this->candidateProducts($site, $excludeIds, null);
        }

        return $picked->map(function (Product $product) use ($currency): array {
            $variants = $product->variants;
            $addVariantId = $variants->count() === 1 ? (int) $variants->first()->id : null;
            $cents = (int) ($variants->first()?->price_cents ?? 0);

            return [
                'slug' => $product->slug,
                'name' => $product->name,
                'price_display' => ShopMoney::displayWithVat($cents, $currency, (bool) $product->price_from),
                'image_url' => $product->images->first()?->url('card'),
                'add_variant_id' => $addVariantId,
            ];
        })->values()->all();
    }

    /**
     * @param  list<int>  $excludeIds
     * @return Collection<int, Product>
     */
    private function candidateProducts(Site $site, array $excludeIds, ?int $categoryId): Collection
    {
        $query = Product::query()
            ->where('site_id', $site->id)
            ->where('status', ProductStatus::Published)
            ->with(['variants', 'images']);

        if ($excludeIds !== []) {
            $query->whereNotIn('id', $excludeIds);
        }
        if ($categoryId) {
            $query->whereHas('categories', fn ($q) => $q->where('shop_categories.id', $categoryId));
        }

        $products = $query->orderByDesc('id')->limit(12)->get();
        if ($products->isEmpty()) {
            return $products;
        }

        $variantIds = $products->pluck('variants.*.id')->flatten()->unique()->all();
        $onHand = $this->stock->onHandMap($variantIds);

        return $products
            ->filter(function (Product $product) use ($onHand): bool {
                foreach ($product->variants as $variant) {
                    if (($onHand[$variant->id] ?? 0) > 0) {
                        return true;
                    }
                }

                return false;
            })
            ->take(2)
            ->values();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function decoratePersonalisation(Site $site, array $rows): array
    {
        $ttl = (int) config('shop_input_presets.signed_url_ttl_seconds', 300);
        foreach ($rows as $i => $row) {
            foreach ($row['images'] ?? [] as $j => $image) {
                $path = $image['path'] ?? '';
                if (! is_string($path) || $path === '') {
                    continue;
                }
                $rows[$i]['images'][$j]['url'] = $this->images->signedUrl($site, $path, $ttl);
            }
        }

        return $rows;
    }

}
