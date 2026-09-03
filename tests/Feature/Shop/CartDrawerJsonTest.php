<?php

use App\Http\Controllers\Shop\CartController;
use App\Models\Shop\CartItem;
use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Shop\ProductImage;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShippingRate;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Services\Shop\CartService;
use App\Support\ShopMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  list<array{name: string, slug: string, price: int, stock?: int, category?: string, variants?: int}>  $catalogue
 * @return array{site: Site, host: string, products: array<string, array{product: Product, variants: list<ProductVariant>}>}
 */
function cartDrawerSite(
    string $host = 'drawer.example',
    array $catalogue = [],
    ?int $freeThresholdCents = null,
    string $shopMode = 'cart',
    string $currency = 'GBP',
): array {
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'business_name' => 'Bloom & Stem',
        'shop_mode' => $shopMode,
        'shop_currency' => $currency,
    ]);

    if ($freeThresholdCents !== null) {
        ShippingRate::create([
            'site_id' => $site->id,
            'strategy' => 'flat_with_free_threshold',
            'flat_amount_cents' => 350,
            'free_threshold_cents' => $freeThresholdCents,
            'method_label' => 'Royal Mail 48',
        ]);
    }

    if ($catalogue === []) {
        $catalogue = [
            ['name' => 'Damson Jam', 'slug' => 'jam', 'price' => 2000, 'stock' => 10, 'category' => 'cakes'],
        ];
    }

    $categories = [];
    $products = [];
    foreach ($catalogue as $row) {
        $product = Product::factory()->published()->for($site)->create([
            'name' => $row['name'],
            'slug' => $row['slug'],
        ]);
        $variantCount = $row['variants'] ?? 1;
        $variants = [];
        for ($i = 0; $i < $variantCount; $i++) {
            $variant = ProductVariant::factory()->for($product)->create([
                'label' => $variantCount === 1 ? 'Jar' : 'Size '.($i + 1),
                'price_cents' => $row['price'] + ($i * 100),
                'sku' => strtoupper($row['slug']).'-'.$i,
            ]);
            VariantStock::create(['variant_id' => $variant->id, 'on_hand' => $row['stock'] ?? 10]);
            $variants[] = $variant;
        }
        if (! empty($row['image'])) {
            ProductImage::create([
                'product_id' => $product->id,
                'path' => $row['image'],
                'sort_order' => 0,
                'alt' => $row['name'],
            ]);
        }
        if (! empty($row['category'])) {
            $slug = $row['category'];
            $categories[$slug] ??= Category::factory()->create([
                'site_id' => $site->id,
                'slug' => $slug,
                'name' => ucfirst($slug),
            ]);
            $product->categories()->attach($categories[$slug]->id, ['is_primary' => true]);
        }
        $products[$row['slug']] = ['product' => $product->fresh(['variants', 'images', 'categories']), 'variants' => $variants];
    }

    return ['site' => $site, 'host' => $host, 'products' => $products];
}

function cartDrawerJsonAdd(string $host, Product $product, ProductVariant $variant, int $qty = 1, ?string $sessionId = null)
{
    $pending = test();
    if ($sessionId) {
        $pending = $pending->withCookie(CartController::COOKIE_NAME, $sessionId);
    }

    return $pending->postJson('http://'.$host.'/shop/cart/add', [
        'product_slug' => $product->slug,
        'variant_id' => $variant->id,
        'qty' => $qty,
    ]);
}

test('json add returns the drawer payload and does not redirect', function () {
    ['host' => $host, 'products' => $products] = cartDrawerSite(catalogue: [
        ['name' => 'Damson Jam', 'slug' => 'jam', 'price' => 2000, 'image' => 'products/jam.jpg'],
    ]);
    $variant = $products['jam']['variants'][0];
    $product = $products['jam']['product'];

    $response = cartDrawerJsonAdd($host, $product, $variant, 2)->assertSuccessful();
    $payload = $response->json();

    expect($payload)->toHaveKeys(['count', 'subtotal_display', 'items', 'upsell', 'free_shipping'])
        ->and($payload['count'])->toBe(2)
        ->and($payload['subtotal_display'])->toBe(ShopMoney::formatWithVat(4000, 'GBP'))
        ->and($payload['free_shipping'])->toBeNull()
        ->and($payload['items'])->toHaveCount(1);

    $item = $payload['items'][0];
    expect($item)->toHaveKeys(['id', 'name', 'variant_label', 'price_display', 'qty', 'image_url', 'product_url'])
        ->and($item['name'])->toBe('Damson Jam')
        ->and($item['variant_label'])->toBe('')
        ->and($item['price_display'])->toBe(ShopMoney::formatWithVat(2000, 'GBP'))
        ->and($item['qty'])->toBe(2)
        ->and($item['product_url'])->toContain('/products/jam')
        ->and($item['image_url'])->not->toBeNull();

    $response->assertHeader('content-type', 'application/json');
    expect($response->headers->get('Location'))->toBeNull();
});

