<?php

use App\Models\Shop\ShippingRate;
use App\Models\Site;
use App\Models\User;
use App\Services\Shop\WeightTiers;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('storefront shipping editor saves strategy, tiers and default weight', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test('shop.shipping-rate-editor', ['siteId' => $site->id])
        ->set('strategy', 'weight_tiers')
        ->set('methodLabel', 'Weight')
        ->set('defaultWeightGrams', 400)
        ->set('freeThresholdCents', 7500)
        ->set('tiers', [
            ['up_to_grams' => 1000, 'amount_cents' => 495],
            ['up_to_grams' => '', 'amount_cents' => 995],
        ])
        ->call('save');

    $rate = ShippingRate::where('site_id', $site->id)->first();

    expect($rate)->not->toBeNull()
        ->and($rate->strategy)->toBe('weight_tiers')
        ->and($rate->method_label)->toBe('Weight')
        ->and($rate->default_weight_grams)->toBe(400)
        ->and($rate->free_threshold_cents)->toBe(7500)
        ->and($rate->tiers)->toBe([
            ['up_to_grams' => 1000, 'amount_cents' => 495],
            ['up_to_grams' => null, 'amount_cents' => 995],
        ]);
});

test('weight_tiers save rejects empty tiers', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test('shop.shipping-rate-editor', ['siteId' => $site->id])
        ->set('strategy', 'weight_tiers')
        ->set('methodLabel', 'Weight')
        ->set('tiers', [])
        ->call('save')
        ->assertHasErrors(['tiers']);

    expect(ShippingRate::query()->where('site_id', $site->id)->exists())->toBeFalse();
});

test('weight_tiers save rejects a missing catch-all', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test('shop.shipping-rate-editor', ['siteId' => $site->id])
        ->set('strategy', 'weight_tiers')
        ->set('methodLabel', 'Weight')
        ->set('tiers', [
            ['up_to_grams' => 1000, 'amount_cents' => 495],
            ['up_to_grams' => 2000, 'amount_cents' => 695],
        ])
        ->call('save')
        ->assertHasErrors(['tiers']);

    expect(ShippingRate::query()->where('site_id', $site->id)->exists())->toBeFalse();
});

test('weight_tiers op schema requires a non-empty list ending in one catch-all', function () {
    expect(WeightTiers::schema()['minItems'])->toBe(1)
        ->and(WeightTiers::schema()['items']['properties']['up_to_grams']['type'])->toBe(['integer', 'null'])
        ->and(WeightTiers::schema()['items']['properties']['amount_cents']['minimum'])->toBe(0)
        ->and(WeightTiers::error([]))->not->toBeNull()
        ->and(WeightTiers::error([
            ['up_to_grams' => 1000, 'amount_cents' => 495],
            ['up_to_grams' => 2000, 'amount_cents' => 695],
        ]))->not->toBeNull()
        ->and(WeightTiers::error([
            ['up_to_grams' => null, 'amount_cents' => 995],
            ['up_to_grams' => 1000, 'amount_cents' => 495],
        ]))->not->toBeNull()
        ->and(WeightTiers::error([
            ['up_to_grams' => 2000, 'amount_cents' => 495],
            ['up_to_grams' => 1000, 'amount_cents' => 695],
            ['up_to_grams' => null, 'amount_cents' => 995],
        ]))->not->toBeNull()
        ->and(WeightTiers::error([
            ['up_to_grams' => 1000, 'amount_cents' => 495],
            ['up_to_grams' => null, 'amount_cents' => 995],
        ]))->toBeNull();
});

test('weight_tiers save rejects a catch-all that is not last', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test('shop.shipping-rate-editor', ['siteId' => $site->id])
        ->set('strategy', 'weight_tiers')
        ->set('methodLabel', 'Weight')
        ->set('tiers', [
            ['up_to_grams' => '', 'amount_cents' => 995],
            ['up_to_grams' => 1000, 'amount_cents' => 495],
        ])
        ->call('save')
        ->assertHasErrors(['tiers']);

    expect(ShippingRate::query()->where('site_id', $site->id)->exists())->toBeFalse();
});
