<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\Product;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function shopFiltersSite(string $host, array $overrides = []): Site
{
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'business_name' => 'Bloom & Stem',
        'shop_mode' => $overrides['shop_mode'] ?? 'cart',
    ]);

    Product::factory()->published()->for($site)->create(['slug' => 'rose', 'name' => 'Red Rose']);

    $product = [
        'id' => 1,
        'slug' => 'rose',
        'status' => 'published',
        'primary_category_slug' => 'cakes',
        'price_cents' => 4500,
        'price_display' => '£45.00',
        'in_stock_any' => true,
        'variant_in_stock' => [1 => true],
        'image_urls' => null,
        'product_card' => ['slug' => 'rose', 'name' => 'Red Rose', 'price_display' => '£45.00'],
        'product_detail' => ['slug' => 'rose', 'name' => 'Red Rose', 'description' => 'Lovely'],
        'variants' => [['id' => 1, 'sku' => 'RR-1', 'label' => 'Std', 'price_cents' => 4500, 'image_urls' => null]],
        'is_ai_seeded' => false,
        'is_ai_reviewed' => false,
    ];

    if (array_key_exists('f', $overrides)) {
        if (is_array($overrides['f'])) {
            $product['f'] = $overrides['f'];
        }
    }

    $facets = $overrides['facets'] ?? null;
    $categoryFacets = $overrides['category_facets'] ?? $facets;

    $json = [
        'meta' => ['site_id' => $site->id, 'product_count' => 1, 'currency' => 'GBP'],
        'categories' => [
            'cakes' => [
                'id' => 1,
                'slug' => 'cakes',
                'name' => 'Cakes',
                'path' => 'cakes',
                'depth' => 1,
                'visibility' => 'visible',
                'parent_slug' => null,
                'children' => ['wedding-cakes'],
                'product_slugs' => ['rose'],
                'breadcrumb' => [['name' => 'Cakes', 'path' => 'cakes']],
                'facets' => $categoryFacets,
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
                'product_slugs' => ['rose'],
                'breadcrumb' => [
                    ['name' => 'Cakes', 'path' => 'cakes'],
                    ['name' => 'Wedding Cakes', 'path' => 'cakes/wedding-cakes'],
                ],
                'facets' => [
                    'category' => [],
                    'price' => $categoryFacets['price'] ?? [],
                    'availability' => $categoryFacets['availability'] ?? [],
                    'options' => $categoryFacets['options'] ?? [],
                ],
            ],
        ],
        'category_paths' => [
            'cakes' => 'cakes',
            'cakes/wedding-cakes' => 'wedding-cakes',
        ],
        'products' => ['rose' => $product],
        'featured_slugs' => ['rose'],
    ];
    if (is_array($facets)) {
        $json['facets'] = $facets;
    }
    if (! is_array($categoryFacets)) {
        unset($json['categories']['cakes']['facets'], $json['categories']['wedding-cakes']['facets']);
    }
    if (array_key_exists('categories', $overrides)) {
        $json['categories'] = $overrides['categories'];
    }
    if (array_key_exists('category_paths', $overrides)) {
        $json['category_paths'] = $overrides['category_paths'];
    }
    if (array_key_exists('featured_slugs', $overrides)) {
        $json['featured_slugs'] = $overrides['featured_slugs'];
    }
    if (isset($overrides['extra_products']) && is_array($overrides['extra_products'])) {
        $json['products'] = array_merge($json['products'], $overrides['extra_products']);
    }

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

