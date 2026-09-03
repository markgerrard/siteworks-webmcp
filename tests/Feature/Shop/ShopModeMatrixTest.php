<?php

use App\Enums\AgentRole;
use App\Enums\Shop\OrderStatus;
use App\Http\Controllers\Shop\CartController;
use App\Models\Client;
use App\Models\GeneratedPage;
use App\Models\Shop\CartItem;
use App\Models\Shop\Customer;
use App\Models\Shop\Product;
use App\Models\Shop\StockReservation;
use App\Models\Site;
use App\Models\User;
use App\Services\Shop\CartService;
use App\Services\Site\PageRenderer;
use Livewire\Livewire;

test('sites.shop_mode is a plain string and accepts quote without a migration', function () {
    $site = Site::factory()->create();

    expect($site->shop_mode)->toBe('cart');

    $site->update(['shop_mode' => 'quote']);

    expect($site->fresh()->shop_mode)->toBe('quote');
});

test('cart and enquire cards, PDPs, drawers and cart pages match committed fixtures', function () {
    [$cartSite, $cartProduct, $cartVariants] = shopModeMatrixSite('byte-cart.example', 'cart');
    [$enquireSite] = shopModeMatrixSite('byte-enquire.example', 'enquire');

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

    $cartPdp = shopModeMatrixGet('byte-cart.example', '/products/conserve');
    $enquirePdp = shopModeMatrixGet('byte-enquire.example', '/products/conserve');
    shopModeByteAssert('cart-pdp-add-form.html', shopModeBytePdpAddRegion($cartPdp));
    shopModeByteAssert('enquire-pdp-add-form.html', shopModeBytePdpAddRegion($enquirePdp));

    $sessionId = 'byte-cart-page';
    app(CartService::class)->addItem(
        app(CartService::class)->getOrCreate($cartSite->id, $sessionId),
        $cartVariants[0]->id,
        1,
    );
    $cartPage = test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->get('http://byte-cart.example/shop/cart')
        ->assertOk()
        ->getContent();
    shopModeByteAssert('cart-cart-page.html', $cartPage);

    test()->get('http://byte-enquire.example/shop/cart')->assertNotFound();
    shopModeByteAssert('enquire-cart-page.html', "HTTP 404\n");

    expect($cartProduct->slug)->toBe('conserve');
});

test('product card pills follow the cart / enquire / quote matrix', function (string $mode, string $expect, string $forbidden) {
    shopModeMatrixSite('pill-'.$mode.'.example', $mode);
    $html = shopModeMatrixGet('pill-'.$mode.'.example', '/shop');

    expect($html)->toContain($expect)
        ->and($html)->not->toContain($forbidden);

    if ($mode === 'enquire') {
        expect($html)->not->toContain('/shop/cart/add');
    } else {
        expect($html)->toMatch('/<form\b[^>]*action="[^"]*\/shop\/cart\/add"/i')
            ->and($html)->toContain('data-shop-card-pill');
    }
})->with([
    'cart' => ['cart', '>Add to cart</button>', 'Add to list'],
    'enquire' => ['enquire', '>Enquire</a>', 'Add to list'],
    'quote' => ['quote', '>Add to list</button>', '>Add to cart</button>'],
]);

test('PDP add form follows the cart / enquire / quote matrix', function (string $mode, string $button, bool $stockPill, bool $cartForm) {
    shopModeMatrixSite('pdp-'.$mode.'.example', $mode);
    $html = shopModeMatrixGet('pdp-'.$mode.'.example', '/products/conserve');

    expect($html)->toContain($button);

    if ($stockPill) {
        expect($html)->toContain('In stock');
    } else {
        expect($html)->not->toContain('In stock')
            ->and($html)->not->toContain('Out of stock');
    }

    if ($cartForm) {
        expect($html)->toMatch('/<form\b[^>]*action="[^"]*\/shop\/cart\/add"/i');
    } else {
        expect($html)->not->toMatch('/<form\b[^>]*action="[^"]*\/shop\/cart\/add"/i')
            ->and($html)->toContain('Enquire about this cake');
    }
})->with([
    'cart' => ['cart', '>Add to cart</button>', true, true],
    'enquire' => ['enquire', 'Enquire about this cake', false, false],
    'quote' => ['quote', '>Add to list</button>', false, true],
]);

