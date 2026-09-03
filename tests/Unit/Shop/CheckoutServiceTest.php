<?php

use App\Enums\Shop\OrderStatus;
use App\Models\Shop\Order;
use App\Models\Shop\OrderItem;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShippingRate;
use App\Models\Shop\StockReservation;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Services\Shop\CartService;
use App\Services\Shop\CheckoutService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\Shop\TaxClassSeeder::class);
    $this->seed(\Database\Seeders\Shop\TaxRateSeeder::class);

    $this->site = Site::factory()->create();
    ShippingRate::create([
        'site_id' => $this->site->id,
        'strategy' => 'flat_with_free_threshold',
        'flat_amount_cents' => 500,
        'free_threshold_cents' => 10000,
        'method_label' => 'Royal Mail 48',
    ]);

    $this->product = Product::factory()->for($this->site)->create(['name' => 'Rose']);
    $this->variant = ProductVariant::factory()->for($this->product)->create(['price_cents' => 2500, 'sku' => 'R1', 'label' => 'Std']);
    VariantStock::create(['variant_id' => $this->variant->id, 'on_hand' => 10]);

    $this->carts = app(CartService::class);
    $this->checkout = app(CheckoutService::class);
});

test('start creates pending order with snapshotted tax + price + shipping', function () {
    $cart = $this->carts->getOrCreate($this->site->id, 'sess-1');
    $this->carts->addItem($cart, $this->variant->id, qty: 2);

    $address = ['name' => 'Jane', 'email' => 'jane@example.com', 'line1' => '1 High St', 'city' => 'Leeds', 'postcode' => 'LS1 1AA', 'country_code' => 'GB'];
    $order = $this->checkout->start($cart, $address);

    expect($order->status)->toBe(OrderStatus::Pending);
    expect($order->subtotal_cents)->toBe(5000);
    expect($order->shipping_cents)->toBe(500);
    expect($order->shipping_method_label)->toBe('Royal Mail 48');
    expect($order->total_cents)->toBe(5500);
    expect($order->number)->toMatch('/[A-Z0-9]+-\d{6}/');
    expect($order->expires_at)->not->toBeNull();

    $item = OrderItem::where('order_id', $order->id)->first();
    expect($item->product_name_snapshot)->toBe('Rose');
    expect($item->sku_snapshot)->toBe('R1');
    expect($item->tax_rate_percent)->toEqual('20.00');
    expect($item->tax_amount_cents)->toBeGreaterThan(0);
});

test('start links cart reservations to the new order', function () {
    $cart = $this->carts->getOrCreate($this->site->id, 'sess-1');
    $this->carts->addItem($cart, $this->variant->id, qty: 2);

    $address = ['name' => 'Jane', 'email' => 'jane@example.com', 'line1' => '1 High St', 'city' => 'Leeds', 'postcode' => 'LS1 1AA', 'country_code' => 'GB'];
    $order = $this->checkout->start($cart, $address);

    $reservation = StockReservation::whereNotNull('order_id')->first();
    expect($reservation->order_id)->toBe($order->id);
});

test('a guest order with a matching customer email is not linked', function () {
    \App\Models\Shop\Customer::create([
        'site_id' => $this->site->id,
        'email' => 'jane@example.com',
    ]);

    $cart = $this->carts->getOrCreate($this->site->id, 'sess-guest');
    $this->carts->addItem($cart, $this->variant->id, qty: 1);

    $order = $this->checkout->start($cart, [
        'name' => 'Jane',
        'email' => 'jane@example.com',
        'line1' => '1 High St',
        'city' => 'Leeds',
        'postcode' => 'LS1 1AA',
        'country_code' => 'GB',
    ]);

    expect($order->customer_id)->toBeNull()
        ->and($order->email)->toBe('jane@example.com');
});

test('a signed-in cart still direct-attaches the order', function () {
    $customer = \App\Models\Shop\Customer::create([
        'site_id' => $this->site->id,
        'email' => 'ava@example.com',
    ]);

    $cart = $this->carts->getOrCreate($this->site->id, 'sess-signed');
    $cart->update(['customer_id' => $customer->id]);
    $this->carts->addItem($cart, $this->variant->id, qty: 1);

    $order = $this->checkout->start($cart->fresh(), [
        'name' => 'Ava',
        'email' => 'other@example.com',
        'line1' => '1 High St',
        'city' => 'Leeds',
        'postcode' => 'LS1 1AA',
        'country_code' => 'GB',
    ]);

    expect($order->customer_id)->toBe($customer->id)
        ->and($order->email)->toBe('other@example.com');
});

test('start throws if cart empty', function () {
    $cart = $this->carts->getOrCreate($this->site->id, 'sess-1');
    $address = ['name' => 'Jane', 'email' => 'jane@example.com', 'line1' => '1', 'city' => 'L', 'postcode' => 'P', 'country_code' => 'GB'];

    expect(fn () => $this->checkout->start($cart, $address))
        ->toThrow(\App\Exceptions\Shop\CheckoutException::class);
});

test('start on a weight_tiers rate stores the tier amount and label', function () {
    $this->variant->update(['weight_grams' => 1500]);
    ShippingRate::query()->where('site_id', $this->site->id)->update([
        'strategy' => 'weight_tiers',
        'flat_amount_cents' => 9999,
        'free_threshold_cents' => null,
        'method_label' => 'Weight',
        'default_weight_grams' => 500,
        'tiers' => [
            ['up_to_grams' => 1000, 'amount_cents' => 495],
            ['up_to_grams' => null, 'amount_cents' => 995],
        ],
    ]);

    $cart = $this->carts->getOrCreate($this->site->id, 'sess-wt');
    $this->carts->addItem($cart, $this->variant->id, qty: 1);

    $order = $this->checkout->start($cart, [
        'name' => 'Jane',
        'email' => 'jane@example.com',
        'line1' => '1 High St',
        'city' => 'Leeds',
        'postcode' => 'LS1 1AA',
        'country_code' => 'GB',
    ]);

    expect($order->shipping_cents)->toBe(995)
        ->and($order->shipping_method_label)->toBe('Weight')
        ->and($order->total_cents)->toBe(2500 + 995);
});
