<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Http\Controllers\Shop\CartController;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Services\Shop\CartService;
use Tests\Support\FulfilmentFixtures;

/**
 * @param  array<string, mixed>|null  $fulfilment
 * @return array{site: Site, host: string, sessionId: string}
 */
function fulfilmentWidgetSite(string $host, ?array $fulfilment, string $shopMode = 'cart'): array
{
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'shop_mode' => $shopMode,
        'shop_currency' => 'GBP',
        'fulfilment' => $fulfilment,
    ]);

    $product = Product::factory()->published()->for($site)->create([
        'slug' => 'loaf',
        'name' => 'Sourdough loaf',
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'label' => 'Std',
        'price_cents' => 450,
    ]);
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
                    'price_cents' => 450,
                    'price_display' => '£4.50',
                    'in_stock_any' => true,
                    'variant_in_stock' => [$variant->id => true],
                    'image_urls' => ['thumb' => '/a.jpg', 'card' => '/a.jpg', 'full' => '/a.jpg'],
                    'product_card' => ['slug' => 'loaf', 'name' => 'Sourdough loaf', 'price_display' => '£4.50'],
                    'product_detail' => ['slug' => 'loaf', 'name' => 'Sourdough loaf', 'description' => 'A loaf'],
                    'variants' => [[
                        'id' => $variant->id,
                        'sku' => $variant->sku,
                        'label' => 'Std',
                        'price_cents' => 450,
                        'image_urls' => null,
                    ]],
                    'is_ai_seeded' => false,
                    'is_ai_reviewed' => false,
                ],
            ],
            'featured_slugs' => [],
        ],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create([
        'site_id' => $site->id,
        'snapshot_id' => $snap->id,
        'updated_at' => now(),
    ]);

    $sessionId = 'fulfilment-'.$host;
    $cart = app(CartService::class)->getOrCreate($site->id, $sessionId);
    app(CartService::class)->addItem($cart, $variant->id, 1);

    return compact('site', 'host', 'sessionId');
}

function fulfilmentWidgetGet(string $host, string $sessionId, string $path)
{
    return test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->from('http://'.$host.$path)
        ->get('http://'.$host.$path);
}

test('sites with no fulfilment config omit the widget on PDP, cart and drawer', function () {
    ['host' => $host, 'sessionId' => $sessionId] = fulfilmentWidgetSite('none.example', null);

    foreach (['/products/loaf', '/shop/cart', '/shop'] as $path) {
        $html = fulfilmentWidgetGet($host, $sessionId, $path)->assertOk()->getContent();
        expect($html)->not->toContain('data-testid="fulfilment-widget"')
            ->and($html)->not->toContain('Check delivery to your postcode')
            ->and($html)->not->toContain('/shop/fulfilment/check');
    }
});

test('the cart drawer partial with no fulfilment config emits no widget markup', function () {
    ['site' => $site] = fulfilmentWidgetSite('drawer-none.example', null);

    $html = view('shop.partials.cart-drawer', ['site' => $site])->render();

    expect($html)->not->toContain('data-testid="fulfilment-widget"')
        ->and($html)->not->toContain('/shop/fulfilment/check')
        ->and($html)->toContain("\n            <a\n                :href=\"checkoutUrl\"");
});

test('the widget matrix covers methods on or off against a zone hit or miss', function (string $fixture, string $postcode, array $expect) {
    $config = match ($fixture) {
        'camino' => FulfilmentFixtures::camino(),
        'florist' => FulfilmentFixtures::florist(),
        'shipping' => FulfilmentFixtures::shippingOnly(),
    };
    $host = $fixture.'-'.$expect['case'].'.example';
    ['sessionId' => $sessionId] = fulfilmentWidgetSite($host, $config);

    $check = test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->from('http://'.$host.'/products/loaf')
        ->get('http://'.$host.'/shop/fulfilment/check?postcode='.urlencode($postcode));

    if (($expect['valid'] ?? true) === false) {
        $check->assertRedirect();
        $html = fulfilmentWidgetGet($host, $sessionId, '/products/loaf')->assertOk()->getContent();
        expect($html)->toContain('data-testid="fulfilment-error"')
            ->and($html)->toContain('Enter a valid postcode.');

        return;
    }

    $check->assertRedirect();
    $html = html_entity_decode(
        fulfilmentWidgetGet($host, $sessionId, '/products/loaf')->assertOk()->getContent(),
        ENT_QUOTES | ENT_HTML5,
    );

    expect($html)->toContain('data-testid="fulfilment-widget"')
        ->and($html)->toContain('data-testid="fulfilment-change"');

    if ($expect['hit'] ?? false) {
        expect($html)->toContain($expect['delivery'])
            ->and($html)->not->toContain('Not in our delivery area');
    } elseif (array_key_exists('miss', $expect)) {
        if ($expect['miss'] === null) {
            expect($html)->not->toContain('Not in our delivery area');
        } else {
            expect($html)->toContain($expect['miss']);
        }
    }

    foreach ($expect['lines'] as $line) {
        expect($html)->toContain($line);
    }
    foreach ($expect['absent'] as $needle) {
        expect($html)->not->toContain($needle);
    }
})->with([
    'camino hit' => ['camino', 'SW1A 1AA', [
        'case' => 'hit',
        'hit' => true,
        'delivery' => 'Local delivery to SW1A: £4.00 · next day · free over £40.00',
        'lines' => ['Click & collect · 12 High Street · same day'],
        'absent' => ['we ship nationwide', 'Ships in 3–5 days'],
    ]],
    'camino miss' => ['camino', 'M1 1AA', [
        'case' => 'miss',
        'miss' => 'Not in our delivery area — you can collect',
        'lines' => ['Click & collect · 12 High Street · same day'],
        'absent' => ['we ship nationwide', 'Local delivery to'],
    ]],
    'florist hit' => ['florist', 'M1 1AA', [
        'case' => 'hit',
        'hit' => true,
        'delivery' => 'Local delivery to M1: £5.00 · same day before 12:00',
        'lines' => ['Shipping · Nationwide next-day'],
        'absent' => ['Click & collect', 'you can collect'],
    ]],
    'florist miss' => ['florist', 'SW1A 1AA', [
        'case' => 'miss',
        'miss' => 'Not in our delivery area — we ship nationwide',
        'lines' => ['Shipping · Nationwide next-day'],
        'absent' => ['you can collect', 'Click & collect'],
    ]],
    'shipping only' => ['shipping', 'SW1A 1AA', [
        'case' => 'note',
        'miss' => null,
        'lines' => ['Shipping · Ships in 3–5 days'],
        'absent' => ['Not in our delivery area', 'Click & collect', 'Local delivery to'],
    ]],
]);

