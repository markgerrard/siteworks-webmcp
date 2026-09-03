<?php

use App\Http\Controllers\Shop\CartController;
use App\Models\Shop\CartItem;
use App\Models\Shop\Product;
use App\Models\Shop\ProductImage;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShippingRate;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Services\Shop\CartService;
use App\Services\Shop\CheckoutService;
use Database\Seeders\Shop\TaxClassSeeder;
use Database\Seeders\Shop\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{site: Site, product: Product, variant: ProductVariant, item: CartItem, sessionId: string}
 */
function cartPageFilledCart(int $qty = 1, bool $withThumb = true): array
{
    test()->seed(TaxClassSeeder::class);
    test()->seed(TaxRateSeeder::class);

    $site = Site::factory()->create([
        'custom_domain' => 'flowers.example',
        'custom_domain_status' => 'active',
    ]);

    ShippingRate::create([
        'site_id' => $site->id,
        'strategy' => 'flat_with_free_threshold',
        'flat_amount_cents' => 350,
        'free_threshold_cents' => null,
        'method_label' => 'Royal Mail 48',
    ]);

    $product = Product::factory()->published()->for($site)->create(['name' => 'Strawberry Conserve', 'slug' => 'conserve']);
    $variant = ProductVariant::factory()->for($product)->create([
        'label' => 'Jar',
        'price_cents' => 595,
        'sku' => 'SC-1',
    ]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 10]);

    if ($withThumb) {
        ProductImage::create([
            'product_id' => $product->id,
            'path' => 'products/conserve-thumb.jpg',
            'sort_order' => 1,
            'alt' => 'Strawberry Conserve',
        ]);
    }

    $sessionId = 'cart-page-session';
    $cart = app(CartService::class)->getOrCreate($site->id, $sessionId);
    $item = app(CartService::class)->addItem($cart, $variant->id, $qty);

    return compact('site', 'product', 'variant', 'item', 'sessionId');
}

function cartPageGet(string $sessionId): string
{
    return test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->get('http://flowers.example/shop/cart')
        ->assertOk()
        ->getContent();
}

/**
 * @return array{action: string, method: string, fields: array<string, string>, inner: string}
 */
function cartPageForm(string $html, string $actionNeedle, string $spoofedMethod): array
{
    preg_match_all('#<form\b([^>]*)>(.*?)</form>#s', $html, $forms, PREG_SET_ORDER);
    expect($forms)->not->toBeEmpty();

    foreach ($forms as $form) {
        $attrs = $form[1];
        if (! str_contains($attrs, $actionNeedle) && ! str_contains($form[2], $actionNeedle)) {
            continue;
        }

        $fields = [];
        preg_match_all('#<input\b[^>]*>#i', $form[2], $inputs);
        foreach ($inputs[0] as $input) {
            if (! preg_match('#\bname="([^"]+)"#', $input, $name)) {
                continue;
            }
            preg_match('#\bvalue="([^"]*)"#', $input, $value);
            $fields[$name[1]] = $value[1] ?? '';
        }

        if (strtoupper($fields['_method'] ?? '') !== strtoupper($spoofedMethod)) {
            continue;
        }

        preg_match('#\baction="([^"]+)"#', $attrs, $action);
        preg_match('#\bmethod="([^"]+)"#i', $attrs, $method);

        return [
            'action' => html_entity_decode($action[1] ?? '', ENT_QUOTES | ENT_HTML5),
            'method' => strtoupper($method[1] ?? 'GET'),
            'fields' => $fields,
            'inner' => $form[2],
        ];
    }

    expect(false)->toBeTrue("no {$spoofedMethod} form matching {$actionNeedle}");

    return ['action' => '', 'method' => 'GET', 'fields' => [], 'inner' => ''];
}

function cartPagePounds(int $cents): string
{
    return '£'.number_format($cents / 100, 2);
}

