<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Http\Controllers\Shop\CartController;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Services\Shop\CartService;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{site: Site, homeHref: string}
 */
function breadcrumbShopSite(): array
{
    $site = Site::factory()->create([
        'custom_domain' => 'flowers.example',
        'custom_domain_status' => 'active',
        'business_name' => 'Bloom & Stem',
    ]);

    $json = [
        'meta' => ['site_id' => $site->id, 'product_count' => 2],
        'categories' => [
            'bouquets' => [
                'id' => 1,
                'slug' => 'bouquets',
                'name' => 'Bouquets',
                'product_slugs' => ['rose'],
            ],
        ],
        'products' => [
            'rose' => [
                'id' => 1,
                'slug' => 'rose',
                'status' => 'published',
                'primary_category_slug' => 'bouquets',
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
            'tulip' => [
                'id' => 2,
                'slug' => 'tulip',
                'status' => 'published',
                'primary_category_slug' => null,
                'price_cents' => 1200,
                'price_display' => '£12.00',
                'in_stock_any' => true,
                'variant_in_stock' => [2 => true],
                'image_urls' => ['thumb' => '/b.jpg', 'card' => '/b.jpg', 'full' => '/b.jpg'],
                'product_card' => ['slug' => 'tulip', 'name' => 'Yellow Tulip', 'price_display' => '£12.00'],
                'product_detail' => ['slug' => 'tulip', 'name' => 'Yellow Tulip', 'description' => 'Bright'],
                'variants' => [['id' => 2, 'sku' => 'YT-1', 'label' => 'Std', 'price_cents' => 1200, 'image_urls' => null]],
                'is_ai_seeded' => false,
                'is_ai_reviewed' => false,
            ],
        ],
        'featured_slugs' => ['rose'],
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

    $homeHref = app(PageRenderer::class)->layoutContext($site)['homeHref'];

    return ['site' => $site, 'homeHref' => $homeHref];
}

/**
 * @return array{labels: list<string>, items: list<array{attrs: string, html: string}>}
 */
function parseBreadcrumbList(string $html): array
{
    expect($html)->toMatch('/<nav\b[^>]*aria-label="Breadcrumb"[^>]*>/i');

    preg_match(
        '/<nav\b[^>]*aria-label="Breadcrumb"[^>]*>(.*?)<\/nav>/is',
        $html,
        $nav,
    );
    expect($nav)->not->toBeEmpty();

    preg_match('/<ol\b[^>]*>(.*?)<\/ol>/is', $nav[1], $ol);
    expect($ol)->not->toBeEmpty();

    preg_match_all('/<li\b([^>]*)>(.*?)<\/li>/is', $ol[1], $matches, PREG_SET_ORDER);
    expect($matches)->not->toBeEmpty();

    $items = [];
    $labels = [];
    foreach ($matches as $match) {
        $items[] = ['attrs' => $match[1], 'html' => $match[2]];
        $labels[] = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5));
    }

    return ['labels' => $labels, 'items' => $items];
}

/**
 * @param  list<string>  $expected
 */
function assertBreadcrumbTrail(string $html, array $expected, string $homeHref): void
{
    $parsed = parseBreadcrumbList($html);

    expect($parsed['labels'])->toBe($expected);

    $last = $parsed['items'][array_key_last($parsed['items'])];
    expect($last['attrs'])->toContain('aria-current="page"')
        ->and($last['html'])->not->toMatch('/<a\b/i');

    preg_match('/<nav\b[^>]*aria-label="Breadcrumb"[^>]*>(.*?)<\/nav>/is', $html, $nav);
    expect($nav[1])->not->toContain('truncate')
        ->and($nav[1])->not->toContain('ellipsis')
        ->and($nav[1])->toContain('flex-wrap');

    preg_match('/<a\b[^>]*href="([^"]*)"[^>]*>\s*Home\s*<\/a>/i', $nav[1], $home);
    expect($home)->not->toBeEmpty();

    $href = html_entity_decode($home[1], ENT_QUOTES | ENT_HTML5);
    $path = parse_url($href, PHP_URL_PATH);
    if ($path === null) {
        $path = $href;
    }

    $expectedPath = parse_url($homeHref, PHP_URL_PATH) ?? $homeHref;

    expect($path)->toBe($expectedPath)
        ->and($path)->not->toBe('/shop');
}

test('shop index shows no visible breadcrumb but keeps BreadcrumbList JSON-LD', function () {
    breadcrumbShopSite();

    $html = $this->get('http://flowers.example/shop')->assertOk()->getContent();

    expect(str_contains($html, 'aria-label="Breadcrumb"'))->toBeFalse()
        ->and(str_contains($html, '"BreadcrumbList"'))->toBeTrue();
});