test('the widget is GET-able on the cart page and the drawer footer', function () {
    ['host' => $host, 'sessionId' => $sessionId] = fulfilmentWidgetSite('camino-surfaces.example', FulfilmentFixtures::camino());

    $pdp = fulfilmentWidgetGet($host, $sessionId, '/products/loaf')->assertOk()->getContent();
    $cart = fulfilmentWidgetGet($host, $sessionId, '/shop/cart')->assertOk()->getContent();
    $drawer = fulfilmentWidgetGet($host, $sessionId, '/shop')->assertOk()->getContent();

    foreach ([$pdp, $cart, $drawer] as $html) {
        expect($html)->toContain('data-testid="fulfilment-widget"')
            ->and($html)->toContain('method="GET"')
            ->and($html)->toContain('/shop/fulfilment/check')
            ->and($html)->toContain('Check delivery to your postcode');
    }
});

test('a remembered check shows the result and Change restores the form', function () {
    ['host' => $host, 'sessionId' => $sessionId] = fulfilmentWidgetSite('camino-remember.example', FulfilmentFixtures::camino());

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->from('http://'.$host.'/products/loaf')
        ->get('http://'.$host.'/shop/fulfilment/check?postcode=SW1A1AA')
        ->assertRedirect();

    $html = fulfilmentWidgetGet($host, $sessionId, '/products/loaf')->assertOk()->getContent();
    expect($html)->toContain('Local delivery to SW1A')
        ->and($html)->toContain('data-testid="fulfilment-change"');

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->from('http://'.$host.'/products/loaf')
        ->get('http://'.$host.'/shop/fulfilment/check?change=1')
        ->assertRedirect();

    $html = fulfilmentWidgetGet($host, $sessionId, '/products/loaf')->assertOk()->getContent();
    expect($html)->toContain('data-testid="fulfilment-widget-form"')
        ->and($html)->not->toContain('Local delivery to SW1A');
});

test('postcode input is escaped on the result and GB garbage does not 500', function () {
    ['host' => $host, 'sessionId' => $sessionId] = fulfilmentWidgetSite('camino-xss.example', FulfilmentFixtures::camino());

    $xss = test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->from('http://'.$host.'/products/loaf')
        ->get('http://'.$host.'/shop/fulfilment/check?postcode='.urlencode('<script>alert(1)</script>'));
    $xss->assertRedirect();
    $html = fulfilmentWidgetGet($host, $sessionId, '/products/loaf')->assertOk()->getContent();
    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('Enter a valid postcode.');

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->from('http://'.$host.'/products/loaf')
        ->get('http://'.$host.'/shop/fulfilment/check?postcode=!!!')
        ->assertRedirect();
});

test('a remembered widget zone rematches when the fulfilment config changes', function () {
    ['site' => $site, 'host' => $host, 'sessionId' => $sessionId] = fulfilmentWidgetSite('camino-stale.example', FulfilmentFixtures::camino());

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->from('http://'.$host.'/products/loaf')
        ->get('http://'.$host.'/shop/fulfilment/check?postcode=SW1A1AA')
        ->assertRedirect();

    $html = html_entity_decode(
        fulfilmentWidgetGet($host, $sessionId, '/products/loaf')->assertOk()->getContent(),
        ENT_QUOTES | ENT_HTML5,
    );
    expect($html)->toContain('Local delivery to SW1A: £4.00');

    $config = FulfilmentFixtures::camino();
    $config['delivery']['zones'][0]['fee_cents'] = 700;
    $site->update(['fulfilment' => $config]);

    $html = html_entity_decode(
        fulfilmentWidgetGet($host, $sessionId, '/products/loaf')->assertOk()->getContent(),
        ENT_QUOTES | ENT_HTML5,
    );
    expect($html)->toContain('Local delivery to SW1A: £7.00')
        ->and($html)->not->toContain('£4.00');
});

test('fulfilment config does not leak across sites', function () {
    ['host' => $hostA, 'sessionId' => $sessionA] = fulfilmentWidgetSite('iso-a.example', null);
    fulfilmentWidgetSite('iso-b.example', FulfilmentFixtures::camino());

    $html = fulfilmentWidgetGet($hostA, $sessionA, '/products/loaf')->assertOk()->getContent();
    expect($html)->not->toContain('data-testid="fulfilment-widget"')
        ->and($html)->not->toContain('Check delivery to your postcode');
});
