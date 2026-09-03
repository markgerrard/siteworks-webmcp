<?php

use App\Services\Shop\Fulfilment\ZoneMatcher;
use App\Support\Postcode\GbPostcodeNormaliser;
use App\Support\Postcode\PassthroughPostcodeNormaliser;

/**
 * @return list<array{name: string, prefixes: list<string>, fee_cents: int, free_over_cents: int|null, lead_time: string, min_order_cents: int|null}>
 */
function zoneMatcherCaminoZones(): array
{
    return [
        [
            'name' => 'Inner',
            'prefixes' => ['SW1A', 'SW1'],
            'fee_cents' => 400,
            'free_over_cents' => 4000,
            'lead_time' => 'next day',
            'min_order_cents' => null,
        ],
        [
            'name' => 'Outer',
            'prefixes' => ['SW'],
            'fee_cents' => 600,
            'free_over_cents' => null,
            'lead_time' => '2 days',
            'min_order_cents' => 1500,
        ],
    ];
}

test('longest prefix wins across overlapping zones', function () {
    $matcher = new ZoneMatcher;
    $gb = new GbPostcodeNormaliser;
    $zones = zoneMatcherCaminoZones();

    $inner = $matcher->match('SW1A1AA', $zones, $gb);
    $outer = $matcher->match('SW21AA', $zones, $gb);

    expect($inner['name'] ?? null)->toBe('Inner')
        ->and($inner['matched_prefix'] ?? null)->toBe('SW1A')
        ->and($outer['name'] ?? null)->toBe('Outer')
        ->and($outer['matched_prefix'] ?? null)->toBe('SW');
});

test('SW1 beats SW when the outward code is SW1', function () {
    $matcher = new ZoneMatcher;
    $hit = $matcher->match('SW11AA', zoneMatcherCaminoZones(), new GbPostcodeNormaliser);

    expect($hit['name'] ?? null)->toBe('Inner')
        ->and($hit['matched_prefix'] ?? null)->toBe('SW1');
});

test('a full-postcode prefix still matches even when it is longer than the outward code', function () {
    $matcher = new ZoneMatcher;
    $zones = [[
        'name' => 'Exact',
        'prefixes' => ['SW1A1AA', 'SW'],
        'fee_cents' => 100,
        'free_over_cents' => null,
        'lead_time' => '',
        'min_order_cents' => null,
    ]];

    $hit = $matcher->match('SW1A1AA', $zones, new GbPostcodeNormaliser);

    expect($hit['name'] ?? null)->toBe('Exact')
        ->and($hit['matched_prefix'] ?? null)->toBe('SW1A1AA');
});

test('matching is case and space insensitive via the normaliser', function () {
    $matcher = new ZoneMatcher;
    $gb = new GbPostcodeNormaliser;
    $zones = zoneMatcherCaminoZones();
    $zones[0]['prefixes'] = ['sw1a', ' sw1 '];

    $hit = $matcher->match($gb->normalise('sw1a 1aa'), $zones, $gb);

    expect($hit['name'] ?? null)->toBe('Inner')
        ->and($hit['matched_prefix'] ?? null)->toBe('SW1A');
});

test('no zone is a miss, not an error', function () {
    $hit = (new ZoneMatcher)->match('M11AA', zoneMatcherCaminoZones(), new GbPostcodeNormaliser);

    expect($hit)->toBeNull();
});

test('non-GB passthrough matches prefixes against whatever the shopper typed', function () {
    $matcher = new ZoneMatcher;
    $pass = new PassthroughPostcodeNormaliser;
    $zones = [[
        'name' => 'West',
        'prefixes' => ['902', '90'],
        'fee_cents' => 795,
        'free_over_cents' => null,
        'lead_time' => '2 days',
        'min_order_cents' => null,
    ]];

    $hit = $matcher->match($pass->normalise('90210'), $zones, $pass);

    expect($hit['name'] ?? null)->toBe('West')
        ->and($hit['matched_prefix'] ?? null)->toBe('902');
});
