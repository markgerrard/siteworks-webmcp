@php
    $name = $product['product_card']['name'] ?? '';
    $price = $product['product_card']['price_display'] ?? '';
    $cardSrc = is_array($product['image_urls'] ?? null) ? ($product['image_urls']['card'] ?? null) : null;
    $shopMode = $site->shop_mode ?? 'cart';
    // Stock gates only where adding reserves it (cart mode). A quote list reserves
    // nothing: a product with none on hand is made to order and stays listable.
    $stockGates = $shopMode === 'cart';
    $inStock = ! $stockGates || (bool) ($product['in_stock_any'] ?? true);
    $variants = is_array($product['variants'] ?? null) ? $product['variants'] : [];
    $variantCount = count($variants);
    $singleVariantId = ($variantCount === 1) ? ($variants[0]['id'] ?? null) : null;
    $productHref = \App\Support\Shop\ShopUrls::product((string) $product['slug']);
    $showCartPill = in_array($shopMode, ['cart', 'quote'], true) && $inStock && $variantCount >= 1;
    $showEnquirePill = $shopMode === 'enquire';
    $addLabel = $shopMode === 'quote' ? 'Add to list' : 'Add to cart';
@endphp
<div
    class="shop-product-card relative block overflow-hidden max-w-full"
    style="border: 1px solid var(--color-border); border-radius: var(--radius-card); position: relative;"{!! ! empty($product['f']) ? ' data-f="'.e(json_encode($product['f'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)).'"' : '' !!}
>
    <a
        href="{{ $productHref }}"
        class="block overflow-hidden max-w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
        style="outline-color: var(--color-accent);"
    >
        <div class="shop-product-card__image w-full overflow-hidden" style="aspect-ratio: 1 / 1; background-color: var(--color-surface-alt);">
            @if (is_string($cardSrc) && $cardSrc !== '')
                <img
                    src="{{ $cardSrc }}"
                    alt="{{ $name }}"
                    width="400"
                    height="400"
                    class="shop-product-card__img w-full h-full object-cover"
                    style="aspect-ratio: 1 / 1;"
                >
            @else
                @include('shop.partials.product-image-placeholder', ['name' => $name, 'size' => 'card'])
            @endif
        </div>
        {!! \App\Support\Shop\ProductBadges::markup(is_array($product['tags'] ?? null) ? $product['tags'] : [], 2, 'card') !!}<div class="p-3 min-w-0">
            <div class="font-semibold break-words">{{ $name }}</div>
{!! \App\Support\Shop\ProductReviews::cardMarkup($site, $product) !!}@if (! empty($product['product_card']['facts_line']))<div class="mt-1 text-sm" style="color: var(--color-text-muted);">{{ $product['product_card']['facts_line'] }}</div>@endif            @if ($price !== '')
                <div class="mt-1 text-sm">
                    <x-shop.price :amount="$price" :vat="\App\Support\ShopMoney::includesVat($site->shop_currency ?? 'GBP')" />
                </div>
            @endif
            @if (! $inStock)
                <div class="mt-1">
                    <x-shop.stock-pill state="out" />
                </div>
            @endif
        </div>
    </a>
    @if ($showCartPill)
        <div class="shop-product-card__pill-slot" data-shop-card-pill>
            @if ($variantCount === 1 && $singleVariantId)
                <form method="POST" action="/shop/cart/add" class="shop-product-card__pill" data-product-name="{{ $name }}">
                    @csrf
                    <input type="hidden" name="product_slug" value="{{ $product['slug'] }}">
                    <input type="hidden" name="variant_id" value="{{ $singleVariantId }}">
                    <input type="hidden" name="qty" value="1">
                    <button type="submit" class="shop-product-card__pill-btn">{{ $addLabel }}</button>
                </form>
            @else
                <a href="{{ $productHref }}" class="shop-product-card__pill-btn">Choose options</a>
            @endif
        </div>
    @elseif ($showEnquirePill)
        <div class="shop-product-card__pill-slot" data-shop-card-pill>
            <a href="{{ $productHref }}" class="shop-product-card__pill-btn">Enquire</a>
        </div>
    @endif
</div>
