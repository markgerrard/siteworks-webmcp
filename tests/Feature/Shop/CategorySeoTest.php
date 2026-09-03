<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;

/**
 * @param  array<string, mixed>  $categoryOverrides
 * @return array{0: Site, 1: array<string, mixed>}
 */
function categorySeoSite(string $host, array $categories, array $categoryPaths, array $products = []): array
{
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'business_name' => 'Bloom & Stem',
    ]);

    if ($products === []) {
        $products = [
            'seo-stub' => [
                'id' => 99,
                'slug' => 'seo-stub',
                'status' => 'published',
                'primary_category_slug' => array_key_first($categories),
                'price_cents' => 1000,
                'price_display' => '£10.00',
                'in_stock_any' => true,
                'variant_in_stock' => [1 => true],
                'image_urls' => null,
                'product_card' => ['slug' => 'seo-stub', 'name' => 'Stub', 'price_display' => '£10.00'],
                'product_detail' => ['slug' => 'seo-stub', 'name' => 'Stub', 'description' => 'Stub'],
                'variants' => [],
                'is_ai_seeded' => false,
                'is_ai_reviewed' => false,
            ],
        ];
    }

    $json = [
        'meta' => ['site_id' => $site->id, 'product_count' => count($products), 'currency' => 'GBP'],
        'category_paths' => $categoryPaths,
        'categories' => $categories,
        'products' => $products,
        'featured_slugs' => [],
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

    return [$site, $json];
}

function categorySeoJsonLd(string $html): array
{
    expect($html)->toMatch('/<script type="application\/ld\+json">/');
    preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $match);
    expect($match)->not->toBeEmpty();

    $decoded = json_decode($match[1], true);
    expect($decoded)->toBeArray();

    return $decoded;
}

test('a depth-1 category page emits title, meta, canonical and BreadcrumbList JSON-LD', function () {
    categorySeoSite('seo-cakes.example', [
        'cakes' => [
            'id' => 1,
            'slug' => 'cakes',
            'name' => 'Cakes',
            'path' => 'cakes',
            'depth' => 1,
            'visibility' => 'visible',
            'parent_slug' => null,
            'meta_title' => null,
            'meta_description' => null,
            'description' => 'Celebration cakes baked to order in the village bakery every morning.',
            'children' => [],
            'breadcrumb' => [['name' => 'Cakes', 'path' => 'cakes']],
            'product_slugs' => [],
        ],
    ], ['cakes' => 'cakes']);

    $html = $this->get('http://seo-cakes.example/collections/cakes')->assertOk()->getContent();

    expect($html)->toContain('<title>Cakes — Bloom &amp; Stem</title>')
        ->and($html)->toContain('<meta name="description" content="Celebration cakes baked to order in the village bakery every morning.">')
        ->and($html)->toContain('<link rel="canonical" href="http://seo-cakes.example/collections/cakes">')
        ->and($html)->not->toContain('name="robots"');

    $ld = categorySeoJsonLd($html);
    expect($ld['@type'])->toBe('BreadcrumbList')
        ->and($ld['itemListElement'])->toHaveCount(3)
        ->and($ld['itemListElement'][0])->toMatchArray([
            '@type' => 'ListItem',
            'position' => 1,
            'name' => 'Home',
            'item' => 'http://seo-cakes.example',
        ])
        ->and($ld['itemListElement'][1])->toMatchArray([
            '@type' => 'ListItem',
            'position' => 2,
            'name' => 'Shop',
            'item' => 'http://seo-cakes.example/shop',
        ])
        ->and($ld['itemListElement'][2])->toMatchArray([
            '@type' => 'ListItem',
            'position' => 3,
            'name' => 'Cakes',
            'item' => 'http://seo-cakes.example/collections/cakes',
        ]);
});

test('a depth-3 category page uses meta fields and nested BreadcrumbList URLs', function () {
    categorySeoSite('seo-nested.example', [
        'cakes' => [
            'id' => 1,
            'slug' => 'cakes',
            'name' => 'Cakes',
            'path' => 'cakes',
            'depth' => 1,
            'visibility' => 'visible',
            'parent_slug' => null,
            'children' => ['wedding-cakes'],
            'breadcrumb' => [['name' => 'Cakes', 'path' => 'cakes']],
            'product_slugs' => [],
        ],
        'wedding-cakes' => [
            'id' => 2,
            'slug' => 'wedding-cakes',
            'name' => 'Wedding Cakes',
            'path' => 'cakes/wedding-cakes',
            'depth' => 2,
            'visibility' => 'visible',
            'parent_slug' => 'cakes',
            'children' => ['tiered'],
            'breadcrumb' => [
                ['name' => 'Cakes', 'path' => 'cakes'],
                ['name' => 'Wedding Cakes', 'path' => 'cakes/wedding-cakes'],
            ],
            'product_slugs' => [],
        ],
        'tiered' => [
            'id' => 3,
            'slug' => 'tiered',
            'name' => 'Tiered',
            'path' => 'cakes/wedding-cakes/tiered',
            'depth' => 3,
            'visibility' => 'visible',
            'parent_slug' => 'wedding-cakes',
            'meta_title' => 'Tiered wedding cakes Palo Alto',
            'meta_description' => 'Three-tier celebration cakes for Palo Alto weddings.',
            'description' => 'Ignored because meta_description is set.',
            'children' => [],
            'breadcrumb' => [
                ['name' => 'Cakes', 'path' => 'cakes'],
                ['name' => 'Wedding Cakes', 'path' => 'cakes/wedding-cakes'],
                ['name' => 'Tiered', 'path' => 'cakes/wedding-cakes/tiered'],
            ],
            'product_slugs' => [],
        ],
    ], [
        'cakes' => 'cakes',
        'cakes/wedding-cakes' => 'wedding-cakes',
        'cakes/wedding-cakes/tiered' => 'tiered',
    ]);

    $html = $this->get('http://seo-nested.example/collections/cakes/wedding-cakes/tiered')->assertOk()->getContent();

    expect($html)->toContain('<title>Tiered wedding cakes Palo Alto</title>')
        ->and($html)->toContain('<meta name="description" content="Three-tier celebration cakes for Palo Alto weddings.">')
        ->and($html)->toContain('<link rel="canonical" href="http://seo-nested.example/collections/cakes/wedding-cakes/tiered">');

    $ld = categorySeoJsonLd($html);
    $items = collect($ld['itemListElement'])->pluck('item')->all();
    expect($items)->toBe([
        'http://seo-nested.example',
        'http://seo-nested.example/shop',
        'http://seo-nested.example/collections/cakes',
        'http://seo-nested.example/collections/cakes/wedding-cakes',
        'http://seo-nested.example/collections/cakes/wedding-cakes/tiered',
    ]);
});

test('a hidden category is noindexed but still routable', function () {
    categorySeoSite('seo-hidden.example', [
        'secret' => [
            'id' => 1,
            'slug' => 'secret',
            'name' => 'Secret',
            'path' => 'secret',
            'depth' => 1,
            'visibility' => 'hidden',
            'parent_slug' => null,
            'children' => [],
            'breadcrumb' => [['name' => 'Secret', 'path' => 'secret']],
            'product_slugs' => [],
        ],
    ], ['secret' => 'secret']);

    $html = $this->get('http://seo-hidden.example/collections/secret')->assertOk()->getContent();

    expect($html)->toContain('<meta name="robots" content="noindex">')
        ->and($html)->toContain('<link rel="canonical" href="http://seo-hidden.example/collections/secret">');
});
