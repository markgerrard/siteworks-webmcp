<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\Product;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;

function shopListingSeoSite(string $host, int $count = 30, ?int $pageSize = 12, bool $hasCategories = true): Site
{
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'business_name' => 'Bloom & Stem',
        'shop_page_size' => $pageSize,
    ]);
    Product::factory()->published()->for($site)->create(['slug' => 'row', 'name' => 'Row']);

    $products = [];
    $slugs = [];
    for ($i = 0; $i < $count; $i++) {
        $slug = 'p'.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        $slugs[] = $slug;
        $products[$slug] = [
            'id' => $i + 1,
            'slug' => $slug,
            'status' => 'published',
            'price_cents' => ($i + 1) * 1000,
            'price_display' => '£'.number_format($i + 1, 2),
            'in_stock_any' => true,
            'variant_in_stock' => [1 => true],
            'image_urls' => null,
            'product_card' => ['slug' => $slug, 'name' => $slug, 'price_display' => '£10.00'],
            'product_detail' => ['slug' => $slug, 'name' => $slug, 'description' => $slug],
            'variants' => [['id' => $i + 1, 'sku' => $slug, 'label' => 'Std', 'price_cents' => ($i + 1) * 1000, 'image_urls' => null]],
            'is_ai_seeded' => false,
            'is_ai_reviewed' => false,
            'f' => ['c' => ['cakes'], 'p' => ($i + 1) * 1000, 'a' => 'in', 'o' => []],
        ];
    }

    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => [
            'meta' => ['site_id' => $site->id, 'product_count' => $count],
            'categories' => $hasCategories ? [
                'cakes' => [
                    'id' => 1,
                    'slug' => 'cakes',
                    'name' => 'Cakes',
                    'path' => 'cakes',
                    'visibility' => 'visible',
                    'product_slugs' => $slugs,
                    'breadcrumb' => [['name' => 'Cakes', 'path' => 'cakes']],
                    'facets' => [
                        'category' => [],
                        'price' => [
                            ['id' => 3, 'min' => 8000, 'max' => null, 'label' => '£80.00+', 'count' => 23],
                        ],
                    ],
                ],
            ] : [],
            'category_paths' => $hasCategories ? ['cakes' => 'cakes'] : [],
            'products' => $products,
            'featured_slugs' => [],
        ],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snap->id, 'updated_at' => now()]);

    return $site;
}

test('sorted and filtered category pages canonicalise to the clean category url', function () {
    shopListingSeoSite('seo-sort.example');

    $sorted = test()->get('http://seo-sort.example/collections/cakes?sort=price_desc')->assertOk()->getContent();
    $filtered = test()->get('http://seo-sort.example/collections/cakes?price[]=3')->assertOk()->getContent();

    expect($sorted)->toContain('<link rel="canonical" href="http://seo-sort.example/collections/cakes">')
        ->and($sorted)->not->toContain('canonical" href="http://seo-sort.example/collections/cakes?sort=')
        ->and($filtered)->toContain('<link rel="canonical" href="http://seo-sort.example/collections/cakes">');
});

test('paged category pages are indexable and emit rel prev and next', function () {
    shopListingSeoSite('seo-page.example');

    $html = test()->get('http://seo-page.example/collections/cakes?page=2')->assertOk()->getContent();

    expect($html)->toContain('<link rel="canonical" href="http://seo-page.example/collections/cakes?page=2">')
        ->and($html)->toContain('<link rel="prev" href="http://seo-page.example/collections/cakes">')
        ->and($html)->toContain('<link rel="next" href="http://seo-page.example/collections/cakes?page=3">')
        ->and($html)->not->toContain('name="robots"');
});

test('paged plain shop grids self-canonicalise and emit rel prev and next', function () {
    shopListingSeoSite('seo-shop-page.example', hasCategories: false);

    $html = test()->get('http://seo-shop-page.example/shop?page=2')->assertOk()->getContent();

    expect($html)->toContain('<link rel="canonical" href="http://seo-shop-page.example/shop?page=2">')
        ->and($html)->toContain('<link rel="prev" href="http://seo-shop-page.example/shop">')
        ->and($html)->toContain('<link rel="next" href="http://seo-shop-page.example/shop?page=3">');
});

test('sorted plain shop grids canonicalise to the unsorted shop url while rel links retain sort', function () {
    shopListingSeoSite('seo-shop-sort.example', hasCategories: false);

    $html = test()->get('http://seo-shop-sort.example/shop?sort=price_desc&page=2')->assertOk()->getContent();

    expect($html)->toContain('<link rel="canonical" href="http://seo-shop-sort.example/shop">')
        ->and($html)->toContain('<link rel="prev" href="http://seo-shop-sort.example/shop?sort=price_desc">')
        ->and($html)->toContain('<link rel="next" href="http://seo-shop-sort.example/shop?sort=price_desc&amp;page=3">')
        ->and($html)->not->toContain('canonical" href="http://seo-shop-sort.example/shop?sort=');
});