function shopFiltersFacets(): array
{
    return [
        'category' => [
            ['slug' => 'cakes', 'name' => 'Cakes', 'count' => 1],
        ],
        'price' => [
            ['id' => 0, 'min' => 0, 'max' => 2000, 'label' => 'Under £20.00', 'count' => 0],
            ['id' => 1, 'min' => 2000, 'max' => 4000, 'label' => '£20.00–£40.00', 'count' => 0],
            ['id' => 2, 'min' => 4000, 'max' => null, 'label' => '£40.00+', 'count' => 1],
        ],
        'availability' => [
            ['id' => 'in', 'label' => 'In stock', 'count' => 1],
            ['id' => 'mto', 'label' => 'Made to order', 'count' => 0],
        ],
        'options' => [
            ['id' => '8in', 'label' => '8"', 'count' => 1],
        ],
    ];
}

function shopFiltersCategoryFacets(): array
{
    $facets = shopFiltersFacets();
    $facets['category'] = [
        ['slug' => 'wedding-cakes', 'name' => 'Wedding Cakes', 'count' => 1],
    ];

    return $facets;
}

function shopFiltersCardOpenTag(string $html): string
{
    preg_match('/<div\b[^>]*class="shop-product-card[^"]*"[^>]*>/', $html, $m);
    expect($m)->not->toBeEmpty();

    return $m[0];
}

function shopFiltersBlock(string $html): string
{
    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $el = $dom->getElementById('shop-filters');
    expect($el)->not->toBeNull();

    return $dom->saveHTML($el);
}

/**
 * Storefront body used for byte comparisons. Livewire injects its
 * `<!-- Livewire Styles -->` block and `livewire.min.js` tag at most once
 * per PHP process, so whole-document HTML of two sequential GETs differs
 * whenever a neighbouring test already consumed that injection.
 */
function shopFiltersStorefrontHtml(string $html): string
{
    preg_match('/<main\b[^>]*>.*<\/main>/is', $html, $main);
    expect($main)->not->toBeEmpty();

    return $main[0];
}

test('shop index and category pages omit the filter row when the snapshot has no facets', function () {
    shopFiltersSite('plain-shop.example');

    $index = test()->get('http://plain-shop.example/shop')->assertOk()->getContent();
    $category = test()->get('http://plain-shop.example/collections/cakes')->assertOk()->getContent();

    expect($index)->not->toContain('id="shop-filters"')
        ->and($index)->not->toContain('data-f=')
        ->and($category)->not->toContain('id="shop-filters"')
        ->and($category)->not->toContain('data-f=')
        ->and(shopFiltersCardOpenTag($index))->toBe(shopFiltersCardOpenTag($category))
        ->and(shopFiltersCardOpenTag($index))->not->toContain('data-f');
});

test('shop index with no categories renders the plain product grid and never the facet UI', function () {
    $facets = shopFiltersFacets();
    $facets['category'] = [];
    $lily = [
        'id' => 2,
        'slug' => 'lily',
        'status' => 'published',
        'primary_category_slug' => null,
        'price_cents' => 2800,
        'price_display' => '£28.00',
        'in_stock_any' => true,
        'variant_in_stock' => [2 => true],
        'image_urls' => null,
        'product_card' => ['slug' => 'lily', 'name' => 'White Lily', 'price_display' => '£28.00'],
        'product_detail' => ['slug' => 'lily', 'name' => 'White Lily', 'description' => 'Fresh'],
        'variants' => [['id' => 2, 'sku' => 'WL-1', 'label' => 'Std', 'price_cents' => 2800, 'image_urls' => null]],
        'is_ai_seeded' => false,
        'is_ai_reviewed' => false,
        'f' => ['c' => [], 'p' => 2800, 'a' => 'in', 'o' => ['8in']],
    ];

    shopFiltersSite('no-cat-shop.example', [
        'facets' => $facets,
        'category_facets' => $facets,
        'categories' => [],
        'category_paths' => [],
        'featured_slugs' => [],
        'f' => ['c' => [], 'p' => 4500, 'a' => 'in', 'o' => ['8in']],
        'extra_products' => ['lily' => $lily],
    ]);

    $html = test()->get('http://no-cat-shop.example/shop')->assertOk()->getContent();
    $main = shopFiltersStorefrontHtml($html);
    $ignoredFacets = shopFiltersStorefrontHtml(
        test()->get('http://no-cat-shop.example/shop?cat[]=ghost&avail[]=mto&opt[]=large&attr[sponge][]=vanilla')
            ->assertOk()
            ->getContent(),
    );

    expect($main)->not->toContain('id="shop-filters"')
        ->and($main)->not->toContain('x-data="shopFilters()"')
        ->and($main)->not->toMatch('/<button\b[^>]*>Filter<\/button>/')
        ->and($main)->not->toContain('shop-filters-drawer')
        ->and($main)->not->toContain('Showing 0 of 0')
        ->and($main)->not->toContain('shopFilters()')
        ->and($main)->toContain('id="shop-listing-toolbar"')
        ->and($main)->toContain('Red Rose')
        ->and($main)->toContain('White Lily')
        ->and($ignoredFacets)->toBe($main);

    preg_match_all('/<div\b[^>]*class="shop-product-card /', $main, $cards);
    expect($cards[0])->toHaveCount(2);
});

