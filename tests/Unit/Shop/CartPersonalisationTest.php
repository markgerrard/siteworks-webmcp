<?php

use App\Models\Shop\CartItem;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Services\Shop\CartService;
use App\Services\Shop\LinePersonalisation;
use Tests\Support\LinePersonalisationFixtures;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->site = Site::factory()->create();
    $this->product = Product::factory()->for($this->site)->create([
        'customer_inputs' => LinePersonalisationFixtures::generic(),
    ]);
    $this->variant = ProductVariant::factory()->for($this->product)->create(['price_cents' => 2500]);
    VariantStock::create(['variant_id' => $this->variant->id, 'on_hand' => 20]);
    $this->svc = app(CartService::class);
});

test('the same variant with the same inputs merges quantity', function () {
    $cart = $this->svc->getOrCreate($this->site->id, 'sess-merge');
    $personalisation = LinePersonalisation::freeze(LinePersonalisationFixtures::generic(), [
        'engraving' => 'Ada',
        'colour' => 'Gold',
    ]);

    $first = $this->svc->addItem($cart, $this->variant->id, 1, $personalisation);
    $second = $this->svc->addItem($cart, $this->variant->id, 2, $personalisation);

    expect($second->id)->toBe($first->id)
        ->and($second->qty)->toBe(3)
        ->and(CartItem::where('cart_id', $cart->id)->count())->toBe(1);
});

test('the same variant with different inputs is a separate line', function () {
    $cart = $this->svc->getOrCreate($this->site->id, 'sess-split');
    $gold = LinePersonalisation::freeze(LinePersonalisationFixtures::generic(), [
        'engraving' => 'Ada',
        'colour' => 'Gold',
    ]);
    $silver = LinePersonalisation::freeze(LinePersonalisationFixtures::generic(), [
        'engraving' => 'Ada',
        'colour' => 'Silver',
    ]);

    $a = $this->svc->addItem($cart, $this->variant->id, 1, $gold);
    $b = $this->svc->addItem($cart, $this->variant->id, 1, $silver);

    expect($a->id)->not->toBe($b->id)
        ->and(CartItem::where('cart_id', $cart->id)->count())->toBe(2)
        ->and($a->personalisation_hash)->not->toBe($b->personalisation_hash);
});

test('a product with no inputs still merges on the empty hash', function () {
    $plain = Product::factory()->for($this->site)->create();
    $variant = ProductVariant::factory()->for($plain)->create(['price_cents' => 1000]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 10]);
    $cart = $this->svc->getOrCreate($this->site->id, 'sess-plain');

    $first = $this->svc->addItem($cart, $variant->id, 1);
    $second = $this->svc->addItem($cart, $variant->id, 1);

    expect($second->id)->toBe($first->id)
        ->and($second->qty)->toBe(2)
        ->and($second->personalisation_hash)->toBe('')
        ->and($second->personalisation)->toBeNull();
});
