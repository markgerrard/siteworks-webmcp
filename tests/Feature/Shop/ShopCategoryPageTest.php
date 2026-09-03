<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\Product;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  list<string>  $productSlugs
 * @param  array<string, array<string, mixed>>  $products
 */
function shopCategoryCatalogue(array $productSlugs, array $products): Site
{
    $site = Site::factory()->create([
        'custom_domain' => 'flowers.example',
        'custom_domain_status' => 'active',
        'business_name' => 'Bloom & Stem',
    ]);

    Product::factory()->published()->for($site)->create(['slug' => 'catalogue-row', 'name' => 'Catalogue row']);

    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => [
            'meta' => ['site_id' => $site->id, 'product_count' => count($products)],
            'categories' => [
                'preserves' => [
                    'id' => 1,
                    'slug' => 'preserves',
                    'name' => 'Preserves',
                    'product_slugs' => $productSlugs,
                ],
            ],
            'products' => $products,
            'featured_slugs' => [],
        ],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create([
        'site_id' => $site->id,
        'snapshot_id' => $snap->id,
        'updated_at' => now(),
    ]);

    return $site;
}

/**
 * @return array<string, mixed>
 */
function shopCategoryProduct(string $slug, string $name, string $priceDisplay, bool $inStock = true, bool $withImage = false): array
{
    return [
        'id' => crc32($slug),
        'slug' => $slug,
        'status' => 'published',
        'primary_category_slug' => 'preserves',
        'price_cents' => 595,
        'price_display' => $priceDisplay,
        'in_stock_any' => $inStock,
        'variant_in_stock' => [1 => $inStock],
        'image_urls' => $withImage
            ? ['thumb' => '/'.$slug.'-thumb.jpg', 'card' => '/'.$slug.'-card.jpg', 'full' => '/'.$slug.'-full.jpg']
            : null,
        'product_card' => ['slug' => $slug, 'name' => $name, 'price_display' => $priceDisplay],
        'product_detail' => ['slug' => $slug, 'name' => $name, 'description' => $name],
        'variants' => [['id' => 1, 'sku' => strtoupper($slug), 'label' => 'Jar', 'price_cents' => 595, 'image_urls' => null]],
        'is_ai_seeded' => false,
        'is_ai_reviewed' => false,
    ];
}

test('the category page shows the toolbar count without the old heading count or chip row', function () {
    $conserve = shopCategoryProduct('conserve', 'Strawberry Conserve', '£5.95', withImage: true);
    $marmalade = shopCategoryProduct('marmalade', 'Seville Marmalade', '£4.50');
    shopCategoryCatalogue(['conserve', 'marmalade'], [
        'conserve' => $conserve,
        'marmalade' => $marmalade,
    ]);

    $html = $this->get('http://flowers.example/collections/preserves')->assertOk()->getContent();
    preg_match('/<main\b[^>]*>(.*?)<\/main>/is', $html, $mainMatch);
    $main = $mainMatch[1];

    preg_match_all('/<h1\b/i', $main, $headings);
    expect($headings[0])->toHaveCount(1);
    expect($main)->toContain('Preserves');

    expect($main)->toContain('Showing 2 items')
        ->and($main)->not->toMatch('/<h1\b[^>]*>[\s\S]*2\s+bakes[\s\S]*<\/h1>/i')
        ->and($main)->not->toMatch('/<section\b[^>]*background-color:\s*var\(--(?:color|brand)-primary\)/i')
        ->and($main)->not->toContain('aria-label="Browse by category"')
        ->and($main)->toContain('Strawberry Conserve')
        ->and($main)->toContain('Seville Marmalade')
        ->and($main)->toContain('£5.95')
        ->and($main)->toContain('£4.50')
        ->and($main)->toContain('inc. VAT')
        ->and($main)->toContain('tabular-nums')
        ->and($main)->toMatch('/aspect-ratio:\s*1\s*\/\s*1/')
        ->and($main)->toContain('/conserve-card.jpg')
        ->and($main)->toMatch('/<img\b[^>]*width="[^"]+"[^>]*height="[^"]+"/i')
        ->and($main)->not->toContain('bg-white')
        ->and($main)->not->toContain('text-gray-500')
        ->and($main)->not->toContain('text-white')
        ->and($main)->not->toContain('bg-gray-100')
        ->and($main)->not->toContain('text-red-600');
});

test('a nested category page renders child tiles and the path url', function () {
    $site = Site::factory()->create([
        'custom_domain' => 'nested-cat.example',
        'custom_domain_status' => 'active',
        'business_name' => 'Bloom & Stem',
    ]);

    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => [
            'meta' => ['site_id' => $site->id, 'product_count' => 1, 'currency' => 'GBP'],
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
                    'breadcrumb' => [['name' => 'Cakes', 'path' => 'cakes']],
                    'hero_image_url' => null,
                    'product_slugs' => ['tiered'],
                    'description' => 'All the cakes',
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
                    'hero_image_url' => '/wedding-thumb.jpg',
                    'product_slugs' => ['tiered'],
                    'description' => 'For the day',
                ],
            ],
            'products' => [
                'tiered' => shopCategoryProduct('tiered', 'Three Tier', '£85.00'),
            ],
            'featured_slugs' => [],
        ],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create([
        'site_id' => $site->id,
        'snapshot_id' => $snap->id,
        'updated_at' => now(),
    ]);

    $html = $this->get('http://nested-cat.example/collections/cakes')->assertOk()->getContent();

    expect($html)->toContain('Wedding Cakes')
        ->and($html)->toContain('/collections/cakes/wedding-cakes')
        ->and($html)->toContain('/wedding-thumb.jpg');
});

test('an empty category renders the empty-state action, not a dead sentence', function () {
    shopCategoryCatalogue([], []);

    $html = $this->get('http://flowers.example/collections/preserves')->assertOk()->getContent();
    preg_match('/<main\b[^>]*>(.*?)<\/main>/is', $html, $mainMatch);
    $main = $mainMatch[1];

    preg_match_all('/<h1\b/i', $main, $headings);
    expect($headings[0])->toHaveCount(1);

    expect($main)->toContain('No items in this category yet.')
        ->and($main)->not->toContain('products')
        ->and($main)->toContain('Browse the shop')
        ->and($main)->toMatch('/href="[^"]*\/shop"/')
        ->and($main)->not->toMatch('/<section\b[^>]*background-color:\s*var\(--(?:color|brand)-primary\)/i')
        ->and($main)->not->toContain('text-gray-500')
        ->and($main)->not->toContain('Sorry');

    // The empty state is a quiet panel, not a bare paragraph jammed against a button.
    expect($main)->toMatch('/background-color:\s*var\(--color-surface-alt\)/')
        ->and($main)->toMatch('/border(?:-color)?:\s*[^;"]*var\(--color-border\)/')
        ->and($main)->toMatch('/border-radius:\s*var\(--radius-card\)/')
        ->and($main)->toMatch('/margin(?:-left)?:\s*(?:0\s+auto|auto)/');

    $messagePos = strpos($main, 'No items in this category yet.');
    $buttonPos = strpos($main, 'Browse the shop');
    expect($messagePos)->not->toBeFalse()
        ->and($buttonPos)->not->toBeFalse()
        ->and($messagePos)->toBeLessThan($buttonPos);
});
