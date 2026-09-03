<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\Product;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;

/**
 * @param  array<string, mixed>  $overrides
 */
function shopListingToolbarSite(string $host, array $overrides = []): Site
{
    $site = Site::factory()->create(array_merge([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'business_name' => 'Bloom & Stem',
        'shop_mode' => 'cart',
    ], $overrides['site'] ?? []));

    Product::factory()->published()->for($site)->create(['slug' => 'row', 'name' => 'Row']);

    $products = $overrides['products'] ?? shopListingThirtyProducts();
    $productSlugs = array_keys($products);
    $hasCategories = $overrides['has_categories'] ?? true;

    $json = [
        'meta' => ['site_id' => $site->id, 'product_count' => count($products), 'currency' => 'GBP'],
        'categories' => $hasCategories ? [
            'cakes' => [
                'id' => 1,
                'slug' => 'cakes',
                'name' => 'Cakes',
                'path' => 'cakes',
                'depth' => 1,
                'visibility' => 'visible',
                'parent_slug' => null,
                'children' => ['wedding-cakes'],
                'product_slugs' => $productSlugs,
                'breadcrumb' => [['name' => 'Cakes', 'path' => 'cakes']],
                'facets' => $overrides['category_facets'] ?? shopListingToolbarFacets(),
            ],
            'wedding-cakes' => [
                'id' => 2,
                'slug' => 'wedding-cakes',
                'name' => 'Wedding Cakes',
                'path' => 'cakes/wedding-cakes',
                'depth' => 2,
                'visibility' => 'visible',
                'parent_slug' => 'cakes',
                'children' => [],
                'product_slugs' => array_slice($productSlugs, 0, 3),
                'breadcrumb' => [
                    ['name' => 'Cakes', 'path' => 'cakes'],
                    ['name' => 'Wedding Cakes', 'path' => 'cakes/wedding-cakes'],
                ],
                'facets' => $overrides['child_facets'] ?? ['category' => [], 'price' => [], 'availability' => [], 'options' => []],
            ],
        ] : [],
        'category_paths' => $hasCategories ? [
            'cakes' => 'cakes',
            'cakes/wedding-cakes' => 'wedding-cakes',
        ] : [],
        'products' => $products,
        'featured_slugs' => $productSlugs,
        'facets' => $overrides['facets'] ?? shopListingToolbarFacets(),
    ];

    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => $json,
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create([
        'site_id' => $site->id,
        'snapshot_id' => $snap->id,
        'updated_at' => now(),
    ]);

    return $site;
}

/**
 * @return array<string, array<string, mixed>>
 */
function shopListingThirtyProducts(): array
{
    $products = [];
    for ($i = 0; $i < 30; $i++) {
        $slug = 'p'.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        $price = match (true) {
            $i <= 9 => ($i + 1) * 1000,
            $i <= 12 => 5000,
            $i === 13 => 4000,
            $i === 14 => 8000,
            $i === 15, $i === 16 => null,
            default => 2500 + ($i * 10),
        };
        $products[$slug] = [
            'id' => $i + 1,
            'slug' => $slug,
            'status' => 'published',
            'primary_category_slug' => 'cakes',
            'price_cents' => $price,
            'price_from' => in_array($i, [13, 14], true),
            'price_display' => $price === null ? '' : '£'.number_format($price / 100, 2),
            'in_stock_any' => true,
            'variant_in_stock' => [1 => true],
            'image_urls' => null,
            'product_card' => ['slug' => $slug, 'name' => $slug, 'price_display' => $price === null ? '' : '£'.number_format($price / 100, 2)],
            'product_detail' => ['slug' => $slug, 'name' => $slug, 'description' => $slug],
            'variants' => $price === null ? [] : [['id' => $i + 1, 'sku' => strtoupper($slug), 'label' => 'Std', 'price_cents' => $price, 'image_urls' => null]],
            'is_ai_seeded' => false,
            'is_ai_reviewed' => false,
            'f' => array_filter([
                'c' => ['cakes'],
                'p' => $price,
                'a' => 'in',
                'o' => [],
            ], fn ($v) => $v !== null),
        ];
    }

    return $products;
}

