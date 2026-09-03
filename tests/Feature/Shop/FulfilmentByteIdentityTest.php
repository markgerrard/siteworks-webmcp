<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Http\Controllers\Shop\CartController;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShippingRate;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Services\Shop\CartService;
use Database\Seeders\Shop\TaxClassSeeder;
use Database\Seeders\Shop\TaxRateSeeder;
use Illuminate\Support\Facades\URL;
use Tests\Support\FulfilmentFixtures;

/**
 * @return array{host: string, sessionId: string}
 */
function fulfilmentByteSite(string $host, ?array $fulfilment, string $shopMode = 'cart'): array
{
    if ($shopMode === 'cart') {
        test()->seed(TaxClassSeeder::class);
        test()->seed(TaxRateSeeder::class);
    }

    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'shop_mode' => $shopMode,
        'shop_currency' => 'GBP',
        'fulfilment' => $fulfilment,
    ]);

    ShippingRate::create([
        'site_id' => $site->id,
        'strategy' => 'flat_with_free_threshold',
        'flat_amount_cents' => 350,
        'method_label' => 'Royal Mail 48',
    ]);

    $product = Product::factory()->published()->for($site)->create(['slug' => 'loaf', 'name' => 'Sourdough loaf']);
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 595, 'label' => 'Std']);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 10]);

    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => [
            'meta' => ['site_id' => $site->id, 'product_count' => 1, 'currency' => 'GBP'],
            'categories' => [],
            'products' => [
                'loaf' => [
                    'id' => $product->id,
                    'slug' => 'loaf',
                    'status' => 'published',
                    'primary_category_slug' => null,
                    'price_cents' => 595,
                    'price_display' => '£5.95',
                    'in_stock_any' => true,
                    'variant_in_stock' => [$variant->id => true],
                    'image_urls' => ['thumb' => '/a.jpg', 'card' => '/a.jpg', 'full' => '/a.jpg'],
                    'product_card' => ['slug' => 'loaf', 'name' => 'Sourdough loaf', 'price_display' => '£5.95'],
                    'product_detail' => ['slug' => 'loaf', 'name' => 'Sourdough loaf', 'description' => 'A loaf'],
                    'variants' => [[
                        'id' => $variant->id, 'sku' => $variant->sku, 'label' => 'Std',
                        'price_cents' => 595, 'image_urls' => null,
                    ]],
                    'is_ai_seeded' => false,
                    'is_ai_reviewed' => false,
                ],
            ],
            'featured_slugs' => [],
        ],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snap->id, 'updated_at' => now()]);

    $sessionId = 'byte-'.$host;
    $cart = app(CartService::class)->getOrCreate($site->id, $sessionId);
    app(CartService::class)->addItem($cart, $variant->id, 1);

    return compact('host', 'sessionId', 'site');
}

function fulfilmentByteGet(string $host, string $sessionId, string $path): string
{
    return test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->get('http://'.$host.$path)
        ->assertOk()
        ->getContent();
}

function fulfilmentByteStrip(string $html, string $host): string
{
    $html = str_replace($host, 'HOST', $html);
    $html = preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value="__CSRF__"', $html) ?? $html;
    $html = preg_replace('/data-csrf="[^"]*"/', 'data-csrf="__CSRF__"', $html) ?? $html;
    $html = preg_replace('/site-[A-Za-z0-9_-]+\.css/', 'site-__HASH__.css', $html) ?? $html;

    return $html;
}

/**
 * PDP body used for byte comparisons. Livewire injects its
 * `<!-- Livewire Styles -->` block and `livewire.min.js` tag at most once
 * per PHP process, so whole-document HTML of two sequential GETs differs
 * whenever a neighbouring test already consumed that injection
 * (D38 / T18). `<main>` sits inside the shop layout, outside head/body-end.
 */
function fulfilmentByteComparable(string $html): string
{
    preg_match('/<main\b[^>]*>.*<\/main>/is', $html, $main);
    expect($main)->not->toBeEmpty();

    return $main[0];
}

test('sites with no fulfilment config omit every fulfilment surface on PDP drawer cart checkout and quote', function () {
    ['host' => $host, 'sessionId' => $sessionId] = fulfilmentByteSite('byte-off.example', null);

    foreach (['/products/loaf', '/shop', '/shop/cart', '/shop/checkout'] as $path) {
        $html = fulfilmentByteGet($host, $sessionId, $path);
        expect($html)->not->toContain('data-testid="fulfilment-widget"')
            ->and($html)->not->toContain('data-testid="fulfilment-method-form"')
            ->and($html)->not->toContain('/shop/fulfilment/check')
            ->and($html)->not->toContain('Check delivery to your postcode');
    }

    ['host' => $quoteHost, 'sessionId' => $quoteSession] = fulfilmentByteSite('byte-quote-off.example', null, 'quote');
    $quote = fulfilmentByteGet($quoteHost, $quoteSession, '/shop/quote');
    expect($quote)->not->toContain('data-testid="quote-fulfilment-fields"')
        ->and($quote)->not->toContain('name="fulfilment_postcode"');
});