test('shop index with categories never renders the facet UI', function () {
    shopFiltersSite('index-nav-only.example', [
        'facets' => shopFiltersFacets(),
        'category_facets' => shopFiltersCategoryFacets(),
        'f' => ['c' => ['cakes'], 'p' => 4500, 'a' => 'in', 'o' => ['8in']],
    ]);

    $html = test()->get('http://index-nav-only.example/shop')->assertOk()->getContent();
    $main = shopFiltersStorefrontHtml($html);

    expect($main)->not->toContain('x-data="shopFilters()"')
        ->and($main)->not->toMatch('/<button\b[^>]*>Filter<\/button>/')
        ->and($main)->not->toContain('shop-filters-drawer')
        ->and($main)->not->toContain('Showing 0 of 0')
        ->and($main)->not->toContain('aria-live')
        ->and($main)->not->toContain('shopFilters()')
        ->and($main)->not->toContain('£40.00+')
        ->and($main)->not->toContain('In stock (1)')
        ->and($main)->toContain('Cakes')
        ->and($main)->toContain('Red Rose');
});

test('the shop index renders exactly one category pill row', function (string $host, array $overrides) {
    shopFiltersSite($host, $overrides);

    $html = test()->get('http://'.$host.'/shop')->assertOk()->getContent();
    $main = shopFiltersStorefrontHtml($html);

    preg_match_all('/id="shop-filters"|aria-label="Browse by category"/', $main, $rows);
    expect($rows[0])->toHaveCount(1);
})->with([
    'with categories' => [
        'pill-cats.example',
        [
            'facets' => shopFiltersFacets(),
            'category_facets' => shopFiltersCategoryFacets(),
            'f' => ['c' => ['cakes'], 'p' => 4500, 'a' => 'in', 'o' => ['8in']],
        ],
    ],
    'without categories' => [
        'pill-none.example',
        (static function (): array {
            $facets = shopFiltersFacets();
            $facets['category'] = [];

            return [
                'facets' => $facets,
                'category_facets' => $facets,
                'categories' => [],
                'category_paths' => [],
                'featured_slugs' => [],
                'f' => ['c' => [], 'p' => 4500, 'a' => 'in', 'o' => ['8in']],
            ];
        })(),
    ],
]);

