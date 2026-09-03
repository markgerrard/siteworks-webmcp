<?php

use App\Services\Site\FeaturedProductsPicker;

function featuredSnapshot(array $overrides = []): array
{
    $products = $overrides['products'] ?? [
        'alpha' => ['id' => 1, 'slug' => 'alpha', 'status' => 'published', 'product_card' => ['name' => 'Alpha']],
        'bravo' => ['id' => 2, 'slug' => 'bravo', 'status' => 'published', 'product_card' => ['name' => 'Bravo']],
        'charlie' => ['id' => 3, 'slug' => 'charlie', 'status' => 'published', 'product_card' => ['name' => 'Charlie']],
        'delta' => ['id' => 4, 'slug' => 'delta', 'status' => 'published', 'product_card' => ['name' => 'Delta']],
        'echo' => ['id' => 5, 'slug' => 'echo', 'status' => 'published', 'product_card' => ['name' => 'Echo']],
    ];

    return [
        'products' => $products,
        'featured_slugs' => $overrides['featured_slugs'] ?? ['delta', 'alpha'],
        'categories' => $overrides['categories'] ?? [],
    ];
}

it('returns featured products in featured_slugs order', function () {
    $picker = app(FeaturedProductsPicker::class);
    $picked = $picker->products(['source' => 'featured', 'count' => 4], featuredSnapshot());

    expect(array_column($picked, 'slug'))->toBe(['delta', 'alpha']);
});

it('falls back to newest when featured source has no active featured products', function () {
    $picker = app(FeaturedProductsPicker::class);
    $picked = $picker->products(
        ['source' => 'featured', 'count' => 4],
        featuredSnapshot(['featured_slugs' => []]),
    );

    expect(array_column($picked, 'slug'))->toBe(['echo', 'delta', 'charlie', 'bravo']);
});

it('uses newest order when source is newest', function () {
    $picker = app(FeaturedProductsPicker::class);
    $picked = $picker->products(['source' => 'newest', 'count' => 3], featuredSnapshot());

    expect(array_column($picked, 'slug'))->toBe(['echo', 'delta', 'charlie']);
});

it('clamps count to 3–8', function () {
    $picker = app(FeaturedProductsPicker::class);
    $snapshot = featuredSnapshot();

    expect($picker->clampedCount(['count' => 1]))->toBe(3)
        ->and($picker->clampedCount(['count' => 99]))->toBe(8)
        ->and($picker->clampedCount([]))->toBe(4);

    $high = $picker->products(['source' => 'newest', 'count' => 99], $snapshot);
    expect($high)->toHaveCount(5);

    $low = $picker->products(['source' => 'newest', 'count' => 1], $snapshot);
    expect(array_column($low, 'slug'))->toBe(['echo', 'delta', 'charlie']);

    $many = [];
    for ($i = 1; $i <= 10; $i++) {
        $many['item-'.$i] = ['id' => $i, 'slug' => 'item-'.$i, 'status' => 'published'];
    }
    expect($picker->products(
        ['source' => 'newest', 'count' => 99],
        ['products' => $many, 'featured_slugs' => []],
    ))->toHaveCount(8);
});

it('returns nothing for a missing snapshot or a snapshot with no visible products', function () {
    $picker = app(FeaturedProductsPicker::class);

    expect($picker->products(['source' => 'featured'], null))->toBe([])
        ->and($picker->products(['source' => 'featured'], ['products' => [], 'featured_slugs' => []]))->toBe([])
        ->and($picker->products(
            ['source' => 'featured'],
            ['products' => ['ghost' => ['id' => 9, 'slug' => 'ghost', 'status' => 'draft']], 'featured_slugs' => ['ghost']],
        ))->toBe([]);
});

it('treats manual as featured and picks tag and category sources from the snapshot', function () {
    $picker = app(FeaturedProductsPicker::class);
    $snapshot = featuredSnapshot([
        'products' => [
            'alpha' => ['id' => 1, 'slug' => 'alpha', 'status' => 'published', 'tags' => [['slug' => 'gift', 'label' => 'Gift', 'badge' => true, 'tone' => 'neutral']]],
            'bravo' => ['id' => 2, 'slug' => 'bravo', 'status' => 'published', 'tags' => [['slug' => 'gift', 'label' => 'Gift', 'badge' => true, 'tone' => 'neutral']]],
            'charlie' => ['id' => 3, 'slug' => 'charlie', 'status' => 'published', 'tags' => []],
        ],
        'featured_slugs' => ['bravo', 'alpha'],
        'categories' => [
            'range' => ['slug' => 'range', 'product_slugs' => ['charlie', 'alpha']],
        ],
    ]);

    expect(array_column($picker->products(['source' => 'manual', 'count' => 4], $snapshot), 'slug'))->toBe(['bravo', 'alpha'])
        ->and(array_column($picker->products(['source' => 'tag:gift', 'limit' => 8], $snapshot), 'slug'))->toBe(['alpha', 'bravo'])
        ->and(array_column($picker->products(['source' => 'category:range', 'limit' => 8], $snapshot), 'slug'))->toBe(['charlie', 'alpha']);
});

it('hides auto sources when fewer than two products match', function (string $source) {
    $picker = app(FeaturedProductsPicker::class);
    $snapshot = featuredSnapshot([
        'products' => [
            'alpha' => ['id' => 1, 'slug' => 'alpha', 'status' => 'published', 'tags' => [['slug' => 'gift', 'label' => 'Gift', 'badge' => true, 'tone' => 'neutral']]],
        ],
        'featured_slugs' => ['alpha'],
        'categories' => ['range' => ['slug' => 'range', 'product_slugs' => ['alpha']]],
    ]);

    expect($picker->products(['source' => $source], $snapshot))->toBe([]);
})->with([
    'newest' => 'newest',
    'tag' => 'tag:gift',
    'category' => 'category:range',
]);

it('returns a single product for manual and featured sources', function (string $source) {
    $picker = app(FeaturedProductsPicker::class);
    $snapshot = featuredSnapshot([
        'products' => [
            'alpha' => ['id' => 1, 'slug' => 'alpha', 'status' => 'published'],
        ],
        'featured_slugs' => ['alpha'],
    ]);

    expect(array_column($picker->products(['source' => $source], $snapshot), 'slug'))->toBe(['alpha']);
})->with([
    'manual' => 'manual',
    'featured' => 'featured',
]);

it('clamps limit to 4–12 when set, otherwise count to 3–8', function () {
    $picker = app(FeaturedProductsPicker::class);

    expect($picker->clampedCount(['limit' => 2]))->toBe(4)
        ->and($picker->clampedCount(['limit' => 99]))->toBe(12)
        ->and($picker->clampedCount(['count' => 1]))->toBe(3);
});

it('includes drafts only when asked', function () {
    $picker = app(FeaturedProductsPicker::class);
    $snapshot = [
        'products' => [
            'live' => ['id' => 1, 'slug' => 'live', 'status' => 'published'],
            'also' => ['id' => 3, 'slug' => 'also', 'status' => 'published'],
            'wip' => ['id' => 2, 'slug' => 'wip', 'status' => 'draft'],
        ],
        'featured_slugs' => ['wip', 'live', 'also'],
    ];

    expect(array_column($picker->products(['source' => 'featured'], $snapshot), 'slug'))->toBe(['live', 'also'])
        ->and(array_column($picker->products(['source' => 'featured'], $snapshot, includeDrafts: true), 'slug'))->toBe(['wip', 'live', 'also']);
});
