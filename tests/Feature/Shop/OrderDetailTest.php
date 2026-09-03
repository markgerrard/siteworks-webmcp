<?php

use App\Enums\Shop\OrderStatus;
use App\Enums\Shop\RefundStatus;
use App\Models\Shop\Order;
use App\Models\Site;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => \Illuminate\Support\Facades\Mail::fake());

function adminOrder(OrderStatus $status, int $total = 10000): array
{
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    test()->actingAs($user);
    $order = Order::create([
        'site_id' => $site->id, 'number' => 'O-'.rand(1000, 9999),
        'email' => 'x@y.com', 'name' => 'X',
        'status' => $status->value, 'refund_status' => 'none',
        'subtotal_cents' => $total, 'shipping_cents' => 0, 'tax_cents' => 0,
        'shipping_tax_cents' => 0, 'total_cents' => $total,
        'tax_country_code' => 'GB', 'shipping_address_json' => [],
        'shipping_method_label' => 'Std', 'placed_at' => now(),
        'stripe_payment_intent_id' => 'pi_test',
    ]);

    return [$user, $site, $order];
}

test('can mark shipped from order detail', function () {
    [, $site, $order] = adminOrder(OrderStatus::Paid);

    Livewire::test('shop.order-detail', ['siteId' => $site->id, 'orderId' => $order->id])
        ->set('trackingNumber', 'RM42')
        ->call('markShipped');

    expect($order->fresh()->status)->toBe(OrderStatus::Shipped);
    expect($order->fresh()->tracking_number)->toBe('RM42');
});

test('mark-shipped button disabled when already shipped', function () {
    [, $site, $order] = adminOrder(OrderStatus::Shipped);

    Livewire::test('shop.order-detail', ['siteId' => $site->id, 'orderId' => $order->id])
        ->assertDontSee('Mark shipped');
});

test('partial refund updates refund_status + amount', function () {
    [, $site, $order] = adminOrder(OrderStatus::Paid, total: 5000);

    // Bind a fake refund gateway for tests
    app()->bind(\App\Services\Shop\RefundService::class, function () {
        $stub = new class {
            public function refund($o, int $a): void {}
        };

        return new \App\Services\Shop\RefundService($stub);
    });

    Livewire::test('shop.order-detail', ['siteId' => $site->id, 'orderId' => $order->id])
        ->set('refundAmountPounds', 10)
        ->call('refundPartial');

    $order->refresh();
    expect($order->refund_status)->toBe(RefundStatus::Partial);
    expect($order->refund_amount_cents)->toBe(1000);
});

test('order detail renders for an order with line items (OrderItem::variant must exist as a relation, not only as an eager-load path)', function () {
    [$user, $site, $order] = adminOrder(OrderStatus::Paid);
    $product = \App\Models\Shop\Product::factory()->for($site)->published()->create(['name' => 'Detail Cake']);
    $variant = \App\Models\Shop\ProductVariant::factory()->for($product)->create(['label' => 'Large', 'price_cents' => 2500, 'sku' => 'DC-L']);
    \App\Models\Shop\OrderItem::create([
        'order_id' => $order->id, 'variant_id' => $variant->id, 'product_id' => $product->id,
        'product_name_snapshot' => 'Detail Cake', 'variant_label_snapshot' => 'Large', 'sku_snapshot' => 'DC-L',
        'qty' => 2, 'unit_price_cents' => 2500, 'tax_rate_percent' => 20, 'tax_amount_cents' => 833, 'line_total_cents' => 5000,
    ]);

    Livewire::test('shop.order-detail', ['siteId' => $site->id, 'orderId' => $order->id])
        ->assertOk()
        ->assertSee('Detail Cake')
        ->assertSee('Large');
});