test('shop index renders navigating category chips only — no price, availability, options, count, or slide-over', function () {
    $facets = shopFiltersFacets();
    shopFiltersSite('filter-shop.example', [
        'facets' => $facets,
        'category_facets' => shopFiltersCategoryFacets(),
        'f' => ['c' => ['cakes'], 'p' => 4500, 'a' => 'in', 'o' => ['8in']],
    ]);

    $html = test()->get('http://filter-shop.example/shop')->assertOk()->getContent();
    $main = shopFiltersStorefrontHtml($html);

    expect($html)->not->toContain('id="shop-filters"')
        ->and($main)->toMatch('/<h1\b.*<\/h1>.*aria-label="Browse by category"/s')
        ->and($main)->toMatch('/<a\b[^>]*href="[^"]*\/collections\/cakes"[^>]*>Cakes<\/a>/')
        ->and($main)->not->toContain('x-data="shopFilters()"')
        ->and($main)->not->toMatch('/<button\b[^>]*>Filter<\/button>/')
        ->and($main)->not->toContain('Under £20.00')
        ->and($main)->not->toContain('In stock (1)')
        ->and($main)->not->toContain('Clear all')
        ->and($main)->not->toContain('Showing 0 of 0')
        ->and($main)->not->toContain('shop-filters-drawer')
        ->and($main)->not->toContain('shopFilters()')
        ->and($html)->toContain('data-f="')
        ->and($html)->toContain('&quot;c&quot;');
});

test('a category page shows child category chips rather than the site-level set', function () {
    shopFiltersSite('filter-cat.example', [
        'facets' => shopFiltersFacets(),
        'category_facets' => shopFiltersCategoryFacets(),
        'f' => ['c' => ['cakes', 'wedding-cakes'], 'p' => 4500, 'a' => 'in', 'o' => ['8in']],
    ]);

    $html = test()->get('http://filter-cat.example/collections/cakes')->assertOk()->getContent();
    preg_match('/id="shop-filters".*?id="shop-filters-drawer"/s', $html, $row);
    expect($row)->not->toBeEmpty();
    $filters = $row[0];

    expect($filters)->toContain('Wedding Cakes')
        ->and($filters)->toContain('name="cat[]"')
        ->and($filters)->not->toMatch('/name="cat\[\]"[^>]*value="cakes"/');
});

test('a category page renders the full filter row with live region and slide-over', function () {
    shopFiltersSite('filter-full.example', [
        'facets' => shopFiltersFacets(),
        'category_facets' => shopFiltersCategoryFacets(),
        'f' => ['c' => ['cakes', 'wedding-cakes'], 'p' => 4500, 'a' => 'in', 'o' => ['8in']],
    ]);

    $html = test()->get('http://filter-full.example/collections/cakes')->assertOk()->getContent();
    preg_match('/<main\b[^>]*>(.*?)<\/main>/is', $html, $main);
    $main = $main[1];

    expect($main)->toMatch('/id="shop-filters"/')
        ->and($main)->toContain('<noscript>')
        ->and($main)->toContain('Wedding Cakes (1)')
        ->and($main)->toContain('Filter')
        ->and($main)->toContain('£40.00+')
        ->and($main)->toContain('In stock')
        ->and($main)->toContain('8in')
        ->and($main)->toContain('Clear filters')
        ->and($main)->toContain('Apply filters')
        ->and($main)->toContain('Showing')
        ->and($main)->toMatch('/aria-live="polite"/')
        ->and($main)->toMatch('/id="shop-filters-drawer"[^>]*role="dialog"/')
        ->and($main)->toMatch('/aria-labelledby="shop-filters-drawer-title"/')
        ->and($main)->toContain('shopFilters()');
});

test('category filter chips omit zero-count values and show counts', function () {
    shopFiltersSite('filter-counts.example', [
        'facets' => shopFiltersFacets(),
        'category_facets' => shopFiltersCategoryFacets(),
        'f' => ['c' => ['cakes', 'wedding-cakes'], 'p' => 4500, 'a' => 'in', 'o' => ['8in']],
    ]);

    $filters = shopFiltersBlock(
        test()->get('http://filter-counts.example/collections/cakes')->assertOk()->getContent(),
    );

    expect($filters)->toContain('Wedding Cakes (1)')
        ->and($filters)->toContain('£40.00+ (1)')
        ->and($filters)->toContain('In stock (1)')
        ->and($filters)->toContain('8in')
        ->and($filters)->not->toContain('Under £20.00 (')
        ->and($filters)->not->toContain('Made to order (');
});

