<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\Product;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use App\Services\Shop\CatalogueListing;

test('null page size keeps the whole snapshot list on one page', function () {
    $products = [];
    for ($i = 0; $i < 30; $i++) {
        $products[] = ['id' => $i + 1, 'slug' => 'p'.$i, 'price_cents' => 1000 + $i];
    }

    $listing = CatalogueListing::apply($products, ['page' => 2], [], null);

    expect($listing['page'])->toBe(1)
        ->and($listing['lastPage'])->toBe(1)
        ->and($listing['products'])->toHaveCount(30);
});

test('page size 12 paginates a 30-product list and clamps out of range pages', function () {
    $products = [];
    for ($i = 0; $i < 30; $i++) {
        $products[] = ['id' => $i + 1, 'slug' => 'p'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'price_cents' => 1000];
    }

    $pageTwo = CatalogueListing::apply($products, ['page' => 2], [], 12);
    expect($pageTwo['page'])->toBe(2)
        ->and($pageTwo['lastPage'])->toBe(3)
        ->and(array_column($pageTwo['products'], 'slug'))->toBe([
            'p12', 'p13', 'p14', 'p15', 'p16', 'p17', 'p18', 'p19', 'p20', 'p21', 'p22', 'p23',
        ]);

    $clamped = CatalogueListing::apply($products, ['page' => 99], [], 12);
    expect($clamped['page'])->toBe(3)
        ->and($clamped['products'])->toHaveCount(6);

    $nonNumeric = CatalogueListing::apply($products, ['page' => 'nope'], [], 12);
    expect($nonNumeric['page'])->toBe(1)
        ->and($nonNumeric['products'])->toHaveCount(12);
});

test('a category page with shop_page_size 12 renders numbered pagination', function () {
    $site = Site::factory()->create([
        'custom_domain' => 'page-cat.example',
        'custom_domain_status' => 'active',
        'business_name' => 'Bloom & Stem',
        'shop_page_size' => 12,
    ]);
    Product::factory()->published()->for($site)->create(['slug' => 'row', 'name' => 'Row']);

    $products = [];
    $slugs = [];
    for ($i = 0; $i < 30; $i++) {
        $slug = 'p'.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        $slugs[] = $slug;
        $products[$slug] = [
            'id' => $i + 1,
            'slug' => $slug,
            'status' => 'published',
            'price_cents' => 1000,
            'price_display' => '£10.00',
            'in_stock_any' => true,
            'variant_in_stock' => [1 => true],
            'image_urls' => null,
            'product_card' => ['slug' => $slug, 'name' => $slug, 'price_display' => '£10.00'],
            'product_detail' => ['slug' => $slug, 'name' => $slug, 'description' => $slug],
            'variants' => [['id' => $i + 1, 'sku' => $slug, 'label' => 'Std', 'price_cents' => 1000, 'image_urls' => null]],
            'is_ai_seeded' => false,
            'is_ai_reviewed' => false,
        ];
    }

    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => [
            'meta' => ['site_id' => $site->id, 'product_count' => 30],
            'categories' => [
                'cakes' => [
                    'id' => 1,
                    'slug' => 'cakes',
                    'name' => 'Cakes',
                    'path' => 'cakes',
                    'visibility' => 'visible',
                    'product_slugs' => $slugs,
                    'breadcrumb' => [['name' => 'Cakes', 'path' => 'cakes']],
                ],
            ],
            'category_paths' => ['cakes' => 'cakes'],
            'products' => $products,
            'featured_slugs' => [],
        ],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snap->id, 'updated_at' => now()]);

    $html = test()->get('http://page-cat.example/collections/cakes?page=2')->assertOk()->getContent();

    expect($html)->toMatch('/aria-label="Pagination"/')
        ->and($html)->toContain('First')
        ->and($html)->toContain('Prev')
        ->and($html)->toContain('Next')
        ->and($html)->toContain('Last')
        ->and($html)->toMatch('/aria-current="page"[^>]*>2</')
        ->and($html)->toContain('p12');

    preg_match_all('/<div class="font-semibold break-words">([^<]+)<\/div>/', $html, $names);
    expect($names[1])->toContain('p12')
        ->and($names[1])->not->toContain('p00')
        ->and($names[1])->toHaveCount(12);
});
