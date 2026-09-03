<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\Product;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array{
 *     categories?: array<string, array<string, mixed>>,
 *     featured_slugs?: list<string>,
 *     products?: array<string, array<string, mixed>>,
 *     hero?: bool
 * }  $overrides
 * @return array{site: Site, homeHref: string}
 */
function shopIndexCatalogue(array $overrides = []): array
{
    $site = Site::factory()->create([
        'custom_domain' => 'flowers.example',
        'custom_domain_status' => 'active',
        'business_name' => 'Bloom & Stem',
    ]);

    Product::factory()->for($site)->create(['slug' => 'rose', 'name' => 'Red Rose']);

    $products = $overrides['products'] ?? [
        'rose' => [
            'id' => 1,
            'slug' => 'rose',
            'status' => 'published',
            'primary_category_slug' => 'bouquets',
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
        ],
    ];

    $categories = $overrides['categories'] ?? [
        'bouquets' => [
            'id' => 1,
            'slug' => 'bouquets',
            'name' => 'Bouquets',
            'product_slugs' => ['rose'],
        ],
    ];

    $json = [
        'meta' => [
            'site_id' => $site->id,
            'product_count' => count($products),
            'headline' => 'Cut this morning',
        ],
        'categories' => $categories,
        'products' => $products,
        'featured_slugs' => $overrides['featured_slugs'] ?? ['rose'],
    ];

    if ($overrides['hero'] ?? false) {
        $json['hero_image_url'] = '/shop-hero.jpg';
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

    return [
        'site' => $site,
        'homeHref' => app(PageRenderer::class)->layoutContext($site)['homeHref'],
    ];
}

function shopIndexGet(): string
{
    return test()->get('http://flowers.example/shop')->assertOk()->getContent();
}

function shopIndexMain(string $html): string
{
    preg_match('/<main\b[^>]*>(.*?)<\/main>/is', $html, $main);
    expect($main)->not->toBeEmpty();

    return $main[1];
}

test('the shop index has one h1, category chips, product rows, and token-priced featured cards', function () {
    shopIndexCatalogue();

    $html = shopIndexGet();
    $main = shopIndexMain($html);

    preg_match_all('/<h1\b/i', $main, $headings);
    expect($headings[0])->toHaveCount(1);

    expect($main)->not->toContain('Search products')
        ->and($main)->not->toContain('Shop by category');

    preg_match('/<nav[^>]*aria-label="Browse by category".*?<\/nav>/s', $main, $chips);
    expect($chips)->not->toBeEmpty();
    expect($chips[0])->toContain('Bouquets')
        ->and($chips[0])->toMatch('/href="[^"]*\/collections\/bouquets"/');

    expect($main)->toMatch('/Bouquets/')
        ->and($main)->toContain('See all')
        ->and($main)->toContain('Red Rose')
        ->and($main)->toContain('£45.00')
        ->and($main)->toContain('inc. VAT')
        ->and($main)->toContain('tabular-nums')
        ->and($main)->toMatch('/aspect-ratio:\s*1\s*\/\s*1/')
        ->and($main)->toMatch('/background-color:\s*var\(--color-surface-alt\)/')
        ->and($main)->not->toContain('bg-gray-100')
        ->and($main)->not->toContain('text-gray-900')
        ->and($main)->not->toContain('bg-gray-50')
        ->and($main)->not->toContain('text-white');
});

test('only categories with products appear as chips and rows', function () {
    shopIndexCatalogue([
        'categories' => [
            'bouquets' => ['id' => 1, 'slug' => 'bouquets', 'name' => 'Bouquets', 'product_slugs' => ['rose']],
            'plants' => ['id' => 2, 'slug' => 'plants', 'name' => 'Plants', 'product_slugs' => []],
            'gifts' => ['id' => 3, 'slug' => 'gifts', 'name' => 'Gifts', 'product_slugs' => []],
        ],
    ]);

    $html = shopIndexGet();
    $main = shopIndexMain($html);

    preg_match('/<nav[^>]*aria-label="Browse by category".*?<\/nav>/s', $main, $chips);
    expect($chips)->not->toBeEmpty();
    expect($chips[0])->toContain('Bouquets')
        ->and($chips[0])->not->toContain('Plants')
        ->and($chips[0])->not->toContain('Gifts')
        ->and($main)->toContain('Red Rose')
        ->and($main)->toMatch('/href="[^"]*\/collections\/bouquets"/');
});

test('each stocked category lists its first products and a see-all link', function () {
    shopIndexCatalogue([
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
                'image_urls' => null,
                'product_card' => ['slug' => 'rose', 'name' => 'Red Rose', 'price_display' => '£45.00'],
                'product_detail' => ['slug' => 'rose', 'name' => 'Red Rose', 'description' => 'Lovely'],
                'variants' => [['id' => 1, 'sku' => 'RR-1', 'label' => 'Std', 'price_cents' => 4500, 'image_urls' => null]],
                'is_ai_seeded' => false,
                'is_ai_reviewed' => false,
            ],
            'fern' => [
                'id' => 2,
                'slug' => 'fern',
                'status' => 'published',
                'primary_category_slug' => 'plants',
                'price_cents' => 1200,
                'price_display' => '£12.00',
                'in_stock_any' => true,
                'variant_in_stock' => [2 => true],
                'image_urls' => null,
                'product_card' => ['slug' => 'fern', 'name' => 'Forest Fern', 'price_display' => '£12.00'],
                'product_detail' => ['slug' => 'fern', 'name' => 'Forest Fern', 'description' => 'Green'],
                'variants' => [['id' => 2, 'sku' => 'FF-1', 'label' => 'Std', 'price_cents' => 1200, 'image_urls' => null]],
                'is_ai_seeded' => false,
                'is_ai_reviewed' => false,
            ],
        ],
        'categories' => [
            'bouquets' => ['id' => 1, 'slug' => 'bouquets', 'name' => 'Bouquets', 'product_slugs' => ['rose']],
            'plants' => ['id' => 2, 'slug' => 'plants', 'name' => 'Plants', 'product_slugs' => ['fern']],
        ],
        'featured_slugs' => [],
    ]);

    $main = shopIndexMain(shopIndexGet());

    preg_match('/<nav[^>]*aria-label="Browse by category".*?<\/nav>/s', $main, $chips);
    expect($chips[0])->toContain('Bouquets')
        ->and($chips[0])->toContain('Plants')
        ->and($main)->toContain('Red Rose')
        ->and($main)->toContain('Forest Fern')
        ->and($main)->toMatch('/See all/');
});

