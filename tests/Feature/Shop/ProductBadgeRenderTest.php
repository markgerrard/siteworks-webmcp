<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\Product;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use App\Support\Shop\ProductBadges;

function badgeRenderProduct(array $tags): array
{
    return [
        'id' => 1,
        'slug' => 'item',
        'status' => 'published',
        'primary_category_slug' => 'range',
        'price_cents' => 4500,
        'price_display' => '£45.00',
        'in_stock_any' => true,
        'variant_in_stock' => [11 => true],
        'image_urls' => ['thumb' => '/a.jpg', 'card' => '/a.jpg', 'full' => '/a.jpg'],
        'product_card' => ['slug' => 'item', 'name' => 'Seasonal item', 'price_display' => '£45.00'],
        'product_detail' => ['slug' => 'item', 'name' => 'Seasonal item', 'description' => 'An item'],
        'variants' => [['id' => 11, 'sku' => 'B1', 'label' => 'Std', 'price_cents' => 4500, 'image_urls' => null]],
        'is_ai_seeded' => false,
        'is_ai_reviewed' => false,
        'tags' => $tags,
    ];
}

function badgeRenderSite(string $host, array $product): Site
{
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'business_name' => 'Badge Shop',
        'shop_mode' => 'cart',
    ]);
    Product::factory()->published()->for($site)->create(['slug' => $product['slug'], 'name' => $product['product_card']['name']]);
    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'product_count' => 1,
        'json' => [
            'meta' => ['site_id' => $site->id, 'product_count' => 1],
            'categories' => [],
            'products' => [$product['slug'] => $product],
            'featured_slugs' => [$product['slug']],
        ],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snap->id, 'updated_at' => now()]);

    return $site;
}

it('shows at most two badge pills on the card, manual first, and skips non-badge tags', function () {
    $tags = [
        ['slug' => 'same-day', 'label' => 'Same day', 'badge' => true, 'tone' => 'accent'],
        ['slug' => 'gift', 'label' => 'Gift', 'badge' => false, 'tone' => 'neutral'],
        ['slug' => 'seasonal', 'label' => 'Seasonal', 'badge' => true, 'tone' => 'warning'],
        ['slug' => 'new', 'label' => 'New', 'badge' => true, 'tone' => 'success'],
    ];
    expect(array_column(ProductBadges::visible($tags, 2), 'slug'))->toBe(['same-day', 'seasonal']);

    badgeRenderSite('badge-card.example', badgeRenderProduct($tags));
    $html = $this->get('http://badge-card.example/shop')->assertSuccessful()->getContent();

    expect($html)->toContain('Same day')
        ->and($html)->toContain('Seasonal')
        ->and(substr_count($html, 'Same day'))->toBeGreaterThan(0);
});

it('renders PDP badges inline after the title', function () {
    $tags = [
        ['slug' => 'same-day', 'label' => 'Same day', 'badge' => true, 'tone' => 'accent'],
        ['slug' => 'new', 'label' => 'New', 'badge' => true, 'tone' => 'success'],
    ];
    badgeRenderSite('badge-pdp.example', badgeRenderProduct($tags));
    $html = $this->get('http://badge-pdp.example/products/item')->assertSuccessful()->getContent();

    expect($html)->toMatch('/<h1[^>]*>Seasonal item<\/h1>[\s\S]{0,400}Same day/')
        ->and($html)->toContain('New');
});

it('emits no badge markup when the product has no badge tags', function () {
    badgeRenderSite('badge-none.example', badgeRenderProduct([]));
    $html = $this->get('http://badge-none.example/shop')->assertSuccessful()->getContent();

    expect($html)->not->toContain('Same day')
        ->and($html)->not->toContain('product-badges');
});

it('escapes a script payload in badge labels', function () {
    $payload = '<script>alert(1)</script>" onclick="alert(1)';
    badgeRenderSite('badge-xss.example', badgeRenderProduct([
        ['slug' => 'xss', 'label' => $payload, 'badge' => true, 'tone' => 'accent'],
    ]));
    $html = $this->get('http://badge-xss.example/shop')->assertSuccessful()->getContent();

    expect($html)->not->toContain($payload)
        ->and($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain(e($payload));
});
