<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;

/**
 * @param  array<string, mixed>  $overrides
 * @param  array<string, mixed>  $jsonOverrides
 */
function shopOgSite(string $host, string $shopMode = 'cart', string $currency = 'GBP', array $overrides = [], array $jsonOverrides = []): Site
{
    $site = Site::factory()->create(array_merge([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'business_name' => 'Bloom & Stem',
        'shop_mode' => $shopMode,
        'shop_currency' => $currency,
        'brand_og_url' => 'https://cdn.example/sites/og-card.png',
    ], $overrides));

    $product = array_replace_recursive([
        'id' => 1,
        'slug' => 'victoria',
        'status' => 'published',
        'primary_category_slug' => 'cakes',
        'price_cents' => 4500,
        'price_display' => $currency === 'USD' ? '$45.00' : '£45.00',
        'price_from' => false,
        'in_stock_any' => true,
        'variant_in_stock' => [1 => true],
        'image_urls' => [
            'thumb' => 'http://images.example/victoria-thumb.jpg',
            'card' => 'http://images.example/victoria-card.jpg',
            'full' => 'http://images.example/victoria-full.jpg',
            'width' => 1600,
            'height' => 1600,
        ],
        'product_card' => ['slug' => 'victoria', 'name' => 'Victoria Sponge', 'price_display' => $currency === 'USD' ? '$45.00' : '£45.00', 'price_from' => false],
        'product_detail' => ['slug' => 'victoria', 'name' => 'Victoria Sponge', 'description' => '<p>A classic sponge.</p>'],
        'variants' => [['id' => 1, 'sku' => 'VS-1', 'label' => 'Std', 'price_cents' => 4500, 'image_urls' => null]],
        'is_ai_seeded' => false,
        'is_ai_reviewed' => false,
    ], $jsonOverrides['product'] ?? []);

    $category = array_replace_recursive([
        'id' => 1,
        'slug' => 'cakes',
        'name' => 'Cakes',
        'path' => 'cakes',
        'description' => 'Celebration cakes',
        'hero_image_url' => 'http://images.example/cakes-thumb.jpg',
        'hero_image_width' => 1400,
        'hero_image_height' => 700,
        'visibility' => 'visible',
        'breadcrumb' => [['name' => 'Cakes', 'path' => 'cakes']],
        'product_slugs' => [$product['slug']],
    ], $jsonOverrides['category'] ?? []);

    $json = [
        'meta' => ['site_id' => $site->id, 'product_count' => 1, 'currency' => $currency],
        'category_paths' => ['cakes' => 'cakes'],
        'categories' => ['cakes' => $category],
        'products' => [$product['slug'] => $product],
        'featured_slugs' => [],
    ];

    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => $json,
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create([
        'site_id' => $site->id,
        'snapshot_id' => $snap->id,
        'updated_at' => now(),
    ]);

    return $site;
}

it('emits product OG tags with the primary image and GBP price on a cart PDP', function () {
    shopOgSite('og-pdp-gbp.example');

    $html = $this->get('http://og-pdp-gbp.example/products/victoria')->assertOk()->getContent();

    expect($html)->toContain('<meta property="og:type" content="product">')
        ->and($html)->toContain('<meta property="og:title" content="Victoria Sponge — Bloom &amp; Stem">')
        ->and($html)->toContain('<meta property="og:description" content="A classic sponge.">')
        ->and($html)->toContain('<meta property="og:image" content="http://images.example/victoria-full.jpg">')
        ->and($html)->toContain('<meta property="og:image:width" content="1600">')
        ->and($html)->toContain('<meta property="og:image:height" content="1600">')
        ->and($html)->toContain('<meta property="og:url" content="http://og-pdp-gbp.example/products/victoria">')
        ->and($html)->toContain('<meta property="og:site_name" content="Bloom &amp; Stem">')
        ->and($html)->toContain('<meta name="twitter:card" content="summary_large_image">')
        ->and($html)->toContain('<meta name="twitter:image" content="http://images.example/victoria-full.jpg">')
        ->and($html)->toContain('<meta property="product:price:amount" content="45.00">')
        ->and($html)->toContain('<meta property="product:price:currency" content="GBP">');
});

it('emits USD product price tags on a cart PDP', function () {
    shopOgSite('og-pdp-usd.example', 'cart', 'USD');

    $html = $this->get('http://og-pdp-usd.example/products/victoria')->assertOk()->getContent();

    expect($html)->toContain('<meta property="product:price:amount" content="45.00">')
        ->and($html)->toContain('<meta property="product:price:currency" content="USD">');
});

it('falls back to the site card when a PDP has no image', function () {
    shopOgSite('og-pdp-noimg.example', 'cart', 'GBP', [], ['product' => ['image_urls' => null]]);

    $html = $this->get('http://og-pdp-noimg.example/products/victoria')->assertOk()->getContent();

    expect($html)->toContain('<meta property="og:image" content="https://cdn.example/sites/og-card.png">')
        ->and($html)->toContain('<meta property="og:image:width" content="1200">')
        ->and($html)->toContain('<meta property="og:image:height" content="630">');
});

