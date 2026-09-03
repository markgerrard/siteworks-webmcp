@php
    $homeHref = app(\App\Services\Site\PageRenderer::class)->layoutContext($site)['homeHref'];
    $trail = [
        ['label' => 'Home', 'href' => $homeHref],
        ['label' => 'Shop', 'href' => url('/shop')],
    ];
    $categorySlug = $product['primary_category_slug'] ?? null;
    $categoryName = null;
    $categoryPath = $categorySlug;
    $snapshot = app(\App\Services\Shop\SnapshotReader::class)->forSite($site->id);
    if (is_string($categorySlug) && $categorySlug !== '') {
        $primaryCategory = $snapshot['categories'][$categorySlug] ?? null;
        $categoryName = is_array($primaryCategory) ? ($primaryCategory['name'] ?? null) : null;
        $categoryPath = is_array($primaryCategory) ? ($primaryCategory['path'] ?? $categorySlug) : $categorySlug;
        $crumbs = is_array($primaryCategory) ? ($primaryCategory['breadcrumb'] ?? null) : null;
        if (is_array($crumbs) && $crumbs !== []) {
            foreach ($crumbs as $crumb) {
                $trail[] = ['label' => $crumb['name'], 'href' => \App\Support\Shop\ShopUrls::collectionAbsolute($crumb['path'])];
            }
        } elseif (is_string($categoryName) && $categoryName !== '') {
            $trail[] = ['label' => $categoryName, 'href' => \App\Support\Shop\ShopUrls::collectionAbsolute($categoryPath)];
        }
    }
    $trail[] = ['label' => $product['product_detail']['name']];
    $productName = $product['product_detail']['name'] ?? $product['slug'];
    $pageTitle = $productName.' — '.$site->business_name;
    $pageDescription = mb_substr(trim(strip_tags((string) ($product['product_detail']['description'] ?? ''))), 0, 160);
    $pageDescription = $pageDescription !== '' ? $pageDescription : null;
    $canonical = \App\Support\Shop\ShopUrls::productAbsolute($product['slug']);
    $shopMode = $site->shop_mode ?? 'cart';
    $ogImage = \App\Support\Shop\ShopOpenGraph::productImage($product) ?? $site->ogImageUrl();
    $ogDescription = \App\Support\Shop\ShopJsonLd::plainText((string) ($product['product_detail']['description'] ?? ''), 200);
    $ogDescription = $ogDescription !== '' ? $ogDescription : $pageDescription;
    $emitPrice = \App\Support\Shop\ShopOpenGraph::shouldEmitPrice($product, $shopMode);
    $ogPriceAmount = $emitPrice ? \App\Support\Shop\ShopOpenGraph::priceAmount($product) : null;
    $ogPriceCurrency = $emitPrice ? \App\Support\Shop\ShopOpenGraph::currency($site) : null;

    // Stock gates only where adding reserves it (cart mode). A quote list reserves
    // nothing: a product with none on hand is made to order and can still be listed.
    $stockGates = $shopMode === 'cart';
    $variantAvailable = fn (array $variantOption): bool => ! $stockGates || ($product['variant_in_stock'][$variantOption['id']] ?? false);
    $canAdd = ! $stockGates || (bool) ($product['in_stock_any'] ?? false);

    $selectedVariantId = null;
    foreach ($product['variants'] as $variantOption) {
        if ($variantAvailable($variantOption)) {
            $selectedVariantId = $variantOption['id'];
            break;
        }
    }

    $gallerySrc = is_array($product['image_urls'] ?? null) ? ($product['image_urls']['full'] ?? null) : null;
    $stockState = ($product['in_stock_any'] ?? false) ? 'in' : 'out';
    $currency = $currency ?? ($site->shop_currency ?? 'GBP');
    $factGroups = \App\Support\Shop\ProductFacts::groups($site->product_fact_groups);
    $productFacts = is_array($product['product_detail']['facts'] ?? null) ? $product['product_detail']['facts'] : [];
    $factTabs = \App\Support\Shop\ProductFacts::visibleTabs($factGroups, $productFacts);
    $reviewSettings = $reviewSettings ?? \App\Support\Shop\ProductReviewSettings::fromSite($site);
    $showReviews = $reviewSettings->enabled;
    $reviewTabs = ($showReviews && $factTabs !== [])
        ? [['slug' => 'reviews', 'label' => $reviewSettings->label]]
        : [];
    $showReviewsSection = $showReviews && $factTabs === [];