test('drawer and cart page CTAs follow the cart / enquire / quote matrix', function () {
    shopModeMatrixSite('drawer-cart.example', 'cart');
    shopModeMatrixSite('drawer-enquire.example', 'enquire');
    [$quoteSite, $quoteProduct, $quoteVariants] = shopModeMatrixSite('drawer-quote.example', 'quote');

    $cartHtml = shopModeMatrixGet('drawer-cart.example', '/shop');
    $enquireHtml = shopModeMatrixGet('drawer-enquire.example', '/shop');
    $quoteHtml = shopModeMatrixGet('drawer-quote.example', '/shop');

    expect($cartHtml)->toContain('id="shop-cart-drawer"')
        ->and($cartHtml)->toContain('>Check out</a>')
        ->and($cartHtml)->toContain('data-checkout-url="http://drawer-cart.example/shop/checkout"')
        ->and($cartHtml)->toContain('Taxes included and shipping calculated at checkout.')
        ->and($cartHtml)->not->toContain('Request a quote');

    expect($enquireHtml)->not->toContain('id="shop-cart-drawer"')
        ->and($enquireHtml)->not->toContain('data-shop-cart-control');

    expect($quoteHtml)->toContain('id="shop-cart-drawer"')
        ->and($quoteHtml)->toContain('data-shop-cart-control')
        ->and($quoteHtml)->toContain('>Request a quote</a>')
        ->and($quoteHtml)->toContain('/shop/quote')
        ->and($quoteHtml)->toContain("We'll come back to you with a price and availability.")
        ->and($quoteHtml)->not->toContain('>Check out</a>')
        ->and($quoteHtml)->not->toContain('>Checkout</a>')
        ->and($quoteHtml)->not->toContain('data-checkout-url="http://drawer-quote.example/shop/checkout"')
        ->and($quoteHtml)->toContain('data-checkout-url="http://drawer-quote.example/shop/quote"')
        ->and($quoteHtml)->not->toContain('Taxes included and shipping calculated at checkout.')
        ->and($quoteHtml)->not->toContain('Spend <span x-text="freeShipping');

    $sessionId = 'quote-cart-page';
    app(CartService::class)->addItem(
        app(CartService::class)->getOrCreate($quoteSite->id, $sessionId),
        $quoteVariants[0]->id,
        1,
    );

    $quoteCart = test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->get('http://drawer-quote.example/shop/cart')
        ->assertOk()
        ->getContent();

    expect($quoteCart)->toContain('>Request a quote</a>')
        ->and($quoteCart)->toContain('href="/shop/quote"')
        ->and($quoteCart)->not->toContain('>Checkout</a>')
        ->and($quoteCart)->not->toContain('href="/shop/checkout"');

    expect($quoteProduct->slug)->toBe('conserve');
});

test('shopCartEnabled is true for cart and quote and false for enquire', function (string $mode, bool $enabled) {
    [$site] = shopModeMatrixSite('ctx-'.$mode.'.example', $mode);

    expect(app(PageRenderer::class)->layoutContext($site)['shopCartEnabled'])->toBe($enabled);
})->with([
    'cart' => ['cart', true],
    'enquire' => ['enquire', false],
    'quote' => ['quote', true],
]);

test('checkout routes 404 in quote and enquire while cart JSON stays open only for cart and quote', function (string $mode, bool $cartOpen, bool $checkoutOpen) {
    [$site, $product, $variants] = shopModeMatrixSite('gate-'.$mode.'.example', $mode);

    test()->get('http://gate-'.$mode.'.example/shop')->assertOk();

    $cart = test()->get('http://gate-'.$mode.'.example/shop/cart');
    $checkout = test()->get('http://gate-'.$mode.'.example/shop/checkout');
    $add = test()->postJson('http://gate-'.$mode.'.example/shop/cart/add', [
        'product_slug' => $product->slug,
        'variant_id' => $variants[0]->id,
        'qty' => 1,
    ]);

    if ($cartOpen) {
        $cart->assertOk();
        $add->assertSuccessful();
    } else {
        $cart->assertNotFound();
        $add->assertNotFound();
    }

    if ($checkoutOpen) {
        $checkout->assertRedirect();
    } else {
        $checkout->assertNotFound();
        test()->post('http://gate-'.$mode.'.example/shop/checkout/start', [
            'name' => 'Jane',
            'email' => 'jane@example.com',
            'line1' => '1 High St',
            'city' => 'Lancaster',
            'postcode' => 'LA1 1AA',
            'country_code' => 'GB',
        ])->assertNotFound();
    }

    // Outcome pages are owed only to sites that have taken an order (see the owed-surface test below).
    foreach (['success', 'cancel'] as $outcome) {
        $res = test()->get('http://gate-'.$mode.'.example/shop/checkout/'.$outcome);
        $mode === 'cart' ? $res->assertOk() : $res->assertNotFound();
    }

    expect($site->shop_mode)->toBe($mode);
})->with([
    'cart' => ['cart', true, true],
    'enquire' => ['enquire', false, false],
    'quote' => ['quote', true, false],
]);