test('non-json add still redirects to the cart page', function () {
    ['host' => $host, 'products' => $products] = cartDrawerSite('html-add.example');
    $variant = $products['jam']['variants'][0];
    $product = $products['jam']['product'];

    test()->post('http://'.$host.'/shop/cart/add', [
        'product_slug' => $product->slug,
        'variant_id' => $variant->id,
        'qty' => 1,
    ])->assertRedirect('http://'.$host.'/shop/cart');

    expect(CartItem::where('variant_id', $variant->id)->exists())->toBeTrue();
});

test('X-Requested-With without Accept json still returns the drawer payload', function () {
    ['host' => $host, 'products' => $products] = cartDrawerSite('xrw.example');
    $variant = $products['jam']['variants'][0];
    $product = $products['jam']['product'];

    $payload = test()->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
        ->post('http://'.$host.'/shop/cart/add', [
            'product_slug' => $product->slug,
            'variant_id' => $variant->id,
            'qty' => 1,
        ])
        ->assertSuccessful()
        ->assertJsonStructure(['count', 'subtotal_display', 'items', 'upsell', 'free_shipping'])
        ->json();

    expect($payload['count'])->toBe(1)
        ->and($payload['items'][0]['name'])->toBe('Damson Jam');
});

test('json show, spoofed patch and spoofed delete re-render the drawer shape', function () {
    ['host' => $host, 'products' => $products, 'site' => $site] = cartDrawerSite('mutate.example');
    $variant = $products['jam']['variants'][0];
    $sessionId = 'drawer-mutate-session';
    $cart = app(CartService::class)->getOrCreate($site->id, $sessionId);
    $item = app(CartService::class)->addItem($cart, $variant->id, 1);
    $itemId = $item->id;

    // getJson() does not send withCookie() values (Laravel json() passes cookies=[]).
    $shown = test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->withHeaders(['Accept' => 'application/json'])
        ->get('http://'.$host.'/shop/cart')
        ->assertSuccessful()
        ->json();
    expect($shown['count'])->toBe(1)
        ->and($shown['items'][0]['id'])->toBe($itemId);

    $patched = test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
        ->post('http://'.$host.'/shop/cart/'.$itemId, ['_method' => 'PATCH', 'qty' => 3])
        ->assertSuccessful()
        ->json();
    expect($patched['count'])->toBe(3)
        ->and($patched['items'][0]['qty'])->toBe(3)
        ->and(CartItem::find($itemId)->qty)->toBe(3);

    $removed = test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
        ->post('http://'.$host.'/shop/cart/'.$itemId, ['_method' => 'DELETE'])
        ->assertSuccessful()
        ->json();
    expect($removed['count'])->toBe(0)
        ->and($removed['items'])->toBe([])
        ->and(CartItem::find($itemId))->toBeNull();
});

test('non-json update and remove still redirect to the cart page', function () {
    ['host' => $host, 'products' => $products] = cartDrawerSite('html-mutate.example');
    $variant = $products['jam']['variants'][0];
    $sessionId = 'html-mutate-session';
    $cart = app(CartService::class)->getOrCreate(
        Site::where('custom_domain', $host)->value('id'),
        $sessionId,
    );
    $item = app(CartService::class)->addItem($cart, $variant->id, 1);

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->patch('http://'.$host.'/shop/cart/'.$item->id, ['qty' => 2])
        ->assertRedirect('http://'.$host.'/shop/cart');
    expect(CartItem::find($item->id)->qty)->toBe(2);

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->delete('http://'.$host.'/shop/cart/'.$item->id)
        ->assertRedirect('http://'.$host.'/shop/cart');
    expect(CartItem::find($item->id))->toBeNull();
});

test('json cart qty update rejects values above 99', function () {
    ['host' => $host, 'products' => $products] = cartDrawerSite('qty-cap.example');
    $variant = $products['jam']['variants'][0];
    $sessionId = 'qty-cap-session';
    $cart = app(CartService::class)->getOrCreate(
        Site::where('custom_domain', $host)->value('id'),
        $sessionId,
    );
    $item = app(CartService::class)->addItem($cart, $variant->id, 1);

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
        ->post('http://'.$host.'/shop/cart/'.$item->id, ['_method' => 'PATCH', 'qty' => 1000])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['qty']);

    expect(CartItem::find($item->id)->qty)->toBe(1);
});

