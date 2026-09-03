<?php

use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\StockReservation;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Services\Shop\CartService;
use App\Services\Shop\StockService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// StockService wrote expires_at on every reservation, but nothing ever read it.
// scopeActive checked only released_at/committed_at, and the sole expiry cron
// (ExpirePendingOrders) walks ORDERS, so it can only release order-attached
// reservations. A shopper who added to cart and walked away held that stock forever.
test('an expired cart reservation no longer withholds stock', function () {
    $site = Site::factory()->create(['custom_domain' => 'flowers.example', 'custom_domain_status' => 'active']);
    $product = Product::factory()->for($site)->create();
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 3000]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 5]);

    $cart = app(CartService::class)->getOrCreate($site->id, 'abandoned-cart');
    app(CartService::class)->addItem($cart, $variant->id, 5);

    $stock = app(StockService::class);
    expect($stock->available($variant->id))->toBe(0, 'all stock should be reserved while the cart is live');

    // The shopper walks away. The reservation ages past its TTL.
    StockReservation::where('cart_id', $cart->id)->update(['expires_at' => now()->subMinute()]);

    expect($stock->available($variant->id))->toBe(
        5,
        'an expired cart reservation is still withholding stock — abandoned carts lock inventory forever'
    );
});

// A reservation still inside its TTL must keep holding the stock.
test('a live reservation still withholds stock', function () {
    $site = Site::factory()->create(['custom_domain' => 'flowers.example', 'custom_domain_status' => 'active']);
    $product = Product::factory()->for($site)->create();
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 3000]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 5]);

    $cart = app(CartService::class)->getOrCreate($site->id, 'live-cart');
    app(CartService::class)->addItem($cart, $variant->id, 2);

    expect(app(StockService::class)->available($variant->id))->toBe(3);
});

// An order-attached reservation must NOT be expired by the cart TTL — orders have
// their own lifecycle (ExpirePendingOrders) and a paid order's stock is committed.
test('an order-attached reservation is not released by cart-reservation expiry', function () {
    $site = Site::factory()->create(['custom_domain' => 'flowers.example', 'custom_domain_status' => 'active']);
    $product = Product::factory()->for($site)->create();
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 3000]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 5]);

    $cart = app(CartService::class)->getOrCreate($site->id, 'ordered-cart');
    app(CartService::class)->addItem($cart, $variant->id, 4);

    $res = StockReservation::where('cart_id', $cart->id)->first();
    $res->update(['order_id' => 999, 'expires_at' => now()->subMinute()]);

    expect(app(StockService::class)->available($variant->id))->toBe(
        1,
        'an order-attached reservation was released by the cart TTL'
    );
});