test('a neighbour site with fulfilment on does not change a no-config site\'s PDP bytes', function () {
    ['host' => $offHost, 'sessionId' => $offSession] = fulfilmentByteSite('byte-iso-off.example', null);
    $before = fulfilmentByteComparable(fulfilmentByteStrip(
        fulfilmentByteGet($offHost, $offSession, '/products/loaf'),
        $offHost,
    ));

    fulfilmentByteSite('byte-iso-on.example', FulfilmentFixtures::camino());

    $after = fulfilmentByteComparable(fulfilmentByteStrip(
        fulfilmentByteGet($offHost, $offSession, '/products/loaf'),
        $offHost,
    ));

    expect($after)->toBe($before)
        ->and($after)->not->toContain('data-testid="fulfilment-widget"');
});

/**
 * @return array<string, string>
 */
function fulfilmentByteShopSurfaces(string $host, string $sessionId, Site $site, string $quoteHost, string $quoteSession): array
{
    $page = fn (string $h, string $session, string $path): string => fulfilmentByteComparable(fulfilmentByteStrip(
        fulfilmentByteGet($h, $session, $path),
        $h,
    ));

    URL::forceRootUrl('http://'.$host);
    URL::forceScheme('http');
    $drawer = fulfilmentByteStrip(view('shop.partials.cart-drawer', ['site' => $site])->render(), $host);

    return [
        'pdp' => $page($host, $sessionId, '/products/loaf'),
        'drawer' => $drawer,
        'cart' => $page($host, $sessionId, '/shop/cart'),
        'checkout' => $page($host, $sessionId, '/shop/checkout'),
        'quote' => $page($quoteHost, $quoteSession, '/shop/quote'),
    ];
}

test('fulfilment-off PDP drawer cart checkout and quote stay byte-identical after a neighbour is configured', function () {
    ['host' => $host, 'sessionId' => $sessionId, 'site' => $site] = fulfilmentByteSite('byte-iso-all-off.example', null);
    ['host' => $quoteHost, 'sessionId' => $quoteSession] = fulfilmentByteSite('byte-iso-all-quote.example', null, 'quote');

    $before = fulfilmentByteShopSurfaces($host, $sessionId, $site, $quoteHost, $quoteSession);

    fulfilmentByteSite('byte-iso-all-on.example', FulfilmentFixtures::camino());

    $after = fulfilmentByteShopSurfaces($host, $sessionId, $site, $quoteHost, $quoteSession);

    foreach ($before as $surface => $html) {
        expect($after[$surface])->toBe($html, $surface.' drifted after a neighbour site gained fulfilment')
            ->and($html)->not->toContain('data-testid="fulfilment-widget"')
            ->and($html)->not->toContain('data-testid="fulfilment-method-form"')
            ->and($html)->not->toContain('data-testid="quote-fulfilment-fields"');
    }
});

test('shop-mode fixtures stay byte-identical with fulfilment disabled', function () {
    [$cartSite, , $cartVariants] = shopModeMatrixSite('byte-cart.example', 'cart');
    [$enquireSite] = shopModeMatrixSite('byte-enquire.example', 'enquire');
    [$quoteSite, , $quoteVariants] = shopModeMatrixSite('byte-quote.example', 'quote');

    shopModeByteForceHost('byte-cart.example');
    shopModeByteAssert('cart-drawer.html', view('shop.partials.cart-drawer', ['site' => $cartSite])->render());
    shopModeByteAssert('cart-product-card.html', view('shop.partials.product-card', [
        'site' => $cartSite,
        'product' => shopModeByteSnapshotProduct($cartSite),
    ])->render());

    shopModeByteForceHost('byte-enquire.example');
    shopModeByteAssert('enquire-drawer.html', view('shop.partials.cart-drawer', ['site' => $enquireSite])->render());
    shopModeByteAssert('enquire-product-card.html', view('shop.partials.product-card', [
        'site' => $enquireSite,
        'product' => shopModeByteSnapshotProduct($enquireSite),
    ])->render());

    shopModeByteAssert('cart-pdp-add-form.html', shopModeBytePdpAddRegion(
        shopModeMatrixGet('byte-cart.example', '/products/conserve'),
    ));

    $sessionId = 'byte-cart-page';
    app(CartService::class)->addItem(
        app(CartService::class)->getOrCreate($cartSite->id, $sessionId),
        $cartVariants[0]->id,
        1,
    );
    shopModeByteAssert('cart-cart-page.html', test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->get('http://byte-cart.example/shop/cart')
        ->assertOk()
        ->getContent());

    test()->seed(TaxClassSeeder::class);
    test()->seed(TaxRateSeeder::class);

    shopModeByteForceHost('byte-cart.example');
    $checkoutSession = 'byte-cart-checkout';
    app(CartService::class)->addItem(
        app(CartService::class)->getOrCreate($cartSite->id, $checkoutSession),
        $cartVariants[0]->id,
        1,
    );
    shopModeByteAssert('cart-checkout.html', test()->withCookie(CartController::COOKIE_NAME, $checkoutSession)
        ->get('http://byte-cart.example/shop/checkout')
        ->assertOk()
        ->getContent());

    shopModeByteForceHost('byte-quote.example');
    $quoteSession = 'byte-quote-quote';
    app(CartService::class)->addItem(
        app(CartService::class)->getOrCreate($quoteSite->id, $quoteSession),
        $quoteVariants[0]->id,
        1,
    );
    shopModeByteAssert('quote-quote.html', test()->withCookie(CartController::COOKIE_NAME, $quoteSession)
        ->get('http://byte-quote.example/shop/quote')
        ->assertOk()
        ->getContent());
});