function cartPageAmount(string $html, string $testId): string
{
    preg_match('/data-testid="'.$testId.'"[^>]*>(.*?)<\/div>/s', $html, $block);
    expect($block)->not->toBeEmpty();
    preg_match('/£\d+\.\d{2}/', $block[1], $amount);
    expect($amount)->not->toBeEmpty();

    return $amount[0];
}

test('an empty cart renders the empty state and no totals', function () {
    $site = Site::factory()->create([
        'custom_domain' => 'flowers.example',
        'custom_domain_status' => 'active',
    ]);
    // The site must BE a shop for its cart to serve; the CART is what is empty here.
    Product::factory()->published()->for($site)->create();

    $html = $this->get('http://flowers.example/shop/cart')->assertOk()->getContent();

    preg_match_all('/<h1\b/i', $html, $headings);
    expect($headings[0])->toHaveCount(1);
    expect($html)->toContain('Your cart is empty.')
        ->and($html)->toContain('Browse the shop')
        ->and($html)->toMatch('/href="[^"]*\/shop"/')
        ->and($html)->not->toContain('data-testid="cart-totals"')
        ->and($html)->not->toContain('bg-blue-600');
});

test('the cart page shows thumbnails, a named remove control, and a qty stepper that PATCHes', function () {
    ['item' => $item, 'sessionId' => $sessionId] = cartPageFilledCart();

    $html = cartPageGet($sessionId);

    preg_match_all('/<h1\b/i', $html, $headings);
    expect($headings[0])->toHaveCount(1);

    expect($html)->toContain('Strawberry Conserve')
        ->and($html)->toMatch('/<img\b[^>]*(alt="Strawberry Conserve"|src="[^"]*conserve-thumb)/i')
        ->and($html)->toMatch('/aria-label="Remove Strawberry Conserve"/')
        ->and($html)->not->toContain('text-red-600')
        ->and($html)->not->toContain('bg-blue-600');

    preg_match('/<button\b[^>]*aria-label="Remove Strawberry Conserve"[^>]*>/i', $html, $remove);
    expect($remove)->not->toBeEmpty();
    expect($remove[0])->toContain('min-width: 44px')
        ->and($remove[0])->toContain('min-height: 44px')
        ->and($remove[0].$html)->toMatch('/focus-visible|outline/');

    $qtyForm = cartPageForm($html, '/shop/cart/'.$item->id, 'PATCH');
    expect($qtyForm['method'])->toBe('POST')
        ->and($qtyForm['fields']['_token'] ?? '')->not->toBeEmpty()
        ->and($qtyForm['fields']['_method'] ?? '')->toBe('PATCH')
        ->and($qtyForm['fields'])->toHaveKey('qty')
        ->and($qtyForm['inner'])->toMatch('/inputmode="numeric"/i')
        ->and($qtyForm['inner'])->toContain('min-width: 44px');

    $qtyForm['fields']['qty'] = '3';

    $this->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->post('http://flowers.example'.$qtyForm['action'], $qtyForm['fields'])
        ->assertRedirect('http://flowers.example/shop/cart');

    expect(CartItem::find($item->id)->qty)->toBe(3);
});

test('the cart remove form names its product, carries csrf, and deletes the line', function () {
    ['item' => $item, 'sessionId' => $sessionId] = cartPageFilledCart();

    $html = cartPageGet($sessionId);
    $removeForm = cartPageForm($html, '/shop/cart/'.$item->id, 'DELETE');

    expect($removeForm['fields']['_token'] ?? '')->not->toBeEmpty()
        ->and($removeForm['fields']['_method'] ?? '')->toBe('DELETE')
        ->and($removeForm['inner'])->toMatch('/aria-label="Remove Strawberry Conserve"/');

    $this->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->post('http://flowers.example'.$removeForm['action'], $removeForm['fields'])
        ->assertRedirect('http://flowers.example/shop/cart');

    expect(CartItem::find($item->id))->toBeNull();
});