test('non-json GET /shop/cart still renders the HTML cart page', function () {
    ['host' => $host] = cartDrawerSite('html-show.example');

    $html = test()->get('http://'.$host.'/shop/cart')->assertOk()->getContent();

    expect($html)->toContain('Your cart')
        ->and($html)->not->toStartWith('{');
});

test('upsell excludes items already in the cart and prefers the same primary category', function () {
    ['host' => $host, 'products' => $products] = cartDrawerSite('upsell.example', [
        ['name' => 'Damson Jam', 'slug' => 'jam', 'price' => 2000, 'category' => 'cakes'],
        ['name' => 'Bakewell Tart', 'slug' => 'tart', 'price' => 450, 'category' => 'cakes'],
        ['name' => 'Sourdough', 'slug' => 'loaf', 'price' => 400, 'category' => 'breads'],
    ]);

    $payload = cartDrawerJsonAdd($host, $products['jam']['product'], $products['jam']['variants'][0])->json();
    $slugs = collect($payload['upsell'])->pluck('slug')->all();

    expect($slugs)->toContain('tart')
        ->and($slugs)->not->toContain('jam')
        ->and($slugs)->not->toContain('loaf');

    $sessionId = 'upsell-session';
    cartDrawerJsonAdd($host, $products['jam']['product'], $products['jam']['variants'][0], 1, $sessionId);
    $payload = cartDrawerJsonAdd($host, $products['tart']['product'], $products['tart']['variants'][0], 1, $sessionId)->json();
    $slugs = collect($payload['upsell'])->pluck('slug')->all();

    expect($slugs)->toContain('loaf')
        ->and($slugs)->not->toContain('jam')
        ->and($slugs)->not->toContain('tart');
});

test('a multi-variant upsell is not addable in one click', function () {
    ['host' => $host, 'products' => $products] = cartDrawerSite('upsell-multi.example', [
        ['name' => 'Damson Jam', 'slug' => 'jam', 'price' => 2000, 'category' => 'cakes'],
        ['name' => 'Layer Cake', 'slug' => 'layer', 'price' => 4500, 'category' => 'cakes', 'variants' => 2],
    ]);

    $payload = cartDrawerJsonAdd($host, $products['jam']['product'], $products['jam']['variants'][0])->json();
    $offer = collect($payload['upsell'])->firstWhere('slug', 'layer');

    expect($offer)->not->toBeNull()
        ->and($offer['add_variant_id'])->toBeNull();
});

test('free_shipping is present only when a site already has a free-shipping threshold', function () {
    ['host' => $host, 'products' => $products] = cartDrawerSite(
        'ship.example',
        [['name' => 'Damson Jam', 'slug' => 'jam', 'price' => 2000]],
        freeThresholdCents: 5000,
    );

    $payload = cartDrawerJsonAdd($host, $products['jam']['product'], $products['jam']['variants'][0])->json();

    expect($payload['free_shipping'])->toBe([
        'threshold_display' => ShopMoney::format(5000, 'GBP'),
        'remaining_display' => ShopMoney::format(3000, 'GBP'),
        'progress_pct' => 40,
    ]);
});

test('free_shipping progress is present on a weight_tiers rate with a threshold', function () {
    ['host' => $host, 'products' => $products] = cartDrawerSite(
        'tier-ship.example',
        [['name' => 'Damson Jam', 'slug' => 'jam', 'price' => 2000]],
        freeThresholdCents: 5000,
    );

    ShippingRate::query()->where('site_id', $products['jam']['product']->site_id)->first()?->update([
        'strategy' => 'weight_tiers',
        'flat_amount_cents' => 9999,
        'tiers' => [
            ['up_to_grams' => 1000, 'amount_cents' => 495],
            ['up_to_grams' => null, 'amount_cents' => 995],
        ],
        'default_weight_grams' => 500,
    ]);

    $payload = cartDrawerJsonAdd($host, $products['jam']['product'], $products['jam']['variants'][0])->json();

    expect($payload['free_shipping'])->toBe([
        'threshold_display' => ShopMoney::format(5000, 'GBP'),
        'remaining_display' => ShopMoney::format(3000, 'GBP'),
        'progress_pct' => 40,
    ]);
});