test('quote mode never creates a stock reservation', function () {
    [$site, $product, $variants] = shopModeMatrixSite('reserve-quote.example', 'quote');

    test()->postJson('http://reserve-quote.example/shop/cart/add', [
        'product_slug' => $product->slug,
        'variant_id' => $variants[0]->id,
        'qty' => 2,
    ])->assertSuccessful();

    expect(CartItem::query()->where('variant_id', $variants[0]->id)->value('qty'))->toBe(2)
        ->and(StockReservation::query()->count())->toBe(0)
        ->and(CartItem::query()->where('variant_id', $variants[0]->id)->value('reservation_id'))->toBeNull();

    expect($site->shopEnabled())->toBeTrue()
        ->and($site->hasEstablishedShop())->toBeTrue();
});

test('customer account shows enquiries and hides orders in quote mode', function () {
    [$site] = shopModeMatrixSite('acct-quote.example', 'quote');
    $customer = Customer::create([
        'site_id' => $site->id,
        'email' => 'ava@acct-quote.example',
        'email_verified_at' => now(),
        'name' => 'Ava O\'Neil',
    ]);
    auth('customer')->login($customer);

    $dash = test()->get('http://acct-quote.example/shop/account')->assertOk()->getContent();

    expect($dash)->toMatch('/href="[^"]*\/shop\/account\/enquiries"/')
        ->and($dash)->toContain('Enquiries')
        ->and($dash)->not->toMatch('/href="[^"]*\/shop\/account\/orders"/');

    test()->get('http://acct-quote.example/shop/account/enquiries')->assertOk();
    test()->get('http://acct-quote.example/shop/account/orders')->assertNotFound();
});

test('client portal hides orders and still shows enquiries in quote mode', function () {
    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    [$site] = shopModeMatrixSite('portal-quote.example', 'quote');
    $site->update(['client_id' => $tenant->id]);

    $html = test()->actingAs($client)
        ->get(route('client.portal.enquiries', $site))
        ->assertOk()
        ->getContent();

    expect($html)->toContain(route('client.portal.enquiries', $site))
        ->and($html)->not->toContain(route('client.portal.orders', $site));

    test()->actingAs($client)->get(route('client.portal.orders', $site))->assertNotFound();
});

test('agents sidebar hides Orders in quote mode like enquire', function (string $mode, bool $showOrders) {
    $actor = User::factory()->staff(AgentRole::Admin)->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $actor->id,
        'shop_mode' => $mode,
        'business_name' => 'Agents '.$mode,
    ]);
    Product::factory()->published()->for($site)->create();
    GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);

    $html = test()->actingAs($actor)
        ->get(route('sites.section', ['site' => $site, 'section' => 'pages']))
        ->assertOk()
        ->getContent();

    $ordersHref = route('sites.section', ['site' => $site, 'section' => 'orders']);
    $enquiriesHref = route('sites.section', ['site' => $site, 'section' => 'enquiries']);

    expect($html)->toContain($enquiriesHref);

    if ($showOrders) {
        expect($html)->toContain($ordersHref);
    } else {
        expect($html)->not->toContain($ordersHref);
    }
})->with([
    'cart' => ['cart', true],
    'enquire' => ['enquire', false],
    'quote' => ['quote', false],
]);

test('agents shop settings offers quote mode labelled Quote requests (cart, no checkout)', function () {
    $actor = User::factory()->staff(AgentRole::Admin)->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $actor->id,
        'shop_mode' => 'cart',
    ]);

    Livewire::actingAs($actor)
        ->test('site-shop-enabled', ['siteId' => $site->id])
        ->assertSee('Quote requests (cart, no checkout)')
        ->set('shopMode', 'quote');

    expect($site->fresh()->shop_mode)->toBe('quote');
});

