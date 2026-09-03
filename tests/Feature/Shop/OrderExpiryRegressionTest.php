<?php

use App\Enums\Shop\OrderStatus;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShippingRate;
use App\Models\Shop\StockReservation;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Services\Shop\CartService;
use App\Services\Shop\CheckoutService;
use App\Services\Shop\StockService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function expirySite(string $domain = 'flowers.example'): Site
{
    test()->seed(\Database\Seeders\Shop\TaxClassSeeder::class);
    test()->seed(\Database\Seeders\Shop\TaxRateSeeder::class);
    $site = Site::factory()->create(['custom_domain' => $domain, 'custom_domain_status' => 'active']);
    ShippingRate::create([
        'site_id' => $site->id, 'strategy' => 'flat_with_free_threshold',
        'flat_amount_cents' => 500, 'free_threshold_cents' => null, 'method_label' => 'Std',
    ]);

    return $site;
}

// Expiry must hold at attach time, not only in scopeActive: if attachToOrder accepts an
// expired reservation (or exempts any row that already carries an order_id), checkout can
// resurrect stock a second cart has legitimately taken, available() goes negative and two
// paid orders share one unit.
test('checkout cannot resurrect an expired reservation whose stock was reallocated', function () {
    $site = expirySite();
    $product = Product::factory()->for($site)->create();
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 2500]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 1]);

    $carts = app(CartService::class);
    $stock = app(StockService::class);

    // Cart A takes the only unit.
    $cartA = $carts->getOrCreate($site->id, 'cart-a');
    $carts->addItem($cartA, $variant->id, 1);
    expect($stock->available($variant->id))->toBe(0);

    // A is abandoned and its reservation ages out.
    StockReservation::where('cart_id', $cartA->id)->update(['expires_at' => now()->subMinutes(5)]);
    expect($stock->available($variant->id))->toBe(1, 'expired reservation should free the unit');

    // Cart B legitimately takes the freed unit.
    $cartB = $carts->getOrCreate($site->id, 'cart-b');
    $carts->addItem($cartB, $variant->id, 1);
    expect($stock->available($variant->id))->toBe(0);

    // A now checks out. It must NOT reclaim the unit B holds.
    $address = ['name' => 'A', 'email' => 'a@example.com', 'line1' => '1 St',
        'city' => 'L', 'postcode' => 'L1', 'country_code' => 'GB'];

    try {
        app(CheckoutService::class)->start($cartA->fresh(), $address);
    } catch (\Throwable $e) {
        // Rejecting the stale checkout is an acceptable outcome.
    }

    expect($stock->available($variant->id))->toBeGreaterThanOrEqual(
        -0,
        'availability went negative — an expired reservation was resurrected and the unit is double-sold'
    );
    expect($stock->available($variant->id))->toBe(0);
});

// Cancelling a PENDING order must release its reservation: an order_id exemption
// that only skips cancelled orders would otherwise leave the reservation active
// forever, since ExpirePendingOrders only ever selects status=Pending.
test('cancelling a pending order releases its reservation', function () {
    $site = expirySite();
    $product = Product::factory()->for($site)->create();
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 2500]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 1]);

    $carts = app(CartService::class);
    $cart = $carts->getOrCreate($site->id, 'cart-cancel');
    $carts->addItem($cart, $variant->id, 1);
    $order = app(CheckoutService::class)->start($cart, [
        'name' => 'A', 'email' => 'a@example.com', 'line1' => '1 St',
        'city' => 'L', 'postcode' => 'L1', 'country_code' => 'GB',
    ]);

    expect(app(StockService::class)->available($variant->id))->toBe(0);

    app(\App\Services\Shop\OrderService::class)->cancel($order->fresh());

    expect(app(StockService::class)->available($variant->id))->toBe(
        1,
        'a cancelled pending order still withholds its stock'
    );
});

// Pinning the Stripe session to the order's EXACT expires_at closes the
// late-payment window but not the asynchronous settlement window: a payment
// completed just before the deadline whose webhook lands just after it can still
// find the order cancelled and its stock released, and the webhook's
// `$affected === 0` branch must not treat that as an idempotent no-op — a silent
// 200 on a real payment.
test('a webhook arriving just after the order deadline is still honoured', function () {
    \Illuminate\Support\Facades\Queue::fake();

    $site = expirySite();
    $product = Product::factory()->for($site)->create();
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 2500]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 5]);
    $cart = app(CartService::class)->getOrCreate($site->id, 'cart-late');
    app(CartService::class)->addItem($cart, $variant->id, 1);
    $order = app(CheckoutService::class)->start($cart, [
        'name' => 'A', 'email' => 'a@example.com', 'line1' => '1 St',
        'city' => 'L', 'postcode' => 'L1', 'country_code' => 'GB',
    ]);

    // The customer paid right at the deadline; delivery is a little late.
    $order->update(['expires_at' => now()->subSeconds(30)]);

    // The reaper runs before the webhook arrives.
    \Illuminate\Support\Facades\Artisan::call('shop:expire-pending-orders');

    expect($order->fresh()->status)->toBe(
        OrderStatus::Pending,
        'the order was reaped inside the webhook-delivery grace window'
    );
});

// The reaper must still cancel an order that is genuinely past the grace period.
test('an order past the reap grace is still cancelled', function () {
    $site = expirySite();
    $product = Product::factory()->for($site)->create();
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 2500]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 5]);
    $cart = app(CartService::class)->getOrCreate($site->id, 'cart-old');
    app(CartService::class)->addItem($cart, $variant->id, 1);
    $order = app(CheckoutService::class)->start($cart, [
        'name' => 'A', 'email' => 'a@example.com', 'line1' => '1 St',
        'city' => 'L', 'postcode' => 'L1', 'country_code' => 'GB',
    ]);

    $order->update(['expires_at' => now()->subHours(3)]);
    \Illuminate\Support\Facades\Artisan::call('shop:expire-pending-orders');

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});