test('nested category breadcrumbs include ancestors', function () {
    $site = Site::factory()->create([
        'custom_domain' => 'nested.example',
        'custom_domain_status' => 'active',
        'business_name' => 'Bloom & Stem',
    ]);
    $homeHref = app(PageRenderer::class)->layoutContext($site)['homeHref'];

    $json = [
        'meta' => ['site_id' => $site->id, 'product_count' => 1],
        'category_paths' => [
            'cakes' => 'cakes',
            'cakes/wedding-cakes' => 'wedding-cakes',
        ],
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
                'breadcrumb' => [
                    ['name' => 'Cakes', 'path' => 'cakes'],
                ],
                'product_slugs' => ['tiered'],
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
                'breadcrumb' => [
                    ['name' => 'Cakes', 'path' => 'cakes'],
                    ['name' => 'Wedding Cakes', 'path' => 'cakes/wedding-cakes'],
                ],
                'product_slugs' => ['tiered'],
            ],
        ],
        'products' => [
            'tiered' => [
                'id' => 1,
                'slug' => 'tiered',
                'status' => 'published',
                'primary_category_slug' => 'wedding-cakes',
                'price_cents' => 8500,
                'price_display' => '£85.00',
                'in_stock_any' => true,
                'variant_in_stock' => [1 => true],
                'image_urls' => ['thumb' => '/a.jpg', 'card' => '/a.jpg', 'full' => '/a.jpg'],
                'product_card' => ['slug' => 'tiered', 'name' => 'Three Tier', 'price_display' => '£85.00'],
                'product_detail' => ['slug' => 'tiered', 'name' => 'Three Tier', 'description' => 'Tall'],
                'variants' => [['id' => 1, 'sku' => 'TT-1', 'label' => 'Std', 'price_cents' => 8500, 'image_urls' => null]],
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

    $html = $this->get('http://nested.example/collections/cakes/wedding-cakes')->assertOk()->getContent();
    assertBreadcrumbTrail($html, ['Home', 'Shop', 'Cakes', 'Wedding Cakes'], $homeHref);

    $productHtml = $this->get('http://nested.example/products/tiered')->assertOk()->getContent();
    assertBreadcrumbTrail($productHtml, ['Home', 'Shop', 'Cakes', 'Wedding Cakes', 'Three Tier'], $homeHref);
});

test('category breadcrumbs are Home, Shop, then the category name', function () {
    ['homeHref' => $homeHref] = breadcrumbShopSite();

    $html = $this->get('http://flowers.example/collections/bouquets')->assertOk()->getContent();

    assertBreadcrumbTrail($html, ['Home', 'Shop', 'Bouquets'], $homeHref);
});

test('product breadcrumbs include the primary category', function () {
    ['homeHref' => $homeHref] = breadcrumbShopSite();

    $html = $this->get('http://flowers.example/products/rose')->assertOk()->getContent();

    assertBreadcrumbTrail($html, ['Home', 'Shop', 'Bouquets', 'Red Rose'], $homeHref);
});

test('a product with no primary category renders exactly three crumbs', function () {
    ['homeHref' => $homeHref] = breadcrumbShopSite();

    $html = $this->get('http://flowers.example/products/tulip')->assertOk()->getContent();

    $parsed = parseBreadcrumbList($html);
    expect($parsed['labels'])->toHaveCount(3);
    assertBreadcrumbTrail($html, ['Home', 'Shop', 'Yellow Tulip'], $homeHref);
});

test('search breadcrumbs are Home, Shop, Search', function () {
    ['homeHref' => $homeHref] = breadcrumbShopSite();

    $html = $this->get('http://flowers.example/shop/search')->assertOk()->getContent();

    assertBreadcrumbTrail($html, ['Home', 'Shop', 'Search'], $homeHref);
});

test('cart breadcrumbs are Home, Shop, Cart', function () {
    ['homeHref' => $homeHref] = breadcrumbShopSite();

    $html = $this->get('http://flowers.example/shop/cart')->assertOk()->getContent();

    assertBreadcrumbTrail($html, ['Home', 'Shop', 'Cart'], $homeHref);
});

test('checkout breadcrumbs are Home, Shop, Cart, Checkout', function () {
    ['site' => $site, 'homeHref' => $homeHref] = breadcrumbShopSite();

    $product = Product::factory()->for($site)->create(['slug' => 'cart-rose', 'name' => 'Cart Rose']);
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 2500]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 5]);

    $sessionId = 'breadcrumb-checkout';
    $cart = app(CartService::class)->getOrCreate($site->id, $sessionId);
    app(CartService::class)->addItem($cart, $variant->id, 1);

    $html = $this->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->get('http://flowers.example/shop/checkout')
        ->assertOk()
        ->getContent();

    assertBreadcrumbTrail($html, ['Home', 'Shop', 'Cart', 'Checkout'], $homeHref);
});
