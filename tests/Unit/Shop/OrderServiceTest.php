<?php

use App\Enums\Shop\OrderStatus;
use App\Exceptions\Shop\OrderStateException;
use App\Models\Shop\Order;
use App\Models\Site;
use App\Services\Shop\OrderService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function makeOrder(OrderStatus $status): Order
{
    return Order::create([
        'site_id' => Site::factory()->create()->id,
        'number' => 'X-' . rand(1000, 9999),
        'email' => 'x@y.com', 'name' => 'X',
        'status' => $status->value, 'refund_status' => 'none',
        'subtotal_cents' => 100, 'shipping_cents' => 0, 'tax_cents' => 0,
        'shipping_tax_cents' => 0, 'total_cents' => 100,
        'tax_country_code' => 'GB', 'shipping_address_json' => [],
        'shipping_method_label' => 'Std', 'placed_at' => now(),
    ]);
}

test('paid order can be marked shipped with tracking info', function () {
    $order = makeOrder(OrderStatus::Paid);

    app(OrderService::class)->markShipped($order, trackingNumber: 'RM123', trackingCarrier: 'Royal Mail');

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Shipped);
    expect($order->tracking_number)->toBe('RM123');
    expect($order->shipped_at)->not->toBeNull();
});

test('cannot mark shipped an already-shipped order', function () {
    $order = makeOrder(OrderStatus::Shipped);
    expect(fn () => app(OrderService::class)->markShipped($order))->toThrow(OrderStateException::class);
});

test('cannot mark shipped a cancelled order', function () {
    $order = makeOrder(OrderStatus::Cancelled);
    expect(fn () => app(OrderService::class)->markShipped($order))->toThrow(OrderStateException::class);
});

test('can cancel a paid order (pre-ship)', function () {
    $order = makeOrder(OrderStatus::Paid);
    app(OrderService::class)->cancel($order);
    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

test('cannot cancel a shipped order', function () {
    $order = makeOrder(OrderStatus::Shipped);
    expect(fn () => app(OrderService::class)->cancel($order))->toThrow(OrderStateException::class);
});

test('cannot un-cancel', function () {
    $order = makeOrder(OrderStatus::Cancelled);
    expect(fn () => app(OrderService::class)->markShipped($order))->toThrow(OrderStateException::class);
});
