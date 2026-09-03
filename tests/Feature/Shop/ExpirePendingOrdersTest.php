<?php

use App\Enums\Shop\OrderStatus;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShippingRate;
use App\Models\Shop\StockReservation;
use App\Models\Shop\VariantStock;
use App\Models\Site;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('expired pending orders transition to cancelled and release reservations', function () {
    $this->seed(\Database\Seeders\Shop\TaxClassSeeder::class);
    $this->seed(\Database\Seeders\Shop\TaxRateSeeder::class);

    $site = Site::factory()->create();
    ShippingRate::create([
        'site_id' => $site->id, 'strategy' => 'flat_with_free_threshold',
        'flat_amount_cents' => 0, 'free_threshold_cents' => null, 'method_label' => 'Std',
    ]);
    $product = Product::factory()->for($site)->create();
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 1000]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 5]);

    $cart = app(\App\Services\Shop\CartService::class)->getOrCreate($site->id, 'sess-1');
    app(\App\Services\Shop\CartService::class)->addItem($cart, $variant->id, 2);

    $order = app(\App\Services\Shop\CheckoutService::class)->start($cart, [
        'name' => 'X', 'email' => 'x@y.com', 'line1' => '1', 'city' => 'L', 'postcode' => 'P', 'country_code' => 'GB',
    ]);
    // Past the payment cutoff AND past the webhook-delivery grace. These are two
    // different deadlines now (CheckoutService::REAP_GRACE_MINUTES): reaping at
    // expires_at itself cancelled orders whose payment was still in flight, so an
    // order one minute past its cutoff is deliberately NOT reaped any more. The
    // intent of this test — an order genuinely past its deadline is cancelled and
    // its reservation released — is unchanged.
    $order->update(['expires_at' => now()->subHours(2)]);

    $this->artisan('shop:expire-pending-orders')->assertSuccessful();

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Cancelled);

    $res = StockReservation::where('order_id', $order->id)->first();
    expect($res->released_at)->not->toBeNull();
});

test('non-expired pending orders are unaffected', function () {
    $site = Site::factory()->create();
    Order::create([
        'site_id' => $site->id, 'number' => 'X-1',
        'email' => 'x@y.com', 'name' => 'X',
        'status' => OrderStatus::Pending->value, 'refund_status' => 'none',
        'subtotal_cents' => 100, 'shipping_cents' => 0, 'tax_cents' => 0,
        'shipping_tax_cents' => 0, 'total_cents' => 100,
        'tax_country_code' => 'GB', 'shipping_address_json' => [],
        'shipping_method_label' => 'Std',
        'placed_at' => now(), 'expires_at' => now()->addMinutes(5),
    ]);

    $this->artisan('shop:expire-pending-orders')->assertSuccessful();

    $latest = Order::first();
    expect($latest->status)->toBe(OrderStatus::Pending);
});
