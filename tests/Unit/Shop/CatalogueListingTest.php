<?php

use App\Services\Shop\CatalogueListing;
use App\Support\Shop\ShopListingQuery;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function listingProduct(string $slug, int $id, array $overrides = []): array
{
    $price = array_key_exists('price_cents', $overrides) ? $overrides['price_cents'] : 1000;
    $hasF = $overrides['has_f'] ?? true;
    unset($overrides['has_f']);

    $product = array_merge([
        'id' => $id,
        'slug' => $slug,
        'status' => 'published',
        'price_cents' => $price,
        'price_from' => false,
        'product_card' => ['slug' => $slug, 'name' => $slug, 'price_display' => '£'.number_format(((int) ($price ?? 0)) / 100, 2)],
    ], $overrides);

    if ($hasF) {
        $product['f'] = array_merge([
            'c' => ['cakes'],
            'p' => is_int($price) ? $price : 0,
            'a' => 'in',
            'o' => [],
        ], is_array($overrides['f'] ?? null) ? $overrides['f'] : []);
        if ($price === null) {
            unset($product['f']['p']);
        }
    }

    return $product;
}

/**
 * 30-product catalogue in featured order p00…p29.
 *
 * @return list<array<string, mixed>>
 */
function listingThirty(): array
{
    $products = [];
    for ($i = 0; $i < 30; $i++) {
        $slug = 'p'.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        $overrides = [];

        if ($i <= 9) {
            $overrides['price_cents'] = ($i + 1) * 1000;
        } elseif ($i <= 12) {
            $overrides['price_cents'] = 5000;
        } elseif ($i === 13) {
            $overrides['price_cents'] = 4000;
            $overrides['price_from'] = true;
        } elseif ($i === 14) {
            $overrides['price_cents'] = 8000;
            $overrides['price_from'] = true;
        } elseif ($i === 15 || $i === 16) {
            $overrides['price_cents'] = null;
            $overrides['f'] = ['c' => ['cakes'], 'a' => 'in', 'o' => []];
        } else {
            $overrides['price_cents'] = 2500 + ($i * 10);
        }

        $products[] = listingProduct($slug, $i + 1, $overrides);
    }

    return $products;
}

function listingSlugs(array $products): array
{
    return array_values(array_map(fn (array $p): string => $p['slug'], $products));
}

test('featured sort keeps the snapshot order', function () {
    $products = listingThirty();

    expect(listingSlugs(CatalogueListing::sort($products, 'featured')))
        ->toBe(listingSlugs($products));
});

test('unknown sort values fall back to featured and never throw', function (string $sort) {
    $products = listingThirty();

    expect(listingSlugs(CatalogueListing::sort($products, $sort)))
        ->toBe(listingSlugs($products));
})->with(['', 'nope', 'PRICE_DESC', 'featured ']);

test('price_desc orders by min price with ties keeping featured order and missing prices last', function () {
    $sorted = CatalogueListing::sort(listingThirty(), 'price_desc');
    $slugs = listingSlugs($sorted);

    expect($slugs[0])->toBe('p09')
        ->and($slugs[1])->toBe('p08')
        ->and($slugs[2])->toBe('p07')
        ->and($slugs[3])->toBe('p14')
        ->and(array_slice($slugs, -2))->toBe(['p15', 'p16']);

    $fiveThousand = array_values(array_filter($sorted, fn (array $p): bool => ($p['f']['p'] ?? $p['price_cents'] ?? null) === 5000));
    expect(listingSlugs($fiveThousand))->toBe(['p04', 'p10', 'p11', 'p12']);
});

test('price_asc orders by min price with missing prices last', function () {
    $slugs = listingSlugs(CatalogueListing::sort(listingThirty(), 'price_asc'));

    expect($slugs[0])->toBe('p00')
        ->and($slugs[1])->toBe('p01')
        ->and(array_slice($slugs, -2))->toBe(['p15', 'p16']);
});

test('price_from products still sort by their guide price', function () {
    $slugs = listingSlugs(CatalogueListing::sort(listingThirty(), 'price_desc'));

    expect($slugs[2])->toBe('p07')
        ->and($slugs[3])->toBe('p14')
        ->and(array_search('p13', $slugs, true))->toBeGreaterThan(array_search('p03', $slugs, true));
});