it('emits quote-mode PDP tags with a guide price and omits price when price_from', function () {
    shopOgSite('og-pdp-quote.example', 'quote');

    $html = $this->get('http://og-pdp-quote.example/products/victoria')->assertOk()->getContent();

    expect($html)->toContain('<meta property="og:type" content="product">')
        ->and($html)->toContain('<meta property="product:price:amount" content="45.00">')
        ->and($html)->toContain('<meta property="product:price:currency" content="GBP">');

    shopOgSite('og-pdp-quote-from.example', 'quote', 'GBP', [], ['product' => ['price_from' => true, 'product_card' => ['price_from' => true]]]);

    $fromHtml = $this->get('http://og-pdp-quote-from.example/products/victoria')->assertOk()->getContent();

    expect($fromHtml)->toContain('<meta property="og:type" content="product">')
        ->and($fromHtml)->not->toContain('product:price:amount')
        ->and($fromHtml)->not->toContain('product:price:currency');
});

it('treats enquire mode like quote for price tags', function () {
    shopOgSite('og-pdp-enquire.example', 'enquire');

    $html = $this->get('http://og-pdp-enquire.example/products/victoria')->assertOk()->getContent();

    expect($html)->toContain('<meta property="product:price:amount" content="45.00">');

    shopOgSite('og-pdp-enquire-from.example', 'enquire', 'USD', [], ['product' => ['price_from' => true, 'product_card' => ['price_from' => true]]]);

    $fromHtml = $this->get('http://og-pdp-enquire-from.example/products/victoria')->assertOk()->getContent();

    expect($fromHtml)->not->toContain('product:price:amount')
        ->and($fromHtml)->not->toContain('product:price:currency');
});

it('uses the category thumbnail, then first product image, then the site card', function () {
    shopOgSite('og-cat-thumb.example');

    $thumbHtml = $this->get('http://og-cat-thumb.example/collections/cakes')->assertOk()->getContent();

    expect($thumbHtml)->toContain('<meta property="og:image" content="http://images.example/cakes-thumb.jpg">')
        ->and($thumbHtml)->toContain('<meta property="og:image:width" content="1400">')
        ->and($thumbHtml)->toContain('<meta property="og:image:height" content="700">')
        ->and($thumbHtml)->toContain('<meta property="og:type" content="website">')
        ->and($thumbHtml)->toContain('<meta property="og:url" content="http://og-cat-thumb.example/collections/cakes">');

    shopOgSite('og-cat-product.example', 'cart', 'GBP', [], ['category' => ['hero_image_url' => null]]);

    $productHtml = $this->get('http://og-cat-product.example/collections/cakes')->assertOk()->getContent();

    expect($productHtml)->toContain('<meta property="og:image" content="http://images.example/victoria-full.jpg">')
        ->and($productHtml)->toContain('<meta property="og:image:width" content="1600">')
        ->and($productHtml)->toContain('<meta property="og:image:height" content="1600">');

    shopOgSite('og-cat-site.example', 'cart', 'GBP', [], [
        'category' => ['hero_image_url' => null],
        'product' => ['image_urls' => null],
    ]);

    $siteHtml = $this->get('http://og-cat-site.example/collections/cakes')->assertOk()->getContent();

    expect($siteHtml)->toContain('<meta property="og:image" content="https://cdn.example/sites/og-card.png">');
});

it('uses the site card on the shop index', function () {
    shopOgSite('og-index.example');

    $html = $this->get('http://og-index.example/shop')->assertOk()->getContent();

    expect($html)->toContain('<meta property="og:image" content="https://cdn.example/sites/og-card.png">')
        ->and($html)->toContain('<meta property="og:image:width" content="1200">')
        ->and($html)->toContain('<meta property="og:type" content="website">')
        ->and($html)->toContain('<meta property="og:url" content="http://og-index.example/shop">')
        ->and($html)->toContain('<meta name="twitter:card" content="summary_large_image">')
        ->and($html)->not->toContain('product:price');
});

it('emits the square OG card after the landscape card on the shop index', function () {
    shopOgSite('og-index-square.example', 'cart', 'GBP', [
        'brand_og_square_url' => 'https://cdn.example/sites/og-square.png',
    ]);

    $html = $this->get('http://og-index-square.example/shop')->assertOk()->getContent();

    expect($html)->toContain('<meta property="og:image" content="https://cdn.example/sites/og-card.png">')
        ->and($html)->toContain('<meta property="og:image" content="https://cdn.example/sites/og-square.png">')
        ->and($html)->toContain('<meta property="og:image:width" content="1200">')
        ->and($html)->toContain('<meta property="og:image:height" content="630">')
        ->and($html)->toContain('<meta property="og:image:height" content="1200">')
        ->and(strpos($html, 'og-card.png'))->toBeLessThan(strpos($html, 'og-square.png'))
        ->and(substr_count($html, '<meta name="twitter:image"'))->toBe(1);
});