test('filter query params do not change the shop HTML the server returns', function () {
    shopFiltersSite('filter-qs.example', [
        'facets' => shopFiltersFacets(),
        'category_facets' => shopFiltersCategoryFacets(),
        'f' => ['c' => ['cakes'], 'p' => 4500, 'a' => 'in', 'o' => ['8in']],
    ]);

    $plain = test()->get('http://filter-qs.example/shop')->assertOk()->getContent();
    $filtered = test()->get('http://filter-qs.example/shop?price=2&avail=in&opt=8in')->assertOk()->getContent();

    expect(shopFiltersStorefrontHtml($filtered))->toBe(shopFiltersStorefrontHtml($plain));

    $categoryPlain = shopFiltersStorefrontHtml(
        test()->get('http://filter-qs.example/collections/cakes')->assertOk()->getContent(),
    );
    $categoryFiltered = shopFiltersStorefrontHtml(
        test()->get('http://filter-qs.example/collections/cakes?price=2')->assertOk()->getContent(),
    );

    expect($categoryFiltered)->toContain('Filter (1)')
        ->and($categoryPlain)->not->toContain('Filter (1)');
});

test('filter chips are pressed buttons, the slide-over is a labelled dialog, and the count is a live region', function () {
    shopFiltersSite('filter-a11y.example', [
        'facets' => shopFiltersFacets(),
        'category_facets' => shopFiltersCategoryFacets(),
        'f' => ['c' => ['cakes', 'wedding-cakes'], 'p' => 4500, 'a' => 'in', 'o' => ['8in']],
    ]);

    $html = test()->get('http://filter-a11y.example/collections/cakes')->assertOk()->getContent();

    expect($html)->toContain('Wedding Cakes (1)')
        ->and($html)->toMatch('/id="shop-filters-drawer"[^>]*role="dialog"/')
        ->and($html)->toMatch('/aria-modal="true"/')
        ->and($html)->toMatch('/aria-labelledby="shop-filters-drawer-title"/')
        ->and($html)->toContain('id="shop-filters-drawer-title"')
        ->and($html)->toMatch('/aria-live="polite"/')
        ->and($html)->toContain('Clear filters')
        ->and($html)->toContain('Apply filters');
});

test('category pages inline the tracked filters.js module', function () {
    $source = (string) file_get_contents(resource_path('js/shop/filters.js'));
    expect($source)->toContain('export function shopFilters');

    $inlined = (string) preg_replace('/^export /m', '', $source);
    expect($inlined)->not->toBe($source);

    shopFiltersSite('filter-inline.example', [
        'facets' => shopFiltersFacets(),
        'category_facets' => shopFiltersCategoryFacets(),
        'f' => ['c' => ['cakes', 'wedding-cakes'], 'p' => 4500, 'a' => 'in', 'o' => ['8in']],
    ]);

    $category = test()->get('http://filter-inline.example/collections/cakes')->assertOk()->getContent();
    $index = shopFiltersStorefrontHtml(
        test()->get('http://filter-inline.example/shop')->assertOk()->getContent(),
    );

    expect($category)->toContain($inlined)
        ->and($category)->not->toContain('export function shopFilters')
        ->and($index)->not->toContain('function shopFilters');
});

test('cart enquire and quote product cards stay byte-identical when no facets apply', function (string $mode) {
    shopFiltersSite('card-'.$mode.'.example', ['shop_mode' => $mode]);
    $html = test()->get('http://card-'.$mode.'.example/shop')->assertOk()->getContent();

    expect($html)->not->toContain('data-f=')
        ->and(shopFiltersCardOpenTag($html))->toBe('<div
    class="shop-product-card relative block overflow-hidden max-w-full"
    style="border: 1px solid var(--color-border); border-radius: var(--radius-card); position: relative;"
>');
})->with(['cart', 'enquire', 'quote']);
