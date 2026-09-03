<?php

use App\Models\Shop\Cart;
use App\Models\Shop\CartItem;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\StockReservation;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Services\Shop\CartService;
use App\Services\Shop\LinePersonalisation;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->site = Site::factory()->create();
    $this->product = Product::factory()->for($this->site)->create();
    $this->variant = ProductVariant::factory()->for($this->product)->create(['price_cents' => 2500]);
    VariantStock::create(['variant_id' => $this->variant->id, 'on_hand' => 10]);

    $this->svc = app(CartService::class);
});

test('getOrCreate creates cart with session cookie id', function () {
    $cart = $this->svc->getOrCreate($this->site->id, 'sess-abc');
    expect($cart->session_cookie_id)->toBe('sess-abc');
    expect($cart->site_id)->toBe($this->site->id);
});

test('getOrCreate is idempotent per session', function () {
    $a = $this->svc->getOrCreate($this->site->id, 'sess-abc');
    $b = $this->svc->getOrCreate($this->site->id, 'sess-abc');
    expect($a->id)->toBe($b->id);
});

test('addItem creates cart_item with snapshotted price + reserves stock', function () {
    $cart = $this->svc->getOrCreate($this->site->id, 'sess-abc');
    $this->svc->addItem($cart, $this->variant->id, qty: 2);

    $item = CartItem::where('cart_id', $cart->id)->first();
    expect($item->qty)->toBe(2);
    expect($item->unit_price_cents)->toBe(2500);
    expect($item->reservation_id)->not->toBeNull();

    $reservation = StockReservation::find($item->reservation_id);
    expect($reservation->qty)->toBe(2);
});

test('addItem increments qty when variant already in cart (updates reservation)', function () {
    $cart = $this->svc->getOrCreate($this->site->id, 'sess-abc');
    $this->svc->addItem($cart, $this->variant->id, qty: 2);
    $this->svc->addItem($cart, $this->variant->id, qty: 1);

    $item = CartItem::where('cart_id', $cart->id)->first();
    expect($item->qty)->toBe(3);

    $reservation = StockReservation::find($item->reservation_id);
    expect($reservation->qty)->toBe(3);
});

test('removeItem deletes line and releases reservation', function () {
    $cart = $this->svc->getOrCreate($this->site->id, 'sess-abc');
    $this->svc->addItem($cart, $this->variant->id, qty: 2);
    $item = CartItem::where('cart_id', $cart->id)->first();

    $this->svc->removeItem($cart, $item->id);

    expect(CartItem::count())->toBe(0);
    $reservation = StockReservation::find($item->reservation_id);
    expect($reservation->released_at)->not->toBeNull();
});

test('setQty to zero removes item', function () {
    $cart = $this->svc->getOrCreate($this->site->id, 'sess-abc');
    $this->svc->addItem($cart, $this->variant->id, qty: 2);
    $item = CartItem::where('cart_id', $cart->id)->first();

    $this->svc->setQty($cart, $item->id, 0);
    expect(CartItem::count())->toBe(0);
});

test('addItem throws when stock unavailable', function () {
    $cart = $this->svc->getOrCreate($this->site->id, 'sess-abc');
    expect(fn () => $this->svc->addItem($cart, $this->variant->id, qty: 11))
        ->toThrow(\App\Exceptions\Shop\InsufficientStockException::class);
});

test('merging split lines rebuilds the survivor reservation and releases the absorbed reservation', function () {
    $cart = $this->svc->getOrCreate($this->site->id, 'sess-abc');
    $first = LinePersonalisation::freeze([
        ['slug' => 'note', 'label' => 'Note', 'kind' => 'text'],
    ], ['note' => 'First']);
    $second = LinePersonalisation::freeze([
        ['slug' => 'note', 'label' => 'Note', 'kind' => 'text'],
    ], ['note' => 'Second']);

    $survivor = $this->svc->addItem($cart, $this->variant->id, 2, $first);
    $absorbed = $this->svc->addItem($cart, $this->variant->id, 3, $second);

    $this->svc->updatePersonalisation($cart, $absorbed->id, $first);

    $survivor->refresh();
    $absorbedReservation = StockReservation::find($absorbed->reservation_id);
    $survivorReservation = StockReservation::find($survivor->reservation_id);

    expect(CartItem::where('cart_id', $cart->id)->count())->toBe(1)
        ->and($survivor->fresh()->qty)->toBe(5)
        ->and($survivorReservation->qty)->toBe(5)
        ->and($survivorReservation->released_at)->toBeNull()
        ->and($absorbedReservation->released_at)->not->toBeNull();
});