@endphp
<x-shop.layout
    :site="$site"
    :title="$pageTitle"
    :meta-description="$pageDescription"
    :canonical="$canonical"
    :og-title="$productName"
    :og-description="$ogDescription"
    :og-image="$ogImage"
    :og-image-width="\App\Support\Shop\ShopOpenGraph::productWidth($product)"
    :og-image-height="\App\Support\Shop\ShopOpenGraph::productHeight($product)"
    og-type="product"
    :og-url="$canonical"
    :product-price-amount="$ogPriceAmount"
    :product-price-currency="$ogPriceCurrency"
>
    <div class="px-4 sm:px-6 lg:px-8 py-6 max-w-full">
        <x-shop.breadcrumbs :trail="$trail" />

        <div class="mt-6 grid md:grid-cols-2 gap-8 max-w-full">
            <div class="w-full max-w-full overflow-hidden" style="aspect-ratio: 1 / 1; border-radius: var(--radius-card); background-color: var(--color-surface-alt);">
                @if (is_string($gallerySrc) && $gallerySrc !== '')
                    <img
                        src="{{ $gallerySrc }}"
                        alt="{{ $product['product_detail']['name'] }}"
                        width="800"
                        height="800"
                        class="w-full h-full object-cover"
                        style="aspect-ratio: 1 / 1;"
                    >
                @else
                    @include('shop.partials.product-image-placeholder', ['name' => $product['product_detail']['name'], 'size' => 'full'])
                @endif
            </div>

            <div class="min-w-0">
@if (session('status'))                <p role="status" class="mb-4 rounded p-3 text-sm" style="border: 1px solid var(--color-border); background-color: var(--color-surface-alt);">{{ session('status') }}</p>
@endif                <h1 class="text-3xl font-bold mb-2">{{ $product['product_detail']['name'] }}</h1>
{!! \App\Support\Shop\ProductReviews::pdpSummaryMarkup($site, $product) !!}                {!! \App\Support\Shop\ProductBadges::markup(is_array($product['tags'] ?? null) ? $product['tags'] : [], null, 'pdp') !!}
                <div class="mb-3">
                    <x-shop.price :amount="$product['price_display']" :vat="\App\Support\ShopMoney::includesVat($currency)" />
                </div>
                <div class="mb-4">
                    @if ($shopMode === 'cart')<x-shop.stock-pill :state="$stockState" />@endif
                </div>
@if ($factTabs === [])                <div class="mb-6">{!! nl2br(e($product['product_detail']['description'] ?? '')) !!}</div>@if ($showReviewsSection)@include('shop.partials.product-reviews')@endif
@else                @include('shop.partials.product-facts', [
                    'description' => $product['product_detail']['description'] ?? '',
                    'factTabs' => $factTabs,
                    'extraTabs' => $reviewTabs,
                ])
