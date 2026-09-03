<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;

/**
 * @return array{site: Site}
 */
function shopIndexSeoSite(string $host): array
{
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'business_name' => 'Bloom & Stem',
    ]);

    $json = [
        'meta' => ['site_id' => $site->id, 'product_count' => 1, 'currency' => 'GBP'],
        'categories' => [],
        'products' => [
            'rose' => [
                'id' => 1,
                'slug' => 'rose',
                'status' => 'published',
                'primary_category_slug' => null,
                'price_cents' => 4500,
                'price_display' => '£45.00',
                'in_stock_any' => true,
                'variant_in_stock' => [1 => true],
                'image_urls' => ['thumb' => '/a.jpg', 'card' => '/a.jpg', 'full' => '/a.jpg'],
                'product_card' => ['slug' => 'rose', 'name' => 'Red Rose', 'price_display' => '£45.00'],
                'product_detail' => ['slug' => 'rose', 'name' => 'Red Rose', 'description' => 'Lovely'],
                'variants' => [['id' => 1, 'sku' => 'RR-1', 'label' => 'Std', 'price_cents' => 4500, 'image_urls' => null]],
                'is_ai_seeded' => false,
                'is_ai_reviewed' => false,
            ],
        ],
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

    return ['site' => $site];
}

function shopIndexJsonLdBlocks(string $html): array
{
    preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches);
    expect($matches[1])->not->toBeEmpty();

    return array_map(fn (string $raw) => json_decode($raw, true), $matches[1]);
}

test('the shop index has a title and canonical link but no query string', function () {
    shopIndexSeoSite('index-seo-meta.example');

    $html = $this->get('http://index-seo-meta.example/shop?utm_source=x')->assertOk()->getContent();

    expect($html)->toContain('<title>Shop — Bloom &amp; Stem</title>')
        ->and($html)->toContain('<link rel="canonical" href="http://index-seo-meta.example/shop">')
        ->and($html)->not->toContain('utm_source');
});

test('the shop index emits a two-crumb BreadcrumbList', function () {
    shopIndexSeoSite('index-seo.example');

    $html = $this->get('http://index-seo.example/shop')->assertOk()->getContent();

    $blocks = shopIndexJsonLdBlocks($html);
    $breadcrumbs = collect($blocks)->firstWhere('@type', 'BreadcrumbList');

    expect($breadcrumbs)->toBeArray()
        ->and($breadcrumbs['itemListElement'])->toHaveCount(2)
        ->and($breadcrumbs['itemListElement'][0])->toMatchArray([
            '@type' => 'ListItem',
            'position' => 1,
            'name' => 'Home',
        ])
        ->and($breadcrumbs['itemListElement'][1])->toMatchArray([
            '@type' => 'ListItem',
            'position' => 2,
            'name' => 'Shop',
            'item' => 'http://index-seo.example/shop',
        ]);
});

test('the shop index emits exactly one BreadcrumbList', function () {
    shopIndexSeoSite('index-seo-once.example');

    $html = $this->get('http://index-seo-once.example/shop')->assertOk()->getContent();

    $blocks = collect(shopIndexJsonLdBlocks($html))->where('@type', 'BreadcrumbList');

    expect($blocks)->toHaveCount(1);
});