function shopListingToolbarFacets(): array
{
    return [
        'category' => [
            ['slug' => 'wedding-cakes', 'name' => 'Wedding Cakes', 'count' => 3],
        ],
        'price' => [
            ['id' => 0, 'min' => 0, 'max' => 2000, 'label' => 'Under £20.00', 'count' => 2],
            ['id' => 3, 'min' => 8000, 'max' => null, 'label' => '£80.00+', 'count' => 3],
        ],
        'availability' => [
            ['id' => 'in', 'label' => 'In stock', 'count' => 30],
        ],
        'options' => [
            ['id' => '8in', 'label' => '8"', 'count' => 5],
        ],
    ];
}

/**
 * @return list<string>
 */
function shopListingCardNames(string $html): array
{
    preg_match_all('/<div class="font-semibold break-words">([^<]+)<\/div>/', $html, $m);

    return $m[1];
}

function shopListingMain(string $html): string
{
    preg_match('/<main\b[^>]*>.*<\/main>/is', $html, $main);
    expect($main)->not->toBeEmpty();

    return $main[0];
}

test('a category page sorts in memory by price_desc and never 500s on an unknown sort', function () {
    shopListingToolbarSite('sort-cat.example');

    $desc = test()->get('http://sort-cat.example/collections/cakes?sort=price_desc')->assertOk()->getContent();
    $names = shopListingCardNames($desc);

    expect($names[0])->toBe('p09')
        ->and($names[1])->toBe('p08')
        ->and($desc)->toContain('?sort=price_desc');

    $unknown = test()->get('http://sort-cat.example/collections/cakes?sort=nope')->assertOk();
    expect(shopListingCardNames($unknown->getContent())[0])->toBe('p00');
});

test('changing sort drops the page query parameter', function () {
    shopListingToolbarSite('sort-page.example');

    $html = test()->get('http://sort-page.example/collections/cakes?page=2&price=3')->assertOk()->getContent();

    expect($html)->toMatch('/href="[^"]*sort=price_desc[^"]*"/')
        ->and($html)->not->toMatch('/href="[^"]*sort=price_desc[^"]*page=/');
});

test('the category toolbar shows the count, an accessible sort menu, a no-js select, and Filter with the active count', function () {
    shopListingToolbarSite('sort-toolbar.example');

    $html = test()->get('http://sort-toolbar.example/collections/cakes?sort=newest&price=3')->assertOk()->getContent();
    $main = shopListingMain($html);

    expect($main)->toContain('id="shop-listing-toolbar"')
        ->and($main)->toContain('>Showing 4 items</p>')
        ->and($main)->not->toContain('Showing 4 of 4</p>')
        ->and($main)->toMatch('/<p\b[^>]*font-family: var\(--font-display\);[^>]*>Showing 4 items<\/p>/')
        ->and($main)->toMatch('/<button\b[^>]*aria-haspopup="menu"[^>]*>[\s\S]*Sort by:/')
        ->and($main)->toMatch('/role="menu"/')
        ->and($main)->toContain('Price: Highest – Lowest')
        ->and($main)->toContain('Price: Lowest – Highest')
        ->and($main)->toContain('Newest')
        ->and($main)->toMatch('/<select\b[^>]*name="sort"/')
        ->and($main)->toMatch('/<option\b[^>]*value="newest"[^>]*selected/')
        ->and($main)->toContain('Filter (1)')
        ->and($main)->not->toContain('sort=rating')
        ->and($main)->not->toMatch('/<h1\b[^>]*>[\s\S]*30 products[\s\S]*<\/h1>/')
        ->and($main)->not->toContain('aria-label="Browse by category"');
});

test('the unfiltered category toolbar uses the exact unfiltered count and omits zero from Filter', function () {
    shopListingToolbarSite('sort-toolbar-unfiltered.example');

    $main = shopListingMain(test()->get('http://sort-toolbar-unfiltered.example/collections/cakes')->assertOk()->getContent());

    expect($main)->toContain('>Showing 30 items</p>')
        ->and($main)->not->toContain('Showing 30 of 30')
        ->and($main)->toMatch('/>\s*Filter\s*<\/button>/')
        ->and($main)->not->toContain('Filter (0)');
});