test('enquire mode has no cart JSON', function () {
    cartDrawerSite('enquire-json.example', shopMode: 'enquire');

    test()->withHeaders(['Accept' => 'application/json'])
        ->get('http://enquire-json.example/shop/cart')
        ->assertNotFound();
    test()->postJson('http://enquire-json.example/shop/cart/add', [
        'product_slug' => 'jam',
        'variant_id' => 1,
        'qty' => 1,
    ])->assertNotFound();
});

test('json drawer items carry a hostile variant_label raw', function () {
    ['host' => $host, 'products' => $products] = cartDrawerSite('hostile-label.example', catalogue: [
        ['name' => 'Damson Jam', 'slug' => 'jam', 'price' => 2000, 'variants' => 2],
    ]);
    $variant = $products['jam']['variants'][0];
    $hostile = '<img src=x onerror=alert(1)>';
    $variant->update(['label' => $hostile]);

    $payload = cartDrawerJsonAdd($host, $products['jam']['product'], $variant->fresh())->assertSuccessful()->json();

    expect($payload['items'][0]['variant_label'])->toBe($hostile);

    $partial = file_get_contents(resource_path('views/shop/partials/cart-drawer.blade.php'));
    expect($partial)->toContain('x-text="item.variant_label"')
        ->and($partial)->not->toContain('x-html');
});

test('a single-variant drawer line omits the variant label', function () {
    ['host' => $host, 'products' => $products] = cartDrawerSite('single-label.example', catalogue: [
        ['name' => 'Damson Jam', 'slug' => 'jam', 'price' => 2000, 'variants' => 1],
    ]);
    $products['jam']['variants'][0]->update(['label' => 'Default']);

    $payload = cartDrawerJsonAdd($host, $products['jam']['product'], $products['jam']['variants'][0]->fresh())
        ->assertSuccessful()
        ->json();

    expect($payload['items'][0]['variant_label'])->toBe('');

    $partial = file_get_contents(resource_path('views/shop/partials/cart-drawer.blade.php'));
    expect($partial)->toContain('x-show="item.variant_label"')
        ->and($partial)->toContain('x-text="item.variant_label"');
});

test('a multi-variant drawer line shows the selected variant label', function () {
    ['host' => $host, 'products' => $products] = cartDrawerSite('multi-label.example', catalogue: [
        ['name' => 'Layer Cake', 'slug' => 'layer', 'price' => 4500, 'variants' => 2],
    ]);
    $variant = $products['layer']['variants'][1];
    $variant->update(['label' => 'Large']);

    $payload = cartDrawerJsonAdd($host, $products['layer']['product'], $variant->fresh())
        ->assertSuccessful()
        ->json();

    expect($payload['items'][0]['variant_label'])->toBe('Large');
});

test('a placeholder Default label is omitted even when other variants exist', function () {
    ['host' => $host, 'products' => $products] = cartDrawerSite('default-label.example', catalogue: [
        ['name' => 'Layer Cake', 'slug' => 'layer', 'price' => 4500, 'variants' => 2],
    ]);
    $variant = $products['layer']['variants'][0];
    $variant->update(['label' => 'Default']);

    $payload = cartDrawerJsonAdd($host, $products['layer']['product'], $variant->fresh())
        ->assertSuccessful()
        ->json();

    expect($payload['items'][0]['variant_label'])->toBe('');
});

test('gbp drawer line and subtotal prices include inc. VAT', function () {
    ['host' => $host, 'products' => $products] = cartDrawerSite('vat-gbp.example');
    $variant = $products['jam']['variants'][0];
    $product = $products['jam']['product'];

    $payload = cartDrawerJsonAdd($host, $product, $variant)->assertSuccessful()->json();

    expect($payload['items'][0]['price_display'])->toContain('inc. VAT')
        ->and($payload['subtotal_display'])->toContain('inc. VAT')
        ->and($payload['items'][0]['price_display'])->toStartWith(ShopMoney::format(2000, 'GBP'))
        ->and($payload['subtotal_display'])->toStartWith(ShopMoney::format(2000, 'GBP'));

    $partial = file_get_contents(resource_path('views/shop/partials/cart-drawer.blade.php'));
    expect($partial)->toContain('x-text="item.price_display"')
        ->and($partial)->toContain('x-text="subtotalDisplay"');
});

