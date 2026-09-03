<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\Product;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;

/**
 * @param  array<string, mixed>  $overrides
 */
function shopDrawerSite(string $host, array $overrides = []): Site
{
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'business_name' => 'Bloom & Stem',
        'shop_mode' => 'cart',
    ]);

    Product::factory()->published()->for($site)->create(['slug' => 'row', 'name' => 'Row']);

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
        'f' => $overrides['f'] ?? ['c' => ['cakes'], 'p' => 4500, 'a' => 'in', 'o' => ['8in']],
    ];

    $facets = $overrides['facets'] ?? [
        'category' => [
            ['slug' => 'wedding-cakes', 'name' => 'Wedding Cakes', 'count' => 1],
        ],
        'price' => [
            ['id' => 2, 'min' => 4000, 'max' => null, 'label' => '£40.00+', 'count' => 1],
        ],
        'availability' => [],
        'options' => [],
        'attributes' => $overrides['attributes'] ?? [],
    ];

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
                'children' => [],
                'product_slugs' => ['rose'],
                'breadcrumb' => [['name' => 'Cakes', 'path' => 'cakes']],
                'facets' => $facets,
            ],
        ],
        'category_paths' => ['cakes' => 'cakes'],
        'products' => ['rose' => $product],
        'featured_slugs' => ['rose'],
        'facets' => $facets,
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

test('a category with no facets omits the Filter button and drawer', function () {
    shopDrawerSite('drawer-none.example', [
        'facets' => [
            'category' => [],
            'price' => [],
            'availability' => [],
            'options' => [],
            'attributes' => [],
        ],
        'f' => ['c' => ['cakes'], 'p' => 4500, 'a' => 'in', 'o' => []],
    ]);

    $html = test()->get('http://drawer-none.example/collections/cakes')->assertOk()->getContent();

    expect($html)->toContain('id="shop-listing-toolbar"')
        ->and($html)->not->toContain('shop-filters-drawer')
        ->and($html)->not->toContain('Apply filters')
        ->and($html)->not->toMatch('/aria-controls="shop-filters-drawer"/');
});

test('a single facet group still renders the Filter drawer', function () {
    shopDrawerSite('drawer-one.example', [
        'facets' => [
            'category' => [],
            'price' => [
                ['id' => 2, 'min' => 4000, 'max' => null, 'label' => '£40.00+', 'count' => 1],
            ],
            'availability' => [],
            'options' => [],
        ],
    ]);

    $html = test()->get('http://drawer-one.example/collections/cakes')->assertOk()->getContent();

    expect($html)->toContain('id="shop-filters-drawer"')
        ->and($html)->toContain('Filters')
        ->and($html)->toContain('Apply filters')
        ->and($html)->toContain('Clear filters')
        ->and($html)->toContain('£40.00+ (1)')
        ->and($html)->toMatch('/<details\b/')
        ->and($html)->not->toContain('Wedding Cakes');
});

test('the availability group uses a neutral heading', function () {
    shopDrawerSite('drawer-availability.example', [
        'facets' => [
            'category' => [],
            'price' => [],
            'availability' => [
                ['id' => 'in', 'label' => 'In stock', 'count' => 1],
            ],
            'options' => [],
        ],
    ]);

    $html = test()->get('http://drawer-availability.example/collections/cakes')->assertOk()->getContent();

    expect($html)->toMatch('/<summary\b[^>]*>[\s\S]*?<span>Availability<\/span>/')
        ->and($html)->toContain('<span>In stock (1)</span>');
});

test('bakery attribute groups come from snapshot data, not hard-coded nouns', function () {
    shopDrawerSite('drawer-bakery.example', [
        'attributes' => [
            ['id' => 'sponge', 'name' => 'Sponge', 'values' => [['id' => 'vanilla', 'label' => 'Vanilla', 'count' => 1]]],
            ['id' => 'size', 'name' => 'Size & Serving', 'values' => [['id' => '8in', 'label' => '8"', 'count' => 1]]],
        ],
        'f' => ['c' => ['cakes'], 'p' => 4500, 'a' => 'in', 'o' => ['8in'], 'attrs' => ['sponge' => ['vanilla'], 'size' => ['8in']]],
    ]);

    $html = test()->get('http://drawer-bakery.example/collections/cakes')->assertOk()->getContent();

    expect($html)->toContain('Sponge')
        ->and($html)->toContain('Size &amp; Serving')
        ->and($html)->toContain('name="attr[sponge][]"')
        ->and($html)->toContain('Vanilla (1)');
});

