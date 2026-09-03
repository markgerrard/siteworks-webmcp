<?php

use App\Enums\Shop\OrderStatus;
use App\Enums\Shop\RefundStatus;
use App\Exceptions\Shop\OrderStateException;
use App\Models\Shop\Order;
use App\Models\Site;
use App\Services\Shop\RefundService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function makeOrderForRefund(OrderStatus $status, int $total = 10000): Order
{
    return Order::create([
        'site_id' => Site::factory()->create()->id,
        'number' => 'R-' . rand(1000, 9999),
        'email' => 'x@y.com', 'name' => 'X',
        'status' => $status->value, 'refund_status' => 'none',
        'subtotal_cents' => $total, 'shipping_cents' => 0, 'tax_cents' => 0,
        'shipping_tax_cents' => 0, 'total_cents' => $total,
        'tax_country_code' => 'GB', 'shipping_address_json' => [],
        'shipping_method_label' => 'Std', 'placed_at' => now(),
        'stripe_payment_intent_id' => 'pi_test_123',
    ]);
}

test('full refund sets refund_status=full and refund_amount=total', function () {
    $order = makeOrderForRefund(OrderStatus::Paid, total: 5000);

    $stub = new class {
        public function refund($orderId, int $amount): void { /* no-op */ }
    };

    (new RefundService($stub))->refundFull($order);

    $order->refresh();
    expect($order->refund_status)->toBe(RefundStatus::Full);
    expect($order->refund_amount_cents)->toBe(5000);
});

test('partial refund sets refund_status=partial with amount', function () {
    $order = makeOrderForRefund(OrderStatus::Shipped, total: 10000);

    $stub = new class { public function refund($orderId, int $amount): void {} };

    (new RefundService($stub))->refundPartial($order, 2500);

    $order->refresh();
    expect($order->refund_status)->toBe(RefundStatus::Partial);
    expect($order->refund_amount_cents)->toBe(2500);
});

test('partial refund equal to total is rejected (use refundFull)', function () {
    $order = makeOrderForRefund(OrderStatus::Paid, total: 5000);
    $stub = new class { public function refund($orderId, int $amount): void {} };

    expect(fn () => (new RefundService($stub))->refundPartial($order, 5000))
        ->toThrow(OrderStateException::class);
});

test('cumulative partial refunds cannot exceed total', function () {
    $order = makeOrderForRefund(OrderStatus::Paid, total: 10000);
    $stub = new class { public function refund($orderId, int $amount): void {} };

    $svc = new RefundService($stub);
    $svc->refundPartial($order, 6000);
    expect(fn () => $svc->refundPartial($order->fresh(), 5000))
        ->toThrow(OrderStateException::class);
});