@endif

                @if ($shopMode === 'enquire')
                    <a
                        href="/enquire?product={{ $product['slug'] }}"
                        class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                        style="display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 0.75rem 1.5rem; background-color: var(--color-primary); color: var(--color-text-on-primary); border-radius: var(--radius-button); outline-color: var(--color-accent);"
                    >Enquire about this cake</a>
                @elseif ($canAdd)
                    {{-- Every control the shopper sets must live INSIDE the form: a browser
                         submits nothing outside it, so a variant picker rendered as a sibling
                         is decorative. CartController::add requires product_slug, variant_id
                         AND qty, and a missing one is a silent redirect-back, not an error. --}}
                    <form
                        method="POST"
                        action="/shop/cart/add"
                        data-product-name="{{ $productName }}"
                        x-data="{ added: false, error: '' }"
                        @shop-cart-add="added = $event.detail.ok; error = $event.detail.ok ? '' : ($event.detail.message || '')"
                        class="space-y-4"
                        {!! empty($product['customer_inputs']) ? '' : 'enctype="multipart/form-data"' !!}
                    >
                        @csrf
                        <input type="hidden" name="product_slug" value="{{ $product['slug'] }}">

                        @if (count($product['variants']) > 1)
                            @include('shop.partials.variant-boxes-styles')
                            <fieldset class="shop-variant-boxes" data-shop-variant-boxes>
                              <legend class="text-sm font-medium">Choose</legend>
                              <div class="shop-variant-boxes__grid" data-count="{{ count($product['variants']) }}">
                                @foreach ($product['variants'] as $v)
                                  <label class="shop-variant-box">
                                    <input type="radio" name="variant_id" value="{{ $v['id'] }}" class="shop-variant-box__input" @checked($selectedVariantId === $v['id']) @disabled(! $variantAvailable($v))>
                                    <span class="shop-variant-box__label">{{ $v['label'] }}</span>
                                    <span class="shop-variant-box__price">{{ \App\Support\ShopMoney::format($v['price_cents'], $currency) }}</span>
                                    @if (! $variantAvailable($v))<span class="shop-variant-box__note">Out of stock</span>@endif
                                  </label>
                                @endforeach
                              </div>
                            </fieldset>
                        @else
                            <input type="hidden" name="variant_id" value="{{ $product['variants'][0]['id'] ?? '' }}">
                        @endif

                        @if (! empty($product['customer_inputs']))
                            @include('shop.partials.customer-inputs-form', [
                                'inputs' => $product['customer_inputs'] ?? [],
                            ])
                        @endif

                        <label class="block">
                            <span class="text-sm font-medium">Quantity</span>
                            <span class="mt-1 block">
                                <x-shop.qty-stepper />
                            </span>
                        </label>

                        <button
                            type="submit"
                            class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                            style="display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 0.75rem 1.5rem; background-color: var(--color-primary); color: var(--color-text-on-primary); border-radius: var(--radius-button); outline-color: var(--color-accent);"
                        >{{ $shopMode === 'quote' ? 'Add to list' : 'Add to cart' }}</button>

                        <p role="status" x-cloak x-show="added" class="mt-2">Added to cart</p>
                        <p role="alert" x-cloak x-show="error" class="mt-2" x-text="error"></p>
                    </form>
                @endif

@php
    $fulfilmentWidget = app(\App\Services\Shop\Fulfilment\FulfilmentService::class)->widgetState($site, request());
@endphp
@if($fulfilmentWidget)@include('shop.partials.fulfilment-widget', ['widget' => $fulfilmentWidget])@endif
                <p class="mt-6">
                    @if (is_string($categorySlug) && $categorySlug !== '' && is_string($categoryName) && $categoryName !== '')
                        <a
                            href="{{ \App\Support\Shop\ShopUrls::collection($categoryPath) }}"
                            class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                            style="color: var(--color-text); min-height: 44px; display: inline-flex; align-items: center; outline-color: var(--color-accent);"
                        >Back to {{ $categoryName }}</a>
                    @else
                        <a
                            href="{{ url('/shop') }}"
                            class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                            style="color: var(--color-text); min-height: 44px; display: inline-flex; align-items: center; outline-color: var(--color-accent);"
                        >Back to shop</a>
                    @endif
                </p>
            </div>
        </div>
    </div>
    @php
        $breadcrumbJsonLd = \App\Support\Shop\ShopJsonLd::breadcrumbList($trail, $canonical);
        $productJsonLd = \App\Support\Shop\ShopJsonLd::product(
            $product,
            $site,
            $canonical,
            in_array($site->shop_mode ?? 'cart', ['cart', 'quote'], true),
        );
    @endphp
    <script type="application/ld+json">{!! \App\Support\Shop\ShopJsonLd::encode($breadcrumbJsonLd) !!}</script>
    <script type="application/ld+json">{!! \App\Support\Shop\ShopJsonLd::encode($productJsonLd) !!}</script>
</x-shop.layout>
