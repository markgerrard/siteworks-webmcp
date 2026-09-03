<?php

use App\Enums\Shop\ProductStatus;
use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Services\Shop\ProductSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{site: Site, matching: list<Product>}
 */
function shopSearchCatalogue(): array
{
    $site = Site::factory()->create([
        'custom_domain' => 'flowers.example',
        'custom_domain_status' => 'active',
        'business_name' => 'Bloom & Stem',
    ]);

    $damson = Product::factory()->for($site)->create([
        'slug' => 'damson',
        'name' => 'Damson Conserve',
        'status' => ProductStatus::Published,
    ]);
    $damsonVariant = ProductVariant::factory()->for($damson)->create([
        'sku' => 'DC-1',
        'label' => 'Jar',
        'price_cents' => 595,
    ]);
    VariantStock::create(['variant_id' => $damsonVariant->id, 'on_hand' => 8]);

    $damsonCheese = Product::factory()->for($site)->create([
        'slug' => 'damson-cheese',
        'name' => 'Damson Cheese',
        'status' => ProductStatus::Published,
    ]);
    $cheeseVariant = ProductVariant::factory()->for($damsonCheese)->create([
        'sku' => 'DCH-1',
        'label' => 'Round',
        'price_cents' => 750,
    ]);
    VariantStock::create(['variant_id' => $cheeseVariant->id, 'on_hand' => 3]);

    $lily = Product::factory()->for($site)->create([
        'slug' => 'lily',
        'name' => 'White Lily',
        'status' => ProductStatus::Published,
    ]);

    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => [
            'meta' => ['site_id' => $site->id, 'product_count' => 3],
            'categories' => [],
            'products' => [
                'damson' => [
                    'id' => $damson->id,
                    'slug' => 'damson',
                    'status' => 'published',
                    'primary_category_slug' => null,
                    'price_cents' => 595,
                    'price_display' => '£5.95',
                    'in_stock_any' => true,
                    'variant_in_stock' => [$damsonVariant->id => true],
                    'image_urls' => null,
                    'product_card' => ['slug' => 'damson', 'name' => 'Damson Conserve', 'price_display' => '£5.95'],
                    'product_detail' => ['slug' => 'damson', 'name' => 'Damson Conserve', 'description' => 'Deep'],
                    'variants' => [['id' => $damsonVariant->id, 'sku' => 'DC-1', 'label' => 'Jar', 'price_cents' => 595, 'image_urls' => null]],
                    'is_ai_seeded' => false,
                    'is_ai_reviewed' => false,
                ],
                'damson-cheese' => [
                    'id' => $damsonCheese->id,
                    'slug' => 'damson-cheese',
                    'status' => 'published',
                    'primary_category_slug' => null,
                    'price_cents' => 750,
                    'price_display' => '£7.50',
                    'in_stock_any' => true,
                    'variant_in_stock' => [$cheeseVariant->id => true],
                    'image_urls' => ['thumb' => '/cheese-thumb.jpg', 'card' => '/cheese-card.jpg', 'full' => '/cheese-full.jpg'],
                    'product_card' => ['slug' => 'damson-cheese', 'name' => 'Damson Cheese', 'price_display' => '£7.50'],
                    'product_detail' => ['slug' => 'damson-cheese', 'name' => 'Damson Cheese', 'description' => 'Set'],
                    'variants' => [['id' => $cheeseVariant->id, 'sku' => 'DCH-1', 'label' => 'Round', 'price_cents' => 750, 'image_urls' => null]],
                    'is_ai_seeded' => false,
                    'is_ai_reviewed' => false,
                ],
                'lily' => [
                    'id' => $lily->id,
                    'slug' => 'lily',
                    'status' => 'published',
                    'primary_category_slug' => null,
                    'price_cents' => 1200,
                    'price_display' => '£12.00',
                    'in_stock_any' => true,
                    'variant_in_stock' => [],
                    'image_urls' => null,
                    'product_card' => ['slug' => 'lily', 'name' => 'White Lily', 'price_display' => '£12.00'],
                    'product_detail' => ['slug' => 'lily', 'name' => 'White Lily', 'description' => 'Pale'],
                    'variants' => [],
                    'is_ai_seeded' => false,
                    'is_ai_reviewed' => false,
                ],
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

    return ['site' => $site, 'matching' => [$damson, $damsonCheese]];
}

test('search echoes the query, shows an independently counted result total, and uses the same cards', function () {
    ['site' => $site] = shopSearchCatalogue();

    $query = 'Damson';
    $expectedCount = app(ProductSearchService::class)->search($site->id, $query)->count();
    expect($expectedCount)->toBe(2);

    $html = $this->get('http://flowers.example/shop/search?q='.$query)->assertOk()->getContent();
    preg_match('/<main\b[^>]*>(.*?)<\/main>/is', $html, $mainMatch);
    $main = $mainMatch[1];

    preg_match_all('/<h1\b/i', $main, $headings);
    expect($headings[0])->toHaveCount(1);

    $withoutInputs = preg_replace('/<input\b[^>]*>/i', '', $main);
    expect($withoutInputs)->toContain($query)
        ->and($withoutInputs)->toMatch('/'.$expectedCount.'\s+items/i')
        ->and($main)->not->toContain('Search products')
        ->and($main)->not->toMatch('/<h1[^>]*>\s*Search\s*<\/h1>/i');

    expect($main)->toContain('Damson Conserve')
        ->and($main)->toContain('Damson Cheese')
        ->and($main)->not->toContain('White Lily')
        ->and($main)->toContain('£5.95')
        ->and($main)->toContain('£7.50')
        ->and($main)->toContain('inc. VAT')
        ->and($main)->toContain('tabular-nums')
        ->and($main)->toMatch('/aspect-ratio:\s*1\s*\/\s*1/')
        ->and($main)->toMatch('/background-color:\s*var\(--color-surface-alt\)/')
        ->and($main)->not->toContain('text-gray-500')
        ->and($main)->not->toContain('No products found for')
        ->and($main)->not->toContain('products matching');
});

test('a miss names the query in the empty state and offers browse', function () {
    shopSearchCatalogue();

    $query = 'Quinceleather';
    $html = $this->get('http://flowers.example/shop/search?q='.$query)->assertOk()->getContent();
    preg_match('/<main\b[^>]*>(.*?)<\/main>/is', $html, $mainMatch);
    $main = $mainMatch[1];

    preg_match_all('/<h1\b/i', $main, $headings);
    expect($headings[0])->toHaveCount(1);

    $text = html_entity_decode(strip_tags($main), ENT_QUOTES | ENT_HTML5);
    expect($text)->toContain("Nothing called ‘{$query}’ yet")
        ->and($main)->not->toContain('No products match')
        ->and($main)->not->toContain('No products found for')
        ->and($main)->not->toContain('text-gray-500');
});

test('an empty query invites the header search', function () {
    shopSearchCatalogue();

    $html = $this->get('http://flowers.example/shop/search')->assertOk()->getContent();
    preg_match('/<main\b[^>]*>(.*?)<\/main>/is', $html, $mainMatch);
    $main = $mainMatch[1];

    preg_match_all('/<h1\b/i', $main, $headings);
    expect($headings[0])->toHaveCount(1);
    expect($main)->toContain('Search the shop')
        ->and($main)->not->toContain('Search products');
});
