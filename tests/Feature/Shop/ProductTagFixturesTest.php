<?php

use App\Enums\Shop\ProductStatus;
use App\Models\GeneratedPage;
use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Services\Shop\SnapshotBuilder;
use App\Support\Shop\AutoTagConfig;

function seedTaggedProduct(Site $site, string $slug, array $tags, array $attrs = []): Product
{
    $onHand = $attrs['on_hand'] ?? 8;
    unset($attrs['on_hand']);
    $product = Product::factory()->for($site)->create(array_merge([
        'slug' => $slug,
        'name' => ucfirst(str_replace('-', ' ', $slug)),
        'status' => ProductStatus::Published,
        'published_at' => now()->subDays(2),
        'tags' => $tags,
    ], $attrs));
    $variant = ProductVariant::factory()->for($product)->create(['sku' => strtoupper($slug)]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => $onHand]);

    return $product;
}

it('Camino bakery fixture exposes gluten-free and bestseller-manual tags with auto new', function () {
    $site = Site::factory()->create([
        'business_name' => 'Camino Bakery',
        'shop_mode' => 'cart',
        'product_tags' => [
            ['slug' => 'gluten-free', 'label' => 'Gluten free', 'show_as_badge' => true, 'tone' => 'success'],
            ['slug' => 'bestseller-manual', 'label' => 'Best seller', 'show_as_badge' => true, 'tone' => 'accent'],
        ],
        'auto_tags' => AutoTagConfig::parse([
            'new' => ['enabled' => true, 'label' => 'New', 'show_as_badge' => true, 'params' => ['days' => 14]],
        ]),
        'shop_index_blocks' => [
            ['source' => 'tag:bestseller-manual', 'limit' => 8, 'layout' => 'carousel', 'heading' => 'Best sellers'],
            ['source' => 'tag:gluten-free', 'limit' => 8, 'layout' => 'grid', 'heading' => 'Gluten free'],
        ],
    ]);
    $loaf = seedTaggedProduct($site, 'sourdough', ['gluten-free', 'bestseller-manual']);
    seedTaggedProduct($site, 'brownie', ['gluten-free']);
    $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'featured_products', 'title' => 'Best sellers', 'source' => 'tag:bestseller-manual', 'limit' => 8, 'layout' => 'carousel'],
            ['type' => 'featured_products', 'title' => 'Gluten free', 'source' => 'tag:gluten-free', 'limit' => 8, 'layout' => 'grid'],
        ]],
    ]);

    $json = app(SnapshotBuilder::class)->build($site->id);
    $slugs = array_column($json['products']['sourdough']['tags'], 'slug');

    expect($slugs)->toContain('gluten-free')
        ->and($slugs)->toContain('bestseller-manual')
        ->and($slugs)->toContain('new')
        ->and($site->fresh()->shop_index_blocks[0]['heading'])->toBe('Best sellers')
        ->and($loaf->tags)->toContain('gluten-free');
});

it('florist fixture exposes same-day and seasonal with auto low-stock', function () {
    $site = Site::factory()->create([
        'business_name' => 'Corner Florist',
        'shop_mode' => 'cart',
        'product_tags' => [
            ['slug' => 'same-day', 'label' => 'Same day', 'show_as_badge' => true, 'tone' => 'accent'],
            ['slug' => 'seasonal', 'label' => 'Seasonal', 'show_as_badge' => true, 'tone' => 'warning'],
        ],
        'auto_tags' => AutoTagConfig::parse([
            'low-stock' => ['enabled' => true, 'label' => 'Low stock', 'show_as_badge' => true, 'params' => ['threshold' => 5]],
        ]),
        'shop_index_blocks' => [
            ['source' => 'tag:seasonal', 'limit' => 8, 'layout' => 'carousel', 'heading' => 'Seasonal'],
        ],
    ]);
    $cat = Category::factory()->create(['site_id' => $site->id, 'slug' => 'bunches']);
    $tight = seedTaggedProduct($site, 'peony-bunch', ['seasonal', 'same-day'], ['on_hand' => 2]);
    seedTaggedProduct($site, 'rose-bunch', ['seasonal'], ['on_hand' => 20]);
    $tight->categories()->attach($cat, ['is_primary' => true]);

    $json = app(SnapshotBuilder::class)->build($site->id);
    $slugs = array_column($json['products']['peony-bunch']['tags'], 'slug');

    expect($slugs)->toContain('same-day')
        ->and($slugs)->toContain('seasonal')
        ->and($slugs)->toContain('low-stock')
        ->and(array_column($json['products']['rose-bunch']['tags'], 'slug'))->not->toContain('low-stock');
});

it('zero-tag fixture snapshot products have empty tags lists', function () {
    $site = Site::factory()->create(['business_name' => 'Plain Shop']);
    seedTaggedProduct($site, 'plain-item', []);

    $json = app(SnapshotBuilder::class)->build($site->id);

    expect($json['products']['plain-item']['tags'])->toBe([])
        ->and($site->fresh()->product_tags ?? [])->toBeEmpty();
});