test('the toolbar count describes the products on the current page', function () {
    $products = shopListingThirtyProducts();
    foreach (array_values($products) as $index => $product) {
        $products[$product['slug']]['f']['a'] = $index < 15 ? 'in' : 'mto';
    }

    shopListingToolbarSite('toolbar-page-count.example', [
        'site' => ['shop_page_size' => 12],
        'products' => $products,
        'category_facets' => array_replace(shopListingToolbarFacets(), [
            'availability' => [
                ['id' => 'in', 'label' => 'In stock', 'count' => 15],
                ['id' => 'mto', 'label' => 'Made to order', 'count' => 15],
            ],
        ]),
    ]);

    $pageOne = shopListingMain(test()->get('http://toolbar-page-count.example/collections/cakes')->assertOk()->getContent());
    $pageThree = shopListingMain(test()->get('http://toolbar-page-count.example/collections/cakes?page=3')->assertOk()->getContent());
    $filtered = shopListingMain(test()->get('http://toolbar-page-count.example/collections/cakes?avail[]=in')->assertOk()->getContent());

    expect($pageOne)->toContain('>Showing 12 items</p>')
        ->and($pageThree)->toContain('>Showing 6 items</p>')
        ->and($filtered)->toContain('>Showing 12 of 15 items</p>');
});

test('the shop index with categories does not render Sort or Filter', function () {
    shopListingToolbarSite('sort-index-cats.example');

    $main = shopListingMain(test()->get('http://sort-index-cats.example/shop')->assertOk()->getContent());

    expect($main)->not->toContain('id="shop-listing-toolbar"')
        ->and($main)->not->toContain('Sort by:')
        ->and($main)->not->toMatch('/name="sort"/')
        ->and($main)->toMatch('/aria-label="Browse by category"/');
});

test('the plain-grid shop index renders Sort and ignores Filter', function () {
    shopListingToolbarSite('sort-index-plain.example', [
        'has_categories' => false,
        'facets' => [
            'category' => [],
            'price' => [
                ['id' => 0, 'min' => 0, 'max' => 2000, 'label' => 'Under £20.00', 'count' => 2],
            ],
            'availability' => [],
            'options' => [],
        ],
        'category_facets' => [
            'category' => [],
            'price' => [],
            'availability' => [],
            'options' => [],
        ],
    ]);

    $html = test()->get('http://sort-index-plain.example/shop?sort=price_asc')->assertOk()->getContent();
    $main = shopListingMain($html);
    $names = shopListingCardNames($html);

    expect($main)->toContain('id="shop-listing-toolbar"')
        ->and($main)->toContain('Sort by:')
        ->and($main)->not->toMatch('/>Filter/')
        ->and($names[0])->toBe('p00')
        ->and($names[1])->toBe('p01');
});

test('facet links keep the current sort parameter', function () {
    shopListingToolbarSite('sort-mirror.example');

    $html = test()->get('http://sort-mirror.example/collections/cakes?sort=newest')->assertOk()->getContent();

    expect($html)->toMatch('/href="[^"]*sort=newest[^"]*"/');
});

test('an explicit Featured choice survives facets and pagination when the site default differs', function () {
    shopListingToolbarSite('sort-featured-override.example', [
        'site' => [
            'shop_default_sort' => 'newest',
            'shop_page_size' => 12,
        ],
    ]);

    $paged = test()->get('http://sort-featured-override.example/collections/cakes?sort=featured&page=2')->assertOk()->getContent();

    expect(shopListingCardNames($paged)[0])->toBe('p12')
        ->and($paged)->toContain('name="sort" value="featured"')
        ->and($paged)->toMatch('/href="[^\"]*sort=featured(?:&amp;|&)page=3[^\"]*"/');

    $filtered = test()->get('http://sort-featured-override.example/collections/cakes?sort=featured&price[]=3')->assertOk()->getContent();

    expect($filtered)->toContain('name="sort" value="featured"')
        ->and($filtered)->toMatch('/aria-label="Active filters"[\s\S]*href="[^\"]*sort=featured[^\"]*"/');
});
