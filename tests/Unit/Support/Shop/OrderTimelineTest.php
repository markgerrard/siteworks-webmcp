<?php

use App\Enums\Shop\OrderStatus;
use App\Enums\Shop\RefundStatus;
use App\Models\Shop\Order;
use App\Support\Shop\OrderTimeline;
use Illuminate\Support\Carbon;

function timelineOrder(array $overrides = []): Order
{
    $order = new Order;
    $order->forceFill(array_merge([
        'status' => OrderStatus::Pending,
        'refund_status' => RefundStatus::None,
        'placed_at' => null,
        'paid_at' => null,
        'shipped_at' => null,
        'tracking_number' => null,
        'updated_at' => Carbon::parse('2026-08-20 12:00:00'),
    ], $overrides));

    return $order;
}

function timelineByKey(array $steps): array
{
    return collect($steps)->keyBy('key')->all();
}

test('a pending order with only placed_at has Placed done and later steps greyed', function () {
    $placed = Carbon::parse('2026-03-14 10:00:00');
    $steps = timelineByKey(OrderTimeline::for(timelineOrder([
        'status' => OrderStatus::Pending,
        'placed_at' => $placed,
    ])));

    expect($steps)->toHaveKeys(['placed', 'paid', 'dispatched', 'refunded'])
        ->and($steps['placed']['label'])->toBe('Placed')
        ->and($steps['placed']['done'])->toBeTrue()
        ->and($steps['placed']['at']->eq($placed))->toBeTrue()
        ->and($steps['paid']['label'])->toBe('Paid')
        ->and($steps['paid']['done'])->toBeFalse()
        ->and($steps['paid']['at'])->toBeNull()
        ->and($steps['dispatched']['label'])->toBe('Dispatched')
        ->and($steps['dispatched']['done'])->toBeFalse()
        ->and($steps['dispatched']['at'])->toBeNull()
        ->and($steps['refunded']['label'])->toBe('Refunded')
        ->and($steps['refunded']['done'])->toBeFalse()
        ->and($steps['refunded']['at'])->toBeNull();
});

test('a paid order marks Placed and Paid from their timestamps', function () {
    $placed = Carbon::parse('2026-03-14 10:00:00');
    $paid = Carbon::parse('2026-03-14 10:05:00');
    $steps = timelineByKey(OrderTimeline::for(timelineOrder([
        'status' => OrderStatus::Paid,
        'placed_at' => $placed,
        'paid_at' => $paid,
    ])));

    expect($steps['placed']['done'])->toBeTrue()
        ->and($steps['paid']['done'])->toBeTrue()
        ->and($steps['paid']['at']->eq($paid))->toBeTrue()
        ->and($steps['dispatched']['done'])->toBeFalse()
        ->and($steps['refunded']['done'])->toBeFalse();
});

test('paid_at not status is what marks the Paid step', function () {
    $steps = timelineByKey(OrderTimeline::for(timelineOrder([
        'status' => OrderStatus::Paid,
        'placed_at' => Carbon::parse('2026-03-14 10:00:00'),
        'paid_at' => null,
    ])));

    expect($steps['paid']['done'])->toBeFalse();
});

test('shipped_at marks Dispatched even without a tracking number', function () {
    $shipped = Carbon::parse('2026-03-15 09:00:00');
    $steps = timelineByKey(OrderTimeline::for(timelineOrder([
        'status' => OrderStatus::Shipped,
        'placed_at' => Carbon::parse('2026-03-14 10:00:00'),
        'paid_at' => Carbon::parse('2026-03-14 10:05:00'),
        'shipped_at' => $shipped,
    ])));

    expect($steps['dispatched']['done'])->toBeTrue()
        ->and($steps['dispatched']['at']->eq($shipped))->toBeTrue();
});

test('a tracking number marks Dispatched when shipped_at is missing', function () {
    $steps = timelineByKey(OrderTimeline::for(timelineOrder([
        'status' => OrderStatus::Shipped,
        'placed_at' => Carbon::parse('2026-03-14 10:00:00'),
        'paid_at' => Carbon::parse('2026-03-14 10:05:00'),
        'tracking_number' => 'RM42',
        'shipped_at' => null,
    ])));

    expect($steps['dispatched']['done'])->toBeTrue()
        ->and($steps['dispatched']['at'])->toBeNull();
});

test('refund_status none leaves Refunded greyed after dispatch', function () {
    $steps = timelineByKey(OrderTimeline::for(timelineOrder([
        'status' => OrderStatus::Shipped,
        'refund_status' => RefundStatus::None,
        'placed_at' => Carbon::parse('2026-03-14 10:00:00'),
        'paid_at' => Carbon::parse('2026-03-14 10:05:00'),
        'shipped_at' => Carbon::parse('2026-03-15 09:00:00'),
        'tracking_number' => 'RM42',
    ])));

    expect($steps['placed']['done'])->toBeTrue()
        ->and($steps['paid']['done'])->toBeTrue()
        ->and($steps['dispatched']['done'])->toBeTrue()
        ->and($steps['refunded']['done'])->toBeFalse()
        ->and($steps['refunded']['at'])->toBeNull();
});

test('a partial refund marks Refunded with the order updated_at', function () {
    $updated = Carbon::parse('2026-03-16 11:00:00');
    $steps = timelineByKey(OrderTimeline::for(timelineOrder([
        'status' => OrderStatus::Paid,
        'refund_status' => RefundStatus::Partial,
        'placed_at' => Carbon::parse('2026-03-14 10:00:00'),
        'paid_at' => Carbon::parse('2026-03-14 10:05:00'),
        'updated_at' => $updated,
    ])));

    expect($steps['refunded']['done'])->toBeTrue()
        ->and($steps['refunded']['at']->eq($updated))->toBeTrue()
        ->and($steps['dispatched']['done'])->toBeFalse();
});

test('a full refund marks Refunded', function () {
    $updated = Carbon::parse('2026-03-16 11:00:00');
    $steps = timelineByKey(OrderTimeline::for(timelineOrder([
        'status' => OrderStatus::Paid,
        'refund_status' => RefundStatus::Full,
        'placed_at' => Carbon::parse('2026-03-14 10:00:00'),
        'paid_at' => Carbon::parse('2026-03-14 10:05:00'),
        'updated_at' => $updated,
    ])));

    expect($steps['refunded']['done'])->toBeTrue()
        ->and($steps['refunded']['at']->eq($updated))->toBeTrue();
});

test('a cancelled unpaid order stays at Placed', function () {
    $steps = timelineByKey(OrderTimeline::for(timelineOrder([
        'status' => OrderStatus::Cancelled,
        'placed_at' => Carbon::parse('2026-03-14 10:00:00'),
        'cancelled_at' => Carbon::parse('2026-03-14 10:20:00'),
    ])));

    expect($steps['placed']['done'])->toBeTrue()
        ->and($steps['paid']['done'])->toBeFalse()
        ->and($steps['dispatched']['done'])->toBeFalse()
        ->and($steps['refunded']['done'])->toBeFalse();
});

test('an empty order has every step greyed', function () {
    $steps = timelineByKey(OrderTimeline::for(timelineOrder()));

    expect($steps['placed']['done'])->toBeFalse()
        ->and($steps['paid']['done'])->toBeFalse()
        ->and($steps['dispatched']['done'])->toBeFalse()
        ->and($steps['refunded']['done'])->toBeFalse();
});