it('emits stored custom OG dimensions on the shop index', function () {
    \Illuminate\Support\Facades\Storage::fake('s3');
    \Illuminate\Support\Facades\Storage::disk('s3')->put('sites/1/brand/og-custom.png', 'custom', 'public');

    shopOgSite('og-index-custom.example', 'cart', 'GBP', [
        'brand_og_custom_path' => 'sites/1/brand/og-custom.png',
        'brand_og_custom_meta' => ['width' => 900, 'height' => 900],
    ]);

    $html = $this->get('http://og-index-custom.example/shop')->assertOk()->getContent();

    expect($html)->toContain('<meta property="og:image:width" content="900">')
        ->and($html)->toContain('<meta property="og:image:height" content="900">')
        ->and($html)->not->toContain('<meta property="og:image:width" content="1200">');
});

/**
 * @return array{0: Site, 1: string}
 */
function shopOgFilledCart(string $host, string $shopMode = 'cart'): array
{
    $site = shopOgSite($host, $shopMode);

    $product = \App\Models\Shop\Product::factory()->published()->for($site)->create([
        'slug' => 'victoria',
        'name' => 'Victoria Sponge',
    ]);
    $variant = \App\Models\Shop\ProductVariant::factory()->for($product)->create([
        'sku' => 'VS-1',
        'label' => 'Std',
        'price_cents' => 4500,
    ]);
    \App\Models\Shop\VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 10]);

    if ($shopMode === 'cart') {
        \App\Models\Shop\ShippingRate::create([
            'site_id' => $site->id,
            'strategy' => 'flat_with_free_threshold',
            'flat_amount_cents' => 350,
            'free_threshold_cents' => null,
            'method_label' => 'Royal Mail 48',
        ]);
    }

    $sessionId = 'og-'.$host;
    $cart = app(\App\Services\Shop\CartService::class)->getOrCreate($site->id, $sessionId);
    app(\App\Services\Shop\CartService::class)->addItem($cart, $variant->id, 1);

    return [$site, $sessionId];
}

function assertShopTransactionalOg(string $html, string $host, string $path): void
{
    expect($html)->toContain('<meta property="og:title" content="Bloom &amp; Stem — Shop">')
        ->and($html)->toContain('<meta property="og:url" content="http://'.$host.$path.'">')
        ->and($html)->toContain('<meta property="og:image" content="https://cdn.example/sites/og-card.png">')
        ->and($html)->not->toContain('name="robots"');
}

it('emits og:title, og:url and the site card on the cart page', function () {
    shopOgSite('og-cart.example');

    $html = $this->get('http://og-cart.example/shop/cart')->assertOk()->getContent();

    assertShopTransactionalOg($html, 'og-cart.example', '/shop/cart');
});

it('emits og:title, og:url and the site card on the search page', function () {
    shopOgSite('og-search.example');

    $html = $this->get('http://og-search.example/shop/search')->assertOk()->getContent();

    assertShopTransactionalOg($html, 'og-search.example', '/shop/search');
});

it('emits og:title, og:url and the site card on the enquire page', function () {
    shopOgSite('og-enquire.example', 'enquire');

    $html = $this->get('http://og-enquire.example/enquire?product=victoria')->assertOk()->getContent();

    assertShopTransactionalOg($html, 'og-enquire.example', '/enquire');
});

it('emits og:title, og:url and the site card on the quote page', function () {
    [, $sessionId] = shopOgFilledCart('og-quote.example', 'quote');

    $html = $this->withCookie(\App\Http\Controllers\Shop\CartController::COOKIE_NAME, $sessionId)
        ->get('http://og-quote.example/shop/quote')
        ->assertOk()
        ->getContent();

    assertShopTransactionalOg($html, 'og-quote.example', '/shop/quote');
});

it('emits og:title, og:url and the site card on the checkout page', function () {
    test()->seed(\Database\Seeders\Shop\TaxClassSeeder::class);
    test()->seed(\Database\Seeders\Shop\TaxRateSeeder::class);
    [, $sessionId] = shopOgFilledCart('og-checkout.example');

    $html = $this->withCookie(\App\Http\Controllers\Shop\CartController::COOKIE_NAME, $sessionId)
        ->get('http://og-checkout.example/shop/checkout')
        ->assertOk()
        ->getContent();

    assertShopTransactionalOg($html, 'og-checkout.example', '/shop/checkout');
});

test('a custom share image replaces the generated cards: no square og:image alongside it', function () {
    \Illuminate\Support\Facades\Storage::fake('s3');
    \Illuminate\Support\Facades\Storage::disk('s3')->put('sites/1/brand/og-custom.png', 'custom', 'public');

    shopOgSite('og-custom-square.example', 'cart', 'GBP', [
        'brand_og_custom_path' => 'sites/1/brand/og-custom.png',
        'brand_og_custom_meta' => ['width' => 900, 'height' => 900],
        'brand_og_square_url' => 'https://cdn.example/sites/og-square-only.png',
    ]);

    $html = $this->get('http://og-custom-square.example/shop')->assertOk()->getContent();

    expect(substr_count($html, 'property="og:image"'))->toBe(1)
        ->and($html)->toContain('og-custom.png')
        ->and($html)->not->toContain('og-square-only.png');
});