test('newest sort uses published time descending, nulls last, and id as the tie-break', function () {
    $products = [
        listingProduct('older-high-id', 40, ['published_at' => '2026-08-01T10:00:00+00:00']),
        listingProduct('newest', 1, ['published_at' => '2026-08-30T10:00:00+00:00']),
        listingProduct('tie-low-id', 5, ['published_at' => '2026-08-20T10:00:00+00:00']),
        listingProduct('tie-high-id', 9, ['published_at' => '2026-08-20T10:00:00+00:00']),
        listingProduct('undated-high-id', 100, ['published_at' => null]),
        listingProduct('undated-low-id', 2, ['published_at' => null]),
    ];

    expect(listingSlugs(CatalogueListing::sort($products, 'newest')))->toBe([
        'newest',
        'tie-high-id',
        'tie-low-id',
        'older-high-id',
        'undated-high-id',
        'undated-low-id',
    ]);
});

test('rating sort is offered only when a product carries a rating key', function () {
    $plain = listingThirty();
    expect(CatalogueListing::resolveSort('rating', CatalogueListing::hasRating($plain)))->toBe('featured')
        ->and(listingSlugs(CatalogueListing::sort($plain, 'rating')))->toBe(listingSlugs($plain));

    $rated = $plain;
    $rated[0]['rating'] = 2;
    $rated[5]['rating'] = 5;
    $rated[8]['rating'] = 5;

    expect(CatalogueListing::hasRating($rated))->toBeTrue()
        ->and(CatalogueListing::resolveSort('rating', true))->toBe('rating');

    $slugs = listingSlugs(CatalogueListing::sort($rated, 'rating'));
    expect(array_slice($slugs, 0, 3))->toBe(['p05', 'p08', 'p00']);
});

test('empty catalogues sort to an empty list', function () {
    expect(CatalogueListing::sort([], 'price_desc'))->toBe([]);
});

test('resolveSort uses the site default when the query is empty and featured when unknown', function () {
    expect(CatalogueListing::resolveSort(null, false, 'newest'))->toBe('newest')
        ->and(CatalogueListing::resolveSort('', false, 'price_asc'))->toBe('price_asc')
        ->and(CatalogueListing::resolveSort('nope', false, 'newest'))->toBe('featured')
        ->and(CatalogueListing::resolveSort('rating', false, 'newest'))->toBe('featured')
        ->and(CatalogueListing::resolveSort('newest', false))->toBe('newest');
});

test('query composition omits the configured default but preserves an explicit Featured override', function () {
    $defaulted = CatalogueListing::apply(listingThirty(), [], defaultSort: 'newest');
    $featured = CatalogueListing::apply(listingThirty(), ['sort' => 'featured'], defaultSort: 'newest');

    expect(ShopListingQuery::toQuery($defaulted['state']))->not->toHaveKey('sort')
        ->and(ShopListingQuery::toQuery($featured['state']))->toMatchArray(['sort' => 'featured'])
        ->and(ShopListingQuery::toQuery($featured['state'], ['page' => 2]))->toMatchArray([
            'sort' => 'featured',
            'page' => 2,
        ]);
});

test('apply ANDs across facet groups and ORs within a group', function () {
    $products = [
        listingProduct('cake', 1, ['price_cents' => 4500, 'f' => ['c' => ['cakes', 'wedding-cakes'], 'p' => 4500, 'a' => 'in', 'o' => ['8in']]]),
        listingProduct('tart', 2, ['price_cents' => 950, 'f' => ['c' => ['patisserie'], 'p' => 950, 'a' => 'in', 'o' => []]]),
        listingProduct('wedding', 3, ['price_cents' => 8000, 'f' => ['c' => ['cakes', 'wedding-cakes'], 'p' => 8000, 'a' => 'mto', 'o' => ['8in', '10in']]]),
    ];
    $facets = [
        'price' => [
            ['id' => 0, 'min' => 0, 'max' => 2000],
            ['id' => 2, 'min' => 4000, 'max' => null],
        ],
    ];

    $listing = CatalogueListing::apply($products, ['price' => ['0', '2'], 'cat' => ['wedding-cakes']], $facets);

    expect(listingSlugs($listing['products']))->toBe(['cake', 'wedding'])
        ->and($listing['filtered'])->toBe(2)
        ->and($listing['total'])->toBe(3)
        ->and($listing['activeFilterCount'])->toBe(3);
});

test('apply keeps featured order after filtering', function () {
    $products = listingThirty();
    $facets = [
        'price' => [
            ['id' => 3, 'min' => 8000, 'max' => null, 'count' => 4],
        ],
    ];

    $listing = CatalogueListing::apply($products, ['price' => '3', 'sort' => 'featured'], $facets);

    expect(listingSlugs($listing['products']))->toBe(['p07', 'p08', 'p09', 'p14']);
});