test('the no-hero shop index does not use a purple title banner', function () {
    shopIndexCatalogue();

    $main = shopIndexMain(shopIndexGet());

    preg_match_all('/<h1\b/i', $main, $headings);
    expect($headings[0])->toHaveCount(1);
    expect($main)->not->toMatch('/<section\b[^>]*background-color:\s*var\(--(?:color|brand)-primary\)[^>]*>[\s\S]*?<h1\b/i')
        ->and($main)->not->toContain('text-white');
});

test('out-of-stock featured cards use the stock pill not a red utility', function () {
    shopIndexCatalogue([
        'products' => [
            'rose' => [
                'id' => 1,
                'slug' => 'rose',
                'status' => 'published',
                'primary_category_slug' => 'bouquets',
                'price_cents' => 4500,
                'price_display' => '£45.00',
                'in_stock_any' => false,
                'variant_in_stock' => [1 => false],
                'image_urls' => null,
                'product_card' => ['slug' => 'rose', 'name' => 'Red Rose', 'price_display' => '£45.00'],
                'product_detail' => ['slug' => 'rose', 'name' => 'Red Rose', 'description' => 'Lovely'],
                'variants' => [['id' => 1, 'sku' => 'RR-1', 'label' => 'Std', 'price_cents' => 4500, 'image_urls' => null]],
                'is_ai_seeded' => false,
                'is_ai_reviewed' => false,
            ],
        ],
    ]);

    $main = shopIndexMain(shopIndexGet());

    expect($main)->toContain('Out of stock')
        ->and($main)->not->toContain('text-red-600');
});

test('a preview-host shop with no published snapshot renders the empty state, not a 404', function () {
    Site::factory()->create([
        'preview_domain' => 'fresh-clone.preview.example',
        'business_name' => 'Bloom & Stem',
    ]);

    $html = test()->get('http://fresh-clone.preview.example/shop')->assertOk()->getContent();
    $main = shopIndexMain($html);

    expect($main)->toContain('Our shop is being stocked')
        ->and($main)->toContain('Back to the homepage');
});

test('a live custom domain with nothing to sell keeps the 404 doctrine', function () {
    Site::factory()->create([
        'custom_domain' => 'flowers.example',
        'custom_domain_status' => 'active',
        'business_name' => 'Bloom & Stem',
    ]);

    test()->get('http://flowers.example/shop')->assertNotFound();
});