test('cart to quote or enquire is refused while an unexpired pending order exists', function (string $target) {
    $actor = User::factory()->staff(AgentRole::Admin)->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $actor->id,
        'shop_mode' => 'cart',
    ]);
    shopModeMatrixOrder($site, ['expires_at' => now()->addHour()]);

    Livewire::actingAs($actor)
        ->test('site-shop-enabled', ['siteId' => $site->id])
        ->set('shopMode', $target)
        ->assertHasErrors(['shopMode' => 'Cannot change shop mode while 1 pending order(s) are unpaid.']);

    expect($site->fresh()->shop_mode)->toBe('cart');
})->with(['quote', 'enquire']);

test('cart to quote is allowed once the pending order is expired or paid', function (string $state) {
    $actor = User::factory()->staff(AgentRole::Admin)->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $actor->id,
        'shop_mode' => 'cart',
    ]);

    if ($state === 'expired') {
        shopModeMatrixOrder($site, ['expires_at' => now()->subMinute()]);
    } else {
        shopModeMatrixOrder($site, ['status' => OrderStatus::Paid, 'expires_at' => now()->addHour()]);
    }

    Livewire::actingAs($actor)
        ->test('site-shop-enabled', ['siteId' => $site->id])
        ->set('shopMode', 'quote')
        ->assertHasNoErrors();

    expect($site->fresh()->shop_mode)->toBe('quote');
})->with(['expired', 'paid']);

test('checkout success is 200 in quote mode for a site that has taken an order', function () {
    [$site] = shopModeMatrixSite('owed-quote.example', 'quote');
    $order = shopModeMatrixOrder($site, [
        'status' => OrderStatus::Paid,
        'number' => 'OWED-0001',
        'expires_at' => null,
    ]);

    test()->withSession(['shop.last_order_id' => $order->id])
        ->get('http://owed-quote.example/shop/checkout/success')
        ->assertOk()
        ->assertSee('OWED-0001');

    test()->get('http://owed-quote.example/shop/checkout/cancel')->assertOk();
    test()->get('http://owed-quote.example/shop/checkout')->assertNotFound();
});

test('customer and client order pages stay reachable in quote mode once an order exists', function () {
    [$site] = shopModeMatrixSite('owed-acct.example', 'quote');
    $customer = Customer::create([
        'site_id' => $site->id,
        'email' => 'ava@owed-acct.example',
        'email_verified_at' => now(),
        'name' => 'Ava O\'Neil',
    ]);
    $order = shopModeMatrixOrder($site, [
        'status' => OrderStatus::Paid,
        'customer_id' => $customer->id,
        'number' => 'ACCT-0009',
        'expires_at' => null,
    ]);

    auth('customer')->login($customer);
    $dash = test()->get('http://owed-acct.example/shop/account')->assertOk()->getContent();
    expect($dash)->toMatch('/href="[^"]*\/shop\/account\/orders"/')
        ->and($dash)->toContain('Enquiries');
    test()->get('http://owed-acct.example/shop/account/orders')->assertOk();
    test()->get('http://owed-acct.example/shop/account/orders/'.$order->id)->assertOk();

    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site->update(['client_id' => $tenant->id]);

    test()->actingAs($client)->get(route('client.portal.orders', $site))->assertOk();
    test()->actingAs($client)
        ->get(route('client.portal.orders.show', ['site' => $site, 'order' => $order->id]))
        ->assertOk();
});

test('merchant order pages stay reachable in quote mode for a site that has taken an order', function () {
    $actor = User::factory()->staff(AgentRole::Admin)->create();
    [$site] = shopModeMatrixSite('owed-agent.example', 'quote');
    $site->update(['created_by_user_id' => $actor->id]);
    GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $order = shopModeMatrixOrder($site, ['status' => OrderStatus::Paid, 'expires_at' => null]);

    test()->actingAs($actor)
        ->get(route('sites.section', ['site' => $site, 'section' => 'orders']))
        ->assertOk();
    test()->actingAs($actor)
        ->get(route('shop.admin.orders.show', ['site' => $site, 'order' => $order->id]))
        ->assertOk();

    $html = test()->actingAs($actor)
        ->get(route('sites.section', ['site' => $site, 'section' => 'pages']))
        ->assertOk()
        ->getContent();

    expect($html)->toContain(route('sites.section', ['site' => $site, 'section' => 'orders']));
});