test('florist attribute groups come from snapshot data', function () {
    shopDrawerSite('drawer-florist.example', [
        'attributes' => [
            ['id' => 'colour', 'name' => 'Colour', 'values' => [['id' => 'blush', 'label' => 'Blush', 'count' => 1]]],
        ],
        'f' => ['c' => ['cakes'], 'p' => 4500, 'a' => 'in', 'o' => [], 'attrs' => ['colour' => ['blush']]],
    ]);

    $html = test()->get('http://drawer-florist.example/collections/cakes')->assertOk()->getContent();

    expect($html)->toContain('Colour')
        ->and($html)->toContain('Blush (1)')
        ->and($html)->not->toContain('Sponge');
});

test('applying category and price checkboxes filters the grid and updates the count', function () {
    $rose = [
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
        'f' => ['c' => ['cakes', 'wedding-cakes'], 'p' => 4500, 'a' => 'in', 'o' => []],
    ];
    $lily = $rose;
    $lily['id'] = 2;
    $lily['slug'] = 'lily';
    $lily['product_card'] = ['slug' => 'lily', 'name' => 'White Lily', 'price_display' => '£12.00'];
    $lily['price_cents'] = 1200;
    $lily['f'] = ['c' => ['cakes'], 'p' => 1200, 'a' => 'in', 'o' => []];

    $site = Site::factory()->create([
        'custom_domain' => 'drawer-apply.example',
        'custom_domain_status' => 'active',
        'business_name' => 'Bloom & Stem',
    ]);
    Product::factory()->published()->for($site)->create(['slug' => 'row', 'name' => 'Row']);
    $facets = [
        'category' => [
            ['slug' => 'wedding-cakes', 'name' => 'Wedding Cakes', 'count' => 1],
        ],
        'price' => [
            ['id' => 0, 'min' => 0, 'max' => 2000, 'label' => 'Under £20.00', 'count' => 1],
            ['id' => 2, 'min' => 4000, 'max' => null, 'label' => '£40.00+', 'count' => 1],
        ],
        'availability' => [],
        'options' => [],
    ];
    $json = [
        'meta' => ['site_id' => $site->id, 'product_count' => 2, 'currency' => 'GBP'],
        'categories' => [
            'cakes' => [
                'id' => 1,
                'slug' => 'cakes',
                'name' => 'Cakes',
                'path' => 'cakes',
                'visibility' => 'visible',
                'children' => [],
                'product_slugs' => ['rose', 'lily'],
                'breadcrumb' => [['name' => 'Cakes', 'path' => 'cakes']],
                'facets' => $facets,
            ],
        ],
        'category_paths' => ['cakes' => 'cakes'],
        'products' => ['rose' => $rose, 'lily' => $lily],
        'featured_slugs' => ['rose', 'lily'],
        'facets' => $facets,
    ];
    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => $json,
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snap->id, 'updated_at' => now()]);

    $plain = test()->get('http://drawer-apply.example/collections/cakes')->assertOk()->getContent();
    expect($plain)->toContain('Showing 2 items')
        ->and($plain)->toContain('Red Rose')
        ->and($plain)->toContain('White Lily');

    $filtered = test()->get('http://drawer-apply.example/collections/cakes?cat[]=wedding-cakes&price[]=2')->assertOk()->getContent();
    $names = [];
    preg_match_all('/<div class="font-semibold break-words">([^<]+)<\/div>/', $filtered, $names);

    expect($filtered)->toContain('Showing 1 items')
        ->and($names[1])->toBe(['Red Rose'])
        ->and($filtered)->toMatch('/<details\b[^>]*open/')
        ->and($filtered)->toContain('checked');
});

test('active filters render as removable pills that keep sort and the other filters', function () {
    shopDrawerSite('drawer-pills.example');

    $html = test()->get('http://drawer-pills.example/collections/cakes?sort=newest&price[]=2&cat[]=wedding-cakes')->assertOk()->getContent();

    expect($html)->toMatch('/aria-label="Active filters"/')
        ->and($html)->toContain('£40.00+')
        ->and($html)->toContain('Wedding Cakes')
        ->and($html)->toMatch('/href="[^"]*sort=newest[^"]*"/')
        ->and($html)->toMatch('/Active filters[\s\S]*×/');
});
