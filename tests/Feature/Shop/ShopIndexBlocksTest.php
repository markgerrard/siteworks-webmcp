<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\Product;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use App\Models\SiteReview;

function shopIndexBlocksSite(string $host, array $products, array $blocks): Site
{
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'business_name' => 'Index Blocks',
        'shop_mode' => 'cart',
        'shop_index_blocks' => $blocks,
    ]);
    $bySlug = [];
    foreach ($products as $product) {
        Product::factory()->published()->for($site)->create(['slug' => $product['slug'], 'name' => $product['name']]);
        $bySlug[$product['slug']] = [
            'id' => $product['id'],
            'slug' => $product['slug'],
            'status' => 'published',
            'price_cents' => 4500,
            'price_display' => '£45.00',
            'in_stock_any' => true,
            'image_urls' => ['card' => '/'.$product['slug'].'.jpg'],
            'product_card' => ['slug' => $product['slug'], 'name' => $product['name'], 'price_display' => '£45.00'],
            'variants' => [['id' => $product['id'] * 10, 'sku' => 'S', 'label' => 'Std', 'price_cents' => 4500]],
            'tags' => $product['tags'] ?? [],
        ];
    }
    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'product_count' => count($bySlug),
        'json' => [
            'meta' => ['site_id' => $site->id, 'product_count' => count($bySlug)],
            'categories' => [],
            'products' => $bySlug,
            'featured_slugs' => [],
            'hero_image_url' => '/shop-hero.jpg',
        ],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snap->id, 'updated_at' => now()]);

    return $site;
}

it('hides a shop-index block for auto sources when fewer than two products match', function (string $host, string $source) {
    shopIndexBlocksSite($host, [
        ['id' => 1, 'slug' => 'alpha', 'name' => 'Alpha item', 'tags' => [['slug' => 'gift', 'label' => 'Gift', 'badge' => true, 'tone' => 'neutral']]],
    ], [
        ['source' => $source, 'limit' => 4, 'layout' => 'grid', 'heading' => 'Lonely row'],
    ]);

    $html = $this->get('http://'.$host.'/shop')->assertSuccessful()->getContent();

    expect($html)->not->toContain('Lonely row');
})->with([
    'newest' => ['index-pair-newest.example', 'newest'],
    'tag' => ['index-pair-tag.example', 'tag:gift'],
    'category' => ['index-pair-category.example', 'category:range'],
]);

it('renders a single-product shop-index block for manual and featured sources', function (string $host, string $source) {
    shopIndexBlocksSite($host, [
        ['id' => 1, 'slug' => 'alpha', 'name' => 'Alpha item'],
    ], [
        ['source' => $source, 'limit' => 4, 'layout' => 'grid', 'heading' => 'Lonely row'],
    ]);

    $html = $this->get('http://'.$host.'/shop')->assertSuccessful()->getContent();

    expect($html)->toContain('Lonely row')
        ->and($html)->toContain('Alpha item');
})->with([
    'manual' => ['index-pair-manual.example', 'manual'],
    'featured' => ['index-pair-featured.example', 'featured'],
]);

it('renders shop index blocks above the grid and hides a block with fewer than two products', function () {
    $gift = ['slug' => 'gift', 'label' => 'Gift', 'badge' => true, 'tone' => 'neutral'];
    shopIndexBlocksSite('index-blocks.example', [
        ['id' => 1, 'slug' => 'alpha', 'name' => 'Alpha item', 'tags' => [$gift]],
        ['id' => 2, 'slug' => 'bravo', 'name' => 'Bravo item', 'tags' => [$gift]],
        ['id' => 3, 'slug' => 'solo', 'name' => 'Solo item', 'tags' => [['slug' => 'rare', 'label' => 'Rare', 'badge' => true, 'tone' => 'accent']]],
    ], [
        ['source' => 'tag:gift', 'limit' => 4, 'layout' => 'grid', 'heading' => 'Gift picks'],
        ['source' => 'tag:rare', 'limit' => 4, 'layout' => 'carousel', 'heading' => 'Rare finds'],
    ]);

    $html = $this->get('http://index-blocks.example/shop')->assertSuccessful()->getContent();

    expect($html)->toContain('Gift picks')
        ->and($html)->toContain('Alpha item')
        ->and($html)->toContain('Bravo item')
        ->and($html)->not->toContain('Rare finds');

    $hero = strpos($html, '/shop-hero.jpg');
    $block = strpos($html, 'Gift picks');
    $grid = strpos($html, 'max-w-full py-8 md:py-10');
    expect($hero)->toBeInt()->and($block)->toBeInt()->and($grid)->toBeInt()
        ->and($block)->toBeGreaterThan($hero)
        ->and($block)->toBeLessThan($grid);
});

it('renders a trust strip block through the shared section partial above the shop grid', function () {
    $site = shopIndexBlocksSite('index-trust.example', [
        ['id' => 1, 'slug' => 'alpha', 'name' => 'Alpha item'],
    ], [[
        'type' => 'trust_strip',
        'sources' => 'site',
        'layout' => 'carousel',
        'heading' => 'Shared customer voices',
        'reviews_label' => 'recommendations',
        'min_reviews' => 3,
        'external' => [
            'label' => 'Independent score',
            'url' => 'https://reviews.example.test/profile',
            'rating' => 4.8,
            'count' => 24,
        ],
    ]]);
    SiteReview::factory()->approved()->count(3)->for($site)->create();

    $html = $this->get('http://index-trust.example/shop')->assertSuccessful()->getContent();

    expect($html)
        ->toContain('Shared customer voices')
        ->toContain('data-trust-strip')
        ->toContain('scroll-snap-type: x mandatory')
        ->toContain('Independent score');

    $hero = strpos($html, '/shop-hero.jpg');
    $block = strpos($html, 'Shared customer voices');
    $grid = strpos($html, 'max-w-full py-8 md:py-10');
    expect($hero)->toBeInt()->and($block)->toBeInt()->and($grid)->toBeInt()
        ->and($block)->toBeGreaterThan($hero)
        ->and($block)->toBeLessThan($grid);
});

it('hides a below-threshold trust block on the shop index', function () {
    $site = shopIndexBlocksSite('index-trust-hidden.example', [
        ['id' => 1, 'slug' => 'alpha', 'name' => 'Alpha item'],
    ], [[
        'type' => 'trust_strip',
        'sources' => 'site',
        'layout' => 'strip',
        'heading' => 'Not enough voices',
        'reviews_label' => 'reviews',
        'min_reviews' => 3,
    ]]);
    SiteReview::factory()->approved()->count(2)->for($site)->create();

    $html = $this->get('http://index-trust-hidden.example/shop')->assertSuccessful()->getContent();

    expect($html)->not->toContain('Not enough voices');
});