test('non-gbp drawer line and subtotal prices omit inc. VAT', function () {
    ['host' => $host, 'products' => $products] = cartDrawerSite('vat-usd.example', currency: 'USD');
    $variant = $products['jam']['variants'][0];
    $product = $products['jam']['product'];

    $payload = cartDrawerJsonAdd($host, $product, $variant)->assertSuccessful()->json();

    expect($payload['items'][0]['price_display'])->not->toContain('inc. VAT')
        ->and($payload['subtotal_display'])->not->toContain('inc. VAT')
        ->and($payload['items'][0]['price_display'])->toBe(ShopMoney::format(2000, 'USD'))
        ->and($payload['subtotal_display'])->toBe(ShopMoney::format(2000, 'USD'));
});

test('json add of more than available stock returns 422 with the unchanged cart payload', function () {
    ['host' => $host, 'products' => $products] = cartDrawerSite('json-stock-add.example', [
        ['name' => 'Damson Jam', 'slug' => 'jam', 'price' => 2000, 'stock' => 1],
    ]);
    $variant = $products['jam']['variants'][0];
    $product = $products['jam']['product'];

    $payload = cartDrawerJsonAdd($host, $product, $variant, 2)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'insufficient_stock')
        ->assertJsonPath('error.message', 'Not enough stock available.')
        ->json();

    expect($payload['cart'])->toHaveKeys(['count', 'subtotal_display', 'items', 'upsell', 'free_shipping'])
        ->and($payload['cart']['count'])->toBe(0)
        ->and($payload['cart']['items'])->toBe([])
        ->and(CartItem::where('variant_id', $variant->id)->exists())->toBeFalse();
});

test('json update past available stock returns 422 with the unchanged cart payload', function () {
    ['host' => $host, 'products' => $products, 'site' => $site] = cartDrawerSite('json-stock-update.example', [
        ['name' => 'Damson Jam', 'slug' => 'jam', 'price' => 2000, 'stock' => 1],
    ]);
    $variant = $products['jam']['variants'][0];
    $sessionId = 'json-stock-update-session';
    $cart = app(CartService::class)->getOrCreate($site->id, $sessionId);
    $item = app(CartService::class)->addItem($cart, $variant->id, 1);

    $payload = test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
        ->post('http://'.$host.'/shop/cart/'.$item->id, ['_method' => 'PATCH', 'qty' => 2])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'insufficient_stock')
        ->assertJsonPath('error.message', 'Not enough stock available.')
        ->json();

    expect($payload['cart']['count'])->toBe(1)
        ->and($payload['cart']['items'])->toHaveCount(1)
        ->and($payload['cart']['items'][0]['id'])->toBe($item->id)
        ->and($payload['cart']['items'][0]['qty'])->toBe(1)
        ->and(CartItem::find($item->id)->qty)->toBe(1);
});

test('json add of an unavailable option returns 422 with the unchanged cart payload', function () {
    ['host' => $host, 'products' => $products] = cartDrawerSite('json-unavailable.example');
    $product = $products['jam']['product'];

    $payload = test()->postJson('http://'.$host.'/shop/cart/add', [
        'product_slug' => $product->slug,
        'variant_id' => 999999,
        'qty' => 1,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'unavailable')
        ->assertJsonPath('error.message', 'That option is not available.')
        ->json();

    expect($payload['cart'])->toHaveKeys(['count', 'subtotal_display', 'items', 'upsell', 'free_shipping'])
        ->and($payload['cart']['count'])->toBe(0)
        ->and($payload['cart']['items'])->toBe([])
        ->and(CartItem::query()->exists())->toBeFalse();
});

test('non-json add of more than available stock still redirects back with the stock error', function () {
    ['host' => $host, 'products' => $products] = cartDrawerSite('html-stock-add.example', [
        ['name' => 'Damson Jam', 'slug' => 'jam', 'price' => 2000, 'stock' => 1],
    ]);
    $variant = $products['jam']['variants'][0];
    $product = $products['jam']['product'];
    $from = 'http://'.$host.'/products/jam';

    test()->from($from)
        ->post('http://'.$host.'/shop/cart/add', [
            'product_slug' => $product->slug,
            'variant_id' => $variant->id,
            'qty' => 2,
        ])
        ->assertRedirect($from);

    expect(CartItem::where('variant_id', $variant->id)->exists())->toBeFalse();
});

test('non-json add of an unavailable option still redirects back with the variant error', function () {
    ['host' => $host, 'products' => $products] = cartDrawerSite('html-unavailable.example');
    $product = $products['jam']['product'];
    $from = 'http://'.$host.'/products/jam';

    test()->from($from)
        ->post('http://'.$host.'/shop/cart/add', [
            'product_slug' => $product->slug,
            'variant_id' => 999999,
            'qty' => 1,
        ])
        ->assertRedirect($from);
});
