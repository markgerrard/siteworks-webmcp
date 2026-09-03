<?php

use App\Models\Shop\TaxClass;
use App\Models\Shop\TaxRate;
use App\Services\Shop\TaxService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\Shop\TaxClassSeeder::class);
    $this->seed(\Database\Seeders\Shop\TaxRateSeeder::class);
    $this->svc = app(TaxService::class);
});

test('calculates 20% VAT on standard line item (inclusive pricing)', function () {
    $result = $this->svc->calculateLines([
        ['unit_price_cents' => 1200, 'qty' => 1, 'tax_class_code' => 'standard'],
    ], 'GB');

    expect($result[0]['tax_amount_cents'])->toBe(200); // £12 inc = £10 + £2 VAT
    expect($result[0]['tax_rate_percent'])->toEqual('20.00');
    expect($result[0]['tax_class_code'])->toBe('standard');
    expect($result[0]['line_total_cents'])->toBe(1200);
});

test('zero-rated line item yields zero tax', function () {
    $result = $this->svc->calculateLines([
        ['unit_price_cents' => 1000, 'qty' => 2, 'tax_class_code' => 'zero'],
    ], 'GB');

    expect($result[0]['tax_amount_cents'])->toBe(0);
    expect($result[0]['tax_rate_percent'])->toEqual('0.00');
    expect($result[0]['line_total_cents'])->toBe(2000);
});

test('null tax_class_code falls back to standard', function () {
    $result = $this->svc->calculateLines([
        ['unit_price_cents' => 1200, 'qty' => 1, 'tax_class_code' => null],
    ], 'GB');

    expect($result[0]['tax_class_code'])->toBe('standard');
    expect($result[0]['tax_amount_cents'])->toBe(200);
});

test('multiple lines sum correctly', function () {
    $result = $this->svc->calculateLines([
        ['unit_price_cents' => 1200, 'qty' => 1, 'tax_class_code' => 'standard'],
        ['unit_price_cents' => 500, 'qty' => 2, 'tax_class_code' => 'zero'],
    ], 'GB');

    expect($result[0]['tax_amount_cents'])->toBe(200);
    expect($result[1]['tax_amount_cents'])->toBe(0);
});

test('shippingTaxForCountry returns standard-rate VAT amount', function () {
    $amt = $this->svc->shippingTaxForCountry('GB', shippingCostCents: 600);
    expect($amt)->toBe(100); // £6 inc VAT → £5 net + £1 VAT
});

test('a country with no tax_rates row returns zero tax', function () {
    $result = $this->svc->calculateLines([
        ['unit_price_cents' => 1200, 'qty' => 1, 'tax_class_code' => 'standard'],
    ], 'US');

    expect($result[0]['tax_amount_cents'])->toBe(0)
        ->and($result[0]['tax_rate_percent'])->toEqual('0.00');

    expect($this->svc->shippingTaxForCountry('US', 600))->toBe(0)
        ->and($this->svc->hasRateForCountry('US'))->toBeFalse()
        ->and($this->svc->hasRateForCountry('GB'))->toBeTrue();
});
