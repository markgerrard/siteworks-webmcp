<?php

use App\Http\Controllers\Shop\CartController;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShippingRate;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Services\Shop\CartService;
use Database\Seeders\Shop\TaxClassSeeder;
use Database\Seeders\Shop\TaxRateSeeder;
use Tests\Support\FulfilmentFixtures;

/**
 * @return array{site: Site, host: string, sessionId: string}
 */
function fulfilmentCartSite(string $host, array $fulfilment, int $priceCents = 595): array
{
    test()->seed(TaxClassSeeder::class);
    test()->seed(TaxRateSeeder::class);

    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'shop_currency' => 'GBP',
        'fulfilment' => $fulfilment,
    ]);

    ShippingRate::create([
        'site_id' => $site->id,
        'strategy' => 'flat_with_free_threshold',
        'flat_amount_cents' => 350,
        'free_threshold_cents' => null,
        'method_label' => 'Royal Mail 48',
    ]);

    $product = Product::factory()->published()->for($site)->create(['name' => 'Sourdough loaf']);
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => $priceCents, 'label' => 'Std']);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 50]);

    $sessionId = 'fulfilment-cart-'.$host;
    $cart = app(CartService::class)->getOrCreate($site->id, $sessionId);
    app(CartService::class)->addItem($cart, $variant->id, 1);

    return compact('site', 'host', 'sessionId');
}

function fulfilmentCartGet(string $host, string $sessionId): string
{
    return test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->get('http://'.$host.'/shop/cart')
        ->assertOk()
        ->getContent();
}

function fulfilmentCartCheckoutGet(string $host, string $sessionId, string $query = ''): string
{
    return test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->get('http://'.$host.'/shop/checkout'.$query)
        ->assertOk()
        ->getContent();
}

function fulfilmentCartBlock(string $html, string $testId): string
{
    preg_match('/data-testid="'.$testId.'"[^>]*>(.*?)<\/div>/s', $html, $block);
    expect($block)->not->toBeEmpty();

    return $block[1];
}

function fulfilmentCartAmount(string $html, string $testId): ?string
{
    preg_match('/£(\d+\.\d{2})/', fulfilmentCartBlock($html, $testId), $amount);

    return $amount[0] ?? null;
}

test('a fulfilment cart with nationwide shipping disabled does not quote T19 before a method is remembered', function () {
    ['host' => $host, 'sessionId' => $sessionId] = fulfilmentCartSite('cart-pending.example', FulfilmentFixtures::camino());

    $cart = html_entity_decode(fulfilmentCartGet($host, $sessionId), ENT_QUOTES | ENT_HTML5);
    $checkout = html_entity_decode(fulfilmentCartCheckoutGet($host, $sessionId), ENT_QUOTES | ENT_HTML5);

    expect($cart)->toContain('Delivery calculated at checkout')
        ->and($cart)->not->toContain('Royal Mail 48')
        ->and(fulfilmentCartBlock($cart, 'cart-shipping'))->not->toContain('£')
        ->and(fulfilmentCartAmount($cart, 'cart-total'))->toBe(fulfilmentCartAmount($checkout, 'checkout-total'));
});

test('a fulfilment cart shows the remembered delivery zone fee and matches checkout', function () {
    ['host' => $host, 'sessionId' => $sessionId] = fulfilmentCartSite('cart-zone.example', FulfilmentFixtures::camino());

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->from('http://'.$host.'/shop/cart')
        ->get('http://'.$host.'/shop/fulfilment/check?postcode=SW1A1AA');

    $cart = html_entity_decode(fulfilmentCartGet($host, $sessionId), ENT_QUOTES | ENT_HTML5);
    $checkout = html_entity_decode(
        fulfilmentCartCheckoutGet($host, $sessionId, '?fulfilment_method=delivery'),
        ENT_QUOTES | ENT_HTML5,
    );

    expect($cart)->toContain('Local delivery')
        ->and($cart)->toContain('£4.00')
        ->and($cart)->not->toContain('Royal Mail 48')
        ->and($cart)->not->toContain('Delivery calculated at checkout')
        ->and(fulfilmentCartAmount($cart, 'cart-shipping'))->toBe(fulfilmentCartAmount($checkout, 'checkout-shipping'))
        ->and(fulfilmentCartAmount($cart, 'cart-total'))->toBe(fulfilmentCartAmount($checkout, 'checkout-total'));
});

test('cart and checkout explain a matched delivery zone minimum order', function () {
    ['host' => $host, 'sessionId' => $sessionId] = fulfilmentCartSite('cart-zone-minimum.example', FulfilmentFixtures::camino());

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->from('http://'.$host.'/shop/cart')
        ->get('http://'.$host.'/shop/fulfilment/check?postcode=SW2%201AA');

    $cart = html_entity_decode(fulfilmentCartGet($host, $sessionId), ENT_QUOTES | ENT_HTML5);
    $checkout = html_entity_decode(
        fulfilmentCartCheckoutGet($host, $sessionId, '?postcode=SW2%201AA&fulfilment_method=delivery'),
        ENT_QUOTES | ENT_HTML5,
    );
    $reason = 'Minimum order £15.00 for delivery to Outer';

    expect($cart)->toContain('£6.00')
        ->and($cart)->toContain($reason)
        ->and($checkout)->toContain('£6.00')
        ->and($checkout)->toContain($reason);
});

test('a fulfilment cart shows the remembered collect line and matches checkout', function () {
    ['host' => $host, 'sessionId' => $sessionId] = fulfilmentCartSite('cart-collect.example', FulfilmentFixtures::camino());

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->from('http://'.$host.'/shop/cart')
        ->get('http://'.$host.'/shop/fulfilment/check?postcode=M11AA');

    $cart = html_entity_decode(fulfilmentCartGet($host, $sessionId), ENT_QUOTES | ENT_HTML5);
    $checkout = html_entity_decode(
        fulfilmentCartCheckoutGet($host, $sessionId, '?fulfilment_method=collect'),
        ENT_QUOTES | ENT_HTML5,
    );

    expect($cart)->toContain('Click & collect')
        ->and($cart)->toContain('12 High Street')
        ->and($cart)->not->toContain('Royal Mail 48')
        ->and($cart)->not->toContain('Delivery calculated at checkout')
        ->and(fulfilmentCartAmount($cart, 'cart-total'))->toBe(fulfilmentCartAmount($checkout, 'checkout-total'));
});

test('a fulfilment cart with nationwide shipping still quotes T19 when no zone is remembered', function () {
    ['host' => $host, 'sessionId' => $sessionId] = fulfilmentCartSite('cart-ship.example', FulfilmentFixtures::florist());

    $cart = html_entity_decode(fulfilmentCartGet($host, $sessionId), ENT_QUOTES | ENT_HTML5);
    $checkout = html_entity_decode(
        fulfilmentCartCheckoutGet($host, $sessionId, '?fulfilment_method=shipping'),
        ENT_QUOTES | ENT_HTML5,
    );

    expect($cart)->toContain('Royal Mail 48')
        ->and($cart)->not->toContain('Delivery calculated at checkout')
        ->and(fulfilmentCartAmount($cart, 'cart-shipping'))->toBe(fulfilmentCartAmount($checkout, 'checkout-shipping'))
        ->and(fulfilmentCartAmount($cart, 'cart-total'))->toBe(fulfilmentCartAmount($checkout, 'checkout-total'));
});
