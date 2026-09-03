<?php

use App\Models\Site;

it('exposes the decided site_type and region slug-to-label maps', function () {
    expect(config('site_types'))->toMatchArray([
        'builder' => 'Builder',
        'electrician' => 'Electrician',
        'plumber' => 'Plumber',
        'roofer' => 'Roofer',
        'joiner' => 'Joiner',
        'plasterer' => 'Plasterer',
        'decorator' => 'Decorator',
        'landscaper' => 'Landscaper',
        'groundworks' => 'Groundworks',
        'driveways-patios' => 'Driveways & Patios',
        'architect' => 'Architect',
        'engineer' => 'Engineer',
        'renovation' => 'Renovation & Refurbishment',
        'property-development' => 'Property Development',
        'industrial-coatings' => 'Industrial Coatings',
        'aggregates' => 'Aggregates',
        'cleaner' => 'Cleaner',
        'alternative-health' => 'Alternative Health',
        'bakery' => 'Bakery',
        'florist' => 'Florist',
        'saas' => 'SaaS',
        'other' => 'Other',
    ])->and(config('regions'))->toMatchArray([
        'north-east' => 'North East',
        'north-west' => 'North West',
        'yorkshire' => 'Yorkshire',
        'east-midlands' => 'East Midlands',
        'west-midlands' => 'West Midlands',
        'east-of-england' => 'East of England',
        'london' => 'London',
        'south-east' => 'South East',
        'south-west' => 'South West',
        'scotland' => 'Scotland',
        'wales' => 'Wales',
        'northern-ireland' => 'Northern Ireland',
        'international' => 'International',
    ]);
});

it('resolves siteTypeLabel and regionLabel from config, or null when unset', function () {
    $typed = new Site(['site_type' => 'saas', 'region' => 'north-west']);
    $blank = new Site(['site_type' => null, 'region' => null]);

    expect($typed->siteTypeLabel())->toBe('SaaS')
        ->and($typed->regionLabel())->toBe('North West')
        ->and($blank->siteTypeLabel())->toBeNull()
        ->and($blank->regionLabel())->toBeNull();
});
