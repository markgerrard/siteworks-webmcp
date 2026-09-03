<?php

use App\Enums\Shop\InventoryReason;
use App\Models\Shop\InventoryMovement;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\StockReservation;
use App\Models\Shop\VariantStock;
use App\Services\Shop\StockService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->variant = ProductVariant::factory()->create();
    VariantStock::create(['variant_id' => $this->variant->id, 'on_hand' => 10]);
    $this->svc = app(StockService::class);
});

test('reserve creates reservation when available', function () {
    $reservation = $this->svc->reserve($this->variant->id, 3, cartId: 1);

    expect($reservation)->toBeInstanceOf(StockReservation::class);
    expect($reservation->qty)->toBe(3);
    expect($reservation->cart_id)->toBe(1);
    expect($reservation->order_id)->toBeNull();
    expect($reservation->expires_at->isFuture())->toBeTrue();
});

test('reserve throws when quantity exceeds available', function () {
    expect(fn () => $this->svc->reserve($this->variant->id, 11, cartId: 1))
        ->toThrow(\App\Exceptions\Shop\InsufficientStockException::class);
});

test('reserve counts existing reservations against available', function () {
    $this->svc->reserve($this->variant->id, 8, cartId: 1);
    expect(fn () => $this->svc->reserve($this->variant->id, 3, cartId: 2))
        ->toThrow(\App\Exceptions\Shop\InsufficientStockException::class);
});

test('attachToOrder sets order_id on an active reservation', function () {
    $reservation = $this->svc->reserve($this->variant->id, 3, cartId: 1);
    $this->svc->attachToOrder($reservation->id, orderId: 42);

    $reservation->refresh();
    expect($reservation->order_id)->toBe(42);
});

test('commit decrements on_hand and logs inventory_movement', function () {
    $reservation = $this->svc->reserve($this->variant->id, 3, cartId: 1);
    $this->svc->attachToOrder($reservation->id, orderId: 42);
    $this->svc->commit($reservation->id);

    $stock = VariantStock::where('variant_id', $this->variant->id)->first();
    expect($stock->on_hand)->toBe(7);

    $reservation->refresh();
    expect($reservation->committed_at)->not->toBeNull();

    $movement = InventoryMovement::where('variant_id', $this->variant->id)->first();
    expect($movement->delta)->toBe(-3);
    expect($movement->reason)->toBe(InventoryReason::Sale);
    expect($movement->reference_type)->toBe('order');
    expect($movement->reference_id)->toBe(42);
});

test('release marks reservation released without touching stock', function () {
    $reservation = $this->svc->reserve($this->variant->id, 3, cartId: 1);
    $this->svc->release($reservation->id, 'cart_abandoned');

    $reservation->refresh();
    expect($reservation->released_at)->not->toBeNull();

    $stock = VariantStock::where('variant_id', $this->variant->id)->first();
    expect($stock->on_hand)->toBe(10);
});

test('recordMovement creates an inventory_movements row', function () {
    $this->svc->recordMovement($this->variant->id, delta: 5, reason: InventoryReason::Adjustment, note: 'Restock');

    $movement = InventoryMovement::where('variant_id', $this->variant->id)->first();
    expect($movement->delta)->toBe(5);
    expect($movement->reason)->toBe(InventoryReason::Adjustment);
    expect($movement->note)->toBe('Restock');
});

test('available returns on_hand minus active reservations', function () {
    $this->svc->reserve($this->variant->id, 3, cartId: 1);
    expect($this->svc->available($this->variant->id))->toBe(7);
});