test('cart totals match CheckoutService::start arithmetic including VAT', function () {
    ['sessionId' => $sessionId] = cartPageFilledCart();

    $html = cartPageGet($sessionId);

    $cart = app(CartService::class)->getOrCreate(
        Site::where('custom_domain', 'flowers.example')->value('id'),
        $sessionId,
    );
    $cart->load('items.variant.product');

    $order = app(CheckoutService::class)->start($cart, [
        'name' => 'Jane',
        'email' => 'jane@example.com',
        'line1' => '1 High St',
        'city' => 'Lancaster',
        'postcode' => 'LA1 1AA',
        'country_code' => 'GB',
    ]);

    $vatCents = $order->tax_cents + $order->shipping_tax_cents;

    expect($html)->toContain('Subtotal')
        ->and($html)->toContain('Shipping')
        ->and($html)->toContain('VAT')
        ->and($html)->toContain('Total');

    expect(cartPageAmount($html, 'cart-subtotal'))->toBe(cartPagePounds($order->subtotal_cents))
        ->and(cartPageAmount($html, 'cart-shipping'))->toBe(cartPagePounds($order->shipping_cents))
        ->and(cartPageAmount($html, 'cart-vat'))->toBe(cartPagePounds($vatCents))
        ->and(cartPageAmount($html, 'cart-total'))->toBe(cartPagePounds($order->total_cents));

    expect($order->total_cents)->toBe($order->subtotal_cents + $order->shipping_cents)
        ->and($vatCents)->toBeGreaterThan(0)
        ->and($html)->not->toContain(cartPagePounds($order->total_cents + $order->tax_cents));
});

test('a USD cart omits the tax line and notes that no sales tax applies', function () {
    test()->seed(TaxClassSeeder::class);
    test()->seed(TaxRateSeeder::class);

    $host = 'camino-cart.example';
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'shop_currency' => 'USD',
    ]);
    ShippingRate::create([
        'site_id' => $site->id,
        'strategy' => 'flat_with_free_threshold',
        'flat_amount_cents' => 350,
        'free_threshold_cents' => null,
        'method_label' => 'USPS Ground',
    ]);
    $product = Product::factory()->published()->for($site)->create(['name' => 'Sourdough Loaf', 'slug' => 'sourdough']);
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 800, 'label' => 'Loaf']);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 10]);

    $sessionId = 'usd-cart-session';
    $cart = app(CartService::class)->getOrCreate($site->id, $sessionId);
    app(CartService::class)->addItem($cart, $variant->id, 1);

    $html = test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->get("http://{$host}/shop/cart")
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data-testid="checkout-tax-note"')
        ->and($html)->toContain('No sales tax applied.')
        ->and($html)->not->toContain('data-testid="cart-vat"')
        ->and($html)->not->toContain('VAT (included)')
        ->and($html)->not->toContain('>Tax<');
});

test('a line without an image still occupies an aspect-ratio tile', function () {
    ['sessionId' => $sessionId] = cartPageFilledCart(withThumb: false);

    $html = cartPageGet($sessionId);

    expect($html)->toMatch('/aspect-ratio:\s*1\s*\/\s*1/')
        ->and($html)->toMatch('/background-color:\s*var\(--color-surface-alt\)/')
        ->and($html)->not->toContain('bg-gray-100');
});

test('a single-variant cart line does not render the Default placeholder label', function () {
    ['variant' => $variant, 'sessionId' => $sessionId] = cartPageFilledCart();
    $variant->update(['label' => 'Default']);

    $html = cartPageGet($sessionId);

    expect($html)->toContain('Strawberry Conserve')
        ->and($html)->not->toContain('Default ·');
});

test('a multi-variant cart line renders the selected variant label', function () {
    ['product' => $product, 'variant' => $variant, 'sessionId' => $sessionId] = cartPageFilledCart();
    $variant->update(['label' => 'Small']);
    ProductVariant::factory()->for($product)->create(['label' => 'Large', 'price_cents' => 795, 'sku' => 'SC-2']);

    $html = cartPageGet($sessionId);

    expect($html)->toContain('Strawberry Conserve')
        ->and($html)->toContain('Small')
        ->and($html)->not->toContain('Large');
});
