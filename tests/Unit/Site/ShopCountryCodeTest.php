<?php

use App\Models\Site;

// Rule: the shop currency is authoritative for the fiscal country;
// `sites.country` is a content label and only counts when it agrees with the currency.

test('shopCountryCode accepts an ISO-2 country only when it matches the currency', function () {
    expect(Site::factory()->make(['country' => 'US', 'shop_currency' => 'USD'])->shopCountryCode())->toBe('US')
        ->and(Site::factory()->make(['country' => 'US', 'shop_currency' => 'GBP'])->shopCountryCode())->toBe('GB')
        ->and(Site::factory()->make(['country' => 'gb', 'shop_currency' => 'USD'])->shopCountryCode())->toBe('US')
        ->and(Site::factory()->make(['country' => 'UK', 'shop_currency' => 'GBP'])->shopCountryCode())->toBe('GB');
});

test('shopCountryCode maps stored country names to ISO-2 when the currency agrees', function () {
    expect(Site::factory()->make(['country' => 'United Kingdom', 'shop_currency' => 'GBP'])->shopCountryCode())->toBe('GB')
        ->and(Site::factory()->make(['country' => 'Australia', 'shop_currency' => 'AUD'])->shopCountryCode())->toBe('AU')
        ->and(Site::factory()->make(['country' => 'New Zealand', 'shop_currency' => 'NZD'])->shopCountryCode())->toBe('NZ')
        ->and(Site::factory()->make(['country' => 'Ireland', 'shop_currency' => 'EUR'])->shopCountryCode())->toBe('IE');
});

test('shopCountryCode ignores a country label that disagrees with the currency', function () {
    expect(Site::factory()->make(['country' => 'Australia', 'shop_currency' => 'GBP'])->shopCountryCode())->toBe('GB')
        ->and(Site::factory()->make(['country' => 'Australia', 'shop_currency' => 'USD'])->shopCountryCode())->toBe('US')
        ->and(Site::factory()->make(['country' => 'Australia', 'shop_currency' => 'EUR'])->shopCountryCode())->toBe('IE');
});

test('shopCountryCode falls back to the currency default when country is blank', function () {
    expect(Site::factory()->make(['country' => null, 'shop_currency' => 'USD'])->shopCountryCode())->toBe('US')
        ->and(Site::factory()->make(['country' => '', 'shop_currency' => 'USD'])->shopCountryCode())->toBe('US')
        ->and(Site::factory()->make(['country' => null, 'shop_currency' => 'GBP'])->shopCountryCode())->toBe('GB')
        ->and(Site::factory()->make(['country' => null, 'shop_currency' => null])->shopCountryCode())->toBe('GB');
});
