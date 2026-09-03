<?php

use App\Exceptions\Shop\CheckoutException;
use App\Models\Shop\ShippingRate;
use App\Models\Site;
use App\Services\Shop\ShippingService;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->site = Site::factory()->create();
    ShippingRate::create([
        'site_id' => $this->site->id,
        'strategy' => 'flat_with_free_threshold',
        'flat_amount_cents' => 500,
        'free_threshold_cents' => 5000,
        'method_label' => 'Royal Mail 48',
    ]);
    $this->svc = app(ShippingService::class);
});

test('flat rate charged below threshold', function () {
    $result = $this->svc->calculate($this->site->id, subtotalCents: 3000);
    expect($result['cost_cents'])->toBe(500);
    expect($result['method_label'])->toBe('Royal Mail 48');
});

test('free when subtotal at or above threshold', function () {
    $result = $this->svc->calculate($this->site->id, subtotalCents: 5000);
    expect($result['cost_cents'])->toBe(0);
});

test('no shipping rate configured returns zero cost with default label', function () {
    $site = Site::factory()->create();
    $result = $this->svc->calculate($site->id, subtotalCents: 1000);
    expect($result['cost_cents'])->toBe(0);
    expect($result['method_label'])->toBe('Standard delivery');
});

test('flat strategy ignores cart weight and keeps the pinned flat amount', function () {
    $result = $this->svc->calculate($this->site->id, 3000, [
        ['qty' => 4, 'weight_grams' => 5000],
    ]);
    expect($result['cost_cents'])->toBe(500)
        ->and($result['method_label'])->toBe('Royal Mail 48');
});

/**
 * @return array{0: \App\Models\Site, 1: ShippingRate}
 */
function weightTiersFixture(array $rateOverrides = []): array
{
    $site = Site::factory()->create();
    $rate = ShippingRate::create(array_merge([
        'site_id' => $site->id,
        'strategy' => 'weight_tiers',
        'flat_amount_cents' => 9999,
        'free_threshold_cents' => 8000,
        'method_label' => 'Weight',
        'default_weight_grams' => 500,
        'tiers' => [
            ['up_to_grams' => 1000, 'amount_cents' => 495],
            ['up_to_grams' => 2000, 'amount_cents' => 695],
            ['up_to_grams' => null, 'amount_cents' => 995],
        ],
    ], $rateOverrides));

    return [$site, $rate];
}

test('weight_tiers picks the first tier whose up_to_grams is at least the cart weight', function () {
    [$site] = weightTiersFixture();
    $svc = app(ShippingService::class);

    $under = $svc->calculate($site->id, 1000, [['qty' => 1, 'weight_grams' => 999]]);
    $over = $svc->calculate($site->id, 1000, [['qty' => 1, 'weight_grams' => 1001]]);

    expect($under['cost_cents'])->toBe(495)
        ->and($over['cost_cents'])->toBe(695)
        ->and($over['method_label'])->toBe('Weight');
});

test('weight_tiers boundary equality uses the matching tier', function () {
    [$site] = weightTiersFixture();

    $result = app(ShippingService::class)->calculate($site->id, 1000, [
        ['qty' => 2, 'weight_grams' => 500],
    ]);

    expect($result['cost_cents'])->toBe(495);
});

test('weight_tiers null variant weight uses the rate default_weight_grams', function () {
    [$site] = weightTiersFixture(['default_weight_grams' => 750]);

    $result = app(ShippingService::class)->calculate($site->id, 1000, [
        ['qty' => 2, 'weight_grams' => null],
    ]);

    // 2 × 750 = 1500 → second tier
    expect($result['cost_cents'])->toBe(695);
});

test('weight_tiers catch-all null up_to_grams covers anything heavier', function () {
    [$site] = weightTiersFixture();

    $result = app(ShippingService::class)->calculate($site->id, 1000, [
        ['qty' => 1, 'weight_grams' => 5000],
    ]);

    expect($result['cost_cents'])->toBe(995);
});

test('weight_tiers still grants free shipping at or above the threshold', function () {
    [$site] = weightTiersFixture();

    $result = app(ShippingService::class)->calculate($site->id, 8000, [
        ['qty' => 1, 'weight_grams' => 5000],
    ]);

    expect($result['cost_cents'])->toBe(0);
});

test('weight_tiers with no matching band charges the last tier and warns once per site', function () {
    $warnings = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$warnings): void {
        if ($event->level === 'warning') {
            $warnings[] = $event;
        }
    });

    [$site] = weightTiersFixture([
        'free_threshold_cents' => null,
        'tiers' => [
            ['up_to_grams' => 100, 'amount_cents' => 495],
            ['up_to_grams' => 200, 'amount_cents' => 695],
        ],
    ]);
    $svc = app(ShippingService::class);
    $items = [['qty' => 1, 'weight_grams' => 5000]];

    $first = $svc->calculate($site->id, 1000, $items);
    $second = $svc->calculate($site->id, 1000, $items);

    expect($first['cost_cents'])->toBe(695)
        ->and($second['cost_cents'])->toBe(695)
        ->and($first['method_label'])->toBe('Weight')
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0]->message)->toContain('last tier')
        ->and($warnings[0]->context['site_id'] ?? null)->toBe($site->id);
});

test('weight_tiers refuses a quote when cart weight exceeds the ceiling', function () {
    [$site] = weightTiersFixture(['free_threshold_cents' => null]);

    expect(fn () => app(ShippingService::class)->calculate($site->id, 1000, [
        ['qty' => 1, 'weight_grams' => 10_000_001],
    ]))->toThrow(CheckoutException::class, 'too heavy');
});

test('weight_tiers zero-weight cart uses the first band', function () {
    [$site] = weightTiersFixture(['free_threshold_cents' => null]);

    $result = app(ShippingService::class)->calculate($site->id, 1000, [
        ['qty' => 2, 'weight_grams' => 0],
    ]);

    expect($result['cost_cents'])->toBe(495);
});

test('weight_tiers still quotes when cart weight is at the ceiling', function () {
    [$site] = weightTiersFixture(['free_threshold_cents' => null]);

    $result = app(ShippingService::class)->calculate($site->id, 1000, [
        ['qty' => 1, 'weight_grams' => 10_000_000],
    ]);

    expect($result['cost_cents'])->toBe(995);
});
