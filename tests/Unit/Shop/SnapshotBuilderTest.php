<?php

use App\Enums\Shop\ProductStatus;
use App\Models\Shop\Category;
use App\Models\Shop\FeaturedProduct;
use App\Models\Shop\Product;
use App\Models\Shop\ProductImage;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\VariantStock;
use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use App\Services\Shop\SnapshotBuilder;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->site = Site::factory()->create();
    $this->builder = app(SnapshotBuilder::class);
});

test('empty shop builds valid skeleton', function () {
    $json = $this->builder->build($this->site->id);

    expect($json['meta']['site_id'])->toBe($this->site->id);
    expect($json['meta']['product_count'])->toBe(0);
    expect($json['categories'])->toBe([]);
    expect($json['products'])->toBe([]);
    expect($json['featured_slugs'])->toBe([]);
    expect($json['hero_width'])->toBe('boxed')
        ->and($json['hero_enabled'])->toBeTrue()
        ->and($json['hero_accent_word'])->toBeNull()
        ->and($json['hero_headline'])->toBeNull()
        ->and($json['hero_text_style'])->toBeNull()
        ->and($json['shared_category_hero'])->toBeNull();
});

test('shop snapshot carries persisted hero_accent_word from the previous snapshot json when the column is null', function () {
    $snapshot = ShopSnapshot::create([
        'site_id' => $this->site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => ['products' => [], 'categories' => [], 'hero_accent_word' => 'Bakehouse'],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $this->site->id, 'snapshot_id' => $snapshot->id]);

    $json = $this->builder->build($this->site->id);

    expect($json['hero_accent_word'])->toBe('Bakehouse');
});

test('shop snapshot defaults hero_accent_word to null when both the column and the previous json omit it', function () {
    $snapshot = ShopSnapshot::create([
        'site_id' => $this->site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => ['products' => [], 'categories' => []],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $this->site->id, 'snapshot_id' => $snapshot->id]);

    $json = $this->builder->build($this->site->id);

    expect($json['hero_accent_word'])->toBeNull();
});

test('shop snapshot prefers the hero_accent_word column over a stale json value', function () {
    $snapshot = ShopSnapshot::create([
        'site_id' => $this->site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => ['products' => [], 'categories' => [], 'hero_accent_word' => 'StaleJsonWord'],
        'built_at' => now(),
        'hero_accent_word' => 'FreshColumnWord',
    ]);
    ShopSnapshotCurrent::create(['site_id' => $this->site->id, 'snapshot_id' => $snapshot->id]);

    $json = $this->builder->build($this->site->id);

    expect($json['hero_accent_word'])->toBe('FreshColumnWord');
});

test('hero_accent_word is carried onto the new snapshot row created by a rebuild', function () {
    $snapshot = ShopSnapshot::create([
        'site_id' => $this->site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => ['products' => [], 'categories' => []],
        'built_at' => now(),
        'hero_accent_word' => 'Bakehouse',
    ]);
    ShopSnapshotCurrent::create(['site_id' => $this->site->id, 'snapshot_id' => $snapshot->id]);

    \App\Jobs\Shop\RebuildShopSnapshot::dispatchSync($this->site->id);

    $rebuilt = ShopSnapshot::where('site_id', $this->site->id)->orderByDesc('version')->first();

    expect($rebuilt->hero_accent_word)->toBe('Bakehouse')
        ->and($rebuilt->json['hero_accent_word'] ?? null)->toBe('Bakehouse');
});

test('shop snapshot carries persisted hero_headline from the current snapshot row', function () {
    $snapshot = ShopSnapshot::create([
        'site_id' => $this->site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => ['products' => [], 'categories' => []],
        'built_at' => now(),
        'hero_headline' => 'Cakes & Patisserie',
    ]);
    ShopSnapshotCurrent::create(['site_id' => $this->site->id, 'snapshot_id' => $snapshot->id]);

    $json = $this->builder->build($this->site->id);

    expect($json['hero_headline'])->toBe('Cakes & Patisserie');
});

test('shop snapshot defaults hero_headline to null when the current row omits it', function () {
    $snapshot = ShopSnapshot::create([
        'site_id' => $this->site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => ['products' => [], 'categories' => []],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $this->site->id, 'snapshot_id' => $snapshot->id]);

    $json = $this->builder->build($this->site->id);

    expect($json['hero_headline'])->toBeNull();
});

test('shop snapshot carries persisted hero_text_style from the current snapshot row', function () {
    $snapshot = ShopSnapshot::create([
        'site_id' => $this->site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => ['products' => [], 'categories' => []],
        'built_at' => now(),
        'hero_text_style' => 'boxed',
    ]);
    ShopSnapshotCurrent::create(['site_id' => $this->site->id, 'snapshot_id' => $snapshot->id]);

    $json = $this->builder->build($this->site->id);

    expect($json['hero_text_style'])->toBe('boxed');
});

test('shop snapshot defaults hero_text_style to null when the current row omits it', function () {
    $snapshot = ShopSnapshot::create([
        'site_id' => $this->site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => ['products' => [], 'categories' => []],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $this->site->id, 'snapshot_id' => $snapshot->id]);

    $json = $this->builder->build($this->site->id);

    expect($json['hero_text_style'])->toBeNull();
});

test('shop snapshot carries persisted shared_category_hero from the current snapshot row', function () {
    $block = [
        'image_url' => 'https://cdn.example.com/shared.jpg',
        'hero_alt' => 'Shared category hero',
        'height' => 'large',
        'width' => 'full',
        'text_zone' => 'top-center',
        'bg_position_y' => 40,
        'text_style' => 'boxed',
    ];
    $snapshot = ShopSnapshot::create([
        'site_id' => $this->site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => ['products' => [], 'categories' => []],
        'built_at' => now(),
        'shared_category_hero' => $block,
    ]);
    ShopSnapshotCurrent::create(['site_id' => $this->site->id, 'snapshot_id' => $snapshot->id]);

    $json = $this->builder->build($this->site->id);

    expect($json['shared_category_hero'])->toMatchArray($block);
});

test('shop snapshot defaults shared_category_hero to null when the current row omits it', function () {
    $snapshot = ShopSnapshot::create([
        'site_id' => $this->site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => ['products' => [], 'categories' => []],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $this->site->id, 'snapshot_id' => $snapshot->id]);

    $json = $this->builder->build($this->site->id);

    expect($json['shared_category_hero'])->toBeNull();
});

test('shared_category_hero is carried onto the new snapshot row created by a rebuild', function () {
    $block = [
        'image_url' => 'https://cdn.example.com/shared.jpg',
        'hero_alt' => 'Shared category hero',
        'height' => 'medium',
        'width' => 'boxed',
        'text_zone' => 'middle-left',
        'bg_position_y' => 50,
        'text_style' => 'plain',
    ];
    $snapshot = ShopSnapshot::create([
        'site_id' => $this->site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => ['products' => [], 'categories' => []],
        'built_at' => now(),
        'shared_category_hero' => $block,
    ]);
    ShopSnapshotCurrent::create(['site_id' => $this->site->id, 'snapshot_id' => $snapshot->id]);

    \App\Jobs\Shop\RebuildShopSnapshot::dispatchSync($this->site->id);

    $rebuilt = ShopSnapshot::where('site_id', $this->site->id)->orderByDesc('version')->first();

    expect($rebuilt->shared_category_hero)->toMatchArray($block)
        ->and($rebuilt->json['shared_category_hero'] ?? null)->toMatchArray($block);
});


test('shop snapshot carries persisted hero_width from the current snapshot row', function () {
    $snapshot = ShopSnapshot::create([
        'site_id' => $this->site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => ['products' => [], 'categories' => []],
        'built_at' => now(),
        'hero_width' => 'full',
    ]);
    ShopSnapshotCurrent::create(['site_id' => $this->site->id, 'snapshot_id' => $snapshot->id]);

    $json = $this->builder->build($this->site->id);

    expect($json['hero_width'])->toBe('full');
});

test('shop snapshot carries persisted hero_enabled from the current snapshot row', function () {
    $snapshot = ShopSnapshot::create([
        'site_id' => $this->site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => ['products' => [], 'categories' => []],
        'built_at' => now(),
        'hero_image_url' => 'https://cdn.example.com/hero.jpg',
        'hero_enabled' => false,
    ]);
    ShopSnapshotCurrent::create(['site_id' => $this->site->id, 'snapshot_id' => $snapshot->id]);

    $json = $this->builder->build($this->site->id);

    expect($json['hero_enabled'])->toBeFalse()
        ->and($json['hero_image_url'])->toBe('https://cdn.example.com/hero.jpg');
});

test('category snapshot includes hero_width boxed by default and round-trips full', function () {
    $boxed = Category::factory()->create([
        'site_id' => $this->site->id,
        'slug' => 'boxed-cat',
        'name' => 'Boxed',
    ]);
    $full = Category::factory()->create([
        'site_id' => $this->site->id,
        'slug' => 'full-cat',
        'name' => 'Full',
        'hero_width' => 'full',
    ]);

    $json = $this->builder->build($this->site->id);

    expect($json['categories']['boxed-cat']['hero_width'])->toBe('boxed')
        ->and($json['categories']['full-cat']['hero_width'])->toBe('full')
        ->and($boxed->hero_width ?? 'boxed')->toBe('boxed')
        ->and($full->hero_width)->toBe('full');
});

test('category snapshot carries hero_mode, hero_text_style and hero_accent_word from the category row', function () {
    Category::factory()->create([
        'site_id' => $this->site->id,
        'slug' => 'cakes',
        'name' => 'Cakes',
        'hero_mode' => 'shared',
        'hero_text_style' => 'boxed',
        'hero_accent_word' => 'Cakes',
    ]);
    Category::factory()->create([
        'site_id' => $this->site->id,
        'slug' => 'unset-cat',
        'name' => 'Unset',
    ]);

    $json = $this->builder->build($this->site->id);

    expect($json['categories']['cakes']['hero_mode'])->toBe('shared')
        ->and($json['categories']['cakes']['hero_text_style'])->toBe('boxed')
        ->and($json['categories']['cakes']['hero_accent_word'])->toBe('Cakes')
        ->and($json['categories']['unset-cat']['hero_mode'])->toBeNull()
        ->and($json['categories']['unset-cat']['hero_text_style'])->toBeNull()
        ->and($json['categories']['unset-cat']['hero_accent_word'])->toBeNull();
});

test('category snapshot carries intro_band false by default and true when set', function () {
    Category::factory()->create([
        'site_id' => $this->site->id,
        'slug' => 'roses',
        'name' => 'Roses',
        'intro_band' => true,
    ]);
    Category::factory()->create([
        'site_id' => $this->site->id,
        'slug' => 'unset-intro',
        'name' => 'Unset intro',
    ]);

    $json = $this->builder->build($this->site->id);

    expect($json['categories']['roses']['intro_band'])->toBeTrue()
        ->and($json['categories']['unset-intro']['intro_band'])->toBeFalse();
});

test('category snapshot includes hero_enabled true by default and round-trips false', function () {
    Category::factory()->create([
        'site_id' => $this->site->id,
        'slug' => 'on-cat',
        'name' => 'On',
        'hero_image_url' => 'https://cdn.example.com/on.jpg',
    ]);
    Category::factory()->create([
        'site_id' => $this->site->id,
        'slug' => 'off-cat',
        'name' => 'Off',
        'hero_image_url' => 'https://cdn.example.com/off.jpg',
        'hero_enabled' => false,
    ]);

    $json = $this->builder->build($this->site->id);

    expect($json['categories']['on-cat']['hero_enabled'])->toBeTrue()
        ->and($json['categories']['off-cat']['hero_enabled'])->toBeFalse()
        ->and($json['categories']['off-cat']['hero_image_url'])->toBe('https://cdn.example.com/off.jpg');
});

test('includes published and draft products, excludes archived', function () {
    $category = Category::factory()->create(['site_id' => $this->site->id, 'slug' => 'bouquets']);

    $published = Product::factory()->for($this->site)->create(['status' => ProductStatus::Published, 'slug' => 'rose']);
    $draft = Product::factory()->for($this->site)->create(['status' => ProductStatus::Draft, 'slug' => 'lily']);
    $archived = Product::factory()->for($this->site)->create(['status' => ProductStatus::Archived, 'slug' => 'old']);

    foreach ([$published, $draft, $archived] as $p) {
        $p->categories()->attach($category, ['is_primary' => true]);
    }

    $json = $this->builder->build($this->site->id);

    expect(array_keys($json['products']))->toEqual(['rose', 'lily']);
    expect($json['products']['rose']['status'])->toBe('published');
    expect($json['products']['lily']['status'])->toBe('draft');
});

test('product entries carry the nullable published timestamp', function () {
    $publishedAt = now()->subDays(3)->startOfSecond();
    Product::factory()->published()->for($this->site)->create([
        'slug' => 'dated',
        'published_at' => $publishedAt,
    ]);
    Product::factory()->for($this->site)->create([
        'slug' => 'undated',
        'published_at' => null,
    ]);

    $json = $this->builder->build($this->site->id);

    expect($json['products']['dated']['published_at'])->toBe($publishedAt->toIso8601String())
        ->and($json['products']['undated']['published_at'])->toBeNull();
});

test('product entry has variants with in_stock boolean from variant_stock', function () {
    $product = Product::factory()->for($this->site)->create(['slug' => 'rose']);
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 4500]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 5]);

    $json = $this->builder->build($this->site->id);

    $p = $json['products']['rose'];
    expect($p['variants'][0]['id'])->toBe($variant->id);
    expect($p['variants'][0]['price_cents'])->toBe(4500);
    expect($p['variants'][0])->toHaveKey('weight_grams');
    expect($p['variant_in_stock'][$variant->id])->toBeTrue();
    expect($p['in_stock_any'])->toBeTrue();
});

test('product variants include weight_grams including null', function () {
    $product = Product::factory()->for($this->site)->create(['slug' => 'candle']);
    $heavy = ProductVariant::factory()->for($product)->create(['price_cents' => 4500, 'sku' => 'HVY', 'weight_grams' => 850]);
    $unset = ProductVariant::factory()->for($product)->create(['price_cents' => 2500, 'sku' => 'DEF', 'weight_grams' => null]);
    VariantStock::create(['variant_id' => $heavy->id, 'on_hand' => 1]);
    VariantStock::create(['variant_id' => $unset->id, 'on_hand' => 1]);

    $json = $this->builder->build($this->site->id);
    $bySku = collect($json['products']['candle']['variants'])->keyBy('sku');

    expect($bySku['HVY']['weight_grams'])->toBe(850)
        ->and($bySku['DEF']['weight_grams'])->toBeNull();
});

test('depth-1 snapshot is identical except for added category tree keys', function () {
    $category = Category::factory()->create([
        'site_id' => $this->site->id,
        'slug' => 'bouquets',
        'name' => 'Bouquets',
        'description' => 'Seasonal bunches',
    ]);
    $p1 = Product::factory()->for($this->site)->create(['slug' => 'rose']);
    $p2 = Product::factory()->for($this->site)->create(['slug' => 'lily']);
    $p1->categories()->attach($category, ['is_primary' => true]);
    $p2->categories()->attach($category, ['is_primary' => true]);

    $json = $this->builder->build($this->site->id);
    $cat = $json['categories']['bouquets'];

    expect($cat)->not->toHaveKey('has_copy')
        ->not->toHaveKey('has_faq');

    $added = [
        'parent_slug', 'path', 'depth', 'is_anchor', 'visibility',
        'meta_title', 'meta_description', 'sort', 'children', 'breadcrumb',
        'hero_mode', 'hero_text_style', 'hero_accent_word', 'intro_band',
    ];
    $legacy = $cat;
    foreach ($added as $key) {
        expect($cat)->toHaveKey($key);
        unset($legacy[$key]);
    }

    expect($legacy)->toMatchArray([
        'id' => $category->id,
        'slug' => 'bouquets',
        'name' => 'Bouquets',
        'description' => 'Seasonal bunches',
        'product_slugs' => ['rose', 'lily'],
        'hero_image_url' => $category->hero_image_url,
        'hero_alt' => $category->hero_alt,
        'hero_height' => $category->hero_height ?? 'medium',
        'bg_position_y' => $category->bg_position_y ?? 50,
        'text_zone' => $category->text_zone ?? 'middle-left',
        'hero_width' => $category->hero_width ?? 'boxed',
        'hero_enabled' => $category->hero_enabled ?? true,
    ])->and($cat['parent_slug'])->toBeNull()
        ->and($cat['path'])->toBe('bouquets')
        ->and($cat['depth'])->toBe(1)
        ->and($cat['is_anchor'])->toBeTrue()
        ->and($cat['visibility'])->toBe('visible')
        ->and($cat['meta_title'])->toBeNull()
        ->and($cat['meta_description'])->toBeNull()
        ->and($cat['sort'])->toBe('manual')
        ->and($cat['children'])->toBe([])
        ->and($cat['breadcrumb'])->toBe([
            ['name' => 'Bouquets', 'path' => 'bouquets'],
        ])
        ->and($json['category_paths'])->toBe(['bouquets' => 'bouquets']);
});

test('anchor categories roll up descendant products after their own, de-duplicated', function () {
    $cakes = Category::factory()->create([
        'site_id' => $this->site->id,
        'slug' => 'cakes',
        'name' => 'Cakes',
        'path' => 'cakes',
        'depth' => 1,
        'is_anchor' => true,
        'sort_order' => 1,
    ]);
    $wedding = Category::factory()->create([
        'site_id' => $this->site->id,
        'parent_id' => $cakes->id,
        'slug' => 'wedding-cakes',
        'name' => 'Wedding Cakes',
        'path' => 'cakes/wedding-cakes',
        'depth' => 2,
        'sort_order' => 1,
    ]);
    $own = Product::factory()->for($this->site)->create(['slug' => 'victoria']);
    $child = Product::factory()->for($this->site)->create(['slug' => 'three-tier']);
    $both = Product::factory()->for($this->site)->create(['slug' => 'lemon-drizzle']);
    $own->categories()->attach($cakes->id, ['is_primary' => true]);
    $child->categories()->attach($wedding->id, ['is_primary' => true]);
    $both->categories()->attach([
        $cakes->id => ['is_primary' => true],
        $wedding->id => ['is_primary' => false],
    ]);

    $json = $this->builder->build($this->site->id);
    $cat = $json['categories']['cakes'];

    expect($cat['product_slugs'])->toBe(['victoria', 'lemon-drizzle', 'three-tier'])
        ->and($cat['children'])->toBe(['wedding-cakes'])
        ->and($cat['path'])->toBe('cakes')
        ->and($json['categories']['wedding-cakes']['parent_slug'])->toBe('cakes')
        ->and($json['categories']['wedding-cakes']['path'])->toBe('cakes/wedding-cakes')
        ->and($json['categories']['wedding-cakes']['depth'])->toBe(2)
        ->and($json['categories']['wedding-cakes']['breadcrumb'])->toBe([
            ['name' => 'Cakes', 'path' => 'cakes'],
            ['name' => 'Wedding Cakes', 'path' => 'cakes/wedding-cakes'],
        ])
        ->and($json['category_paths']['cakes/wedding-cakes'])->toBe('wedding-cakes');
});

test('hidden children are omitted from the visible children list', function () {
    $cakes = Category::factory()->create([
        'site_id' => $this->site->id,
        'slug' => 'cakes',
        'name' => 'Cakes',
        'path' => 'cakes',
        'depth' => 1,
        'sort_order' => 1,
    ]);
    Category::factory()->create([
        'site_id' => $this->site->id,
        'parent_id' => $cakes->id,
        'slug' => 'secret',
        'name' => 'Secret',
        'path' => 'cakes/secret',
        'depth' => 2,
        'visibility' => 'hidden',
        'sort_order' => 1,
    ]);
    Category::factory()->create([
        'site_id' => $this->site->id,
        'parent_id' => $cakes->id,
        'slug' => 'wedding-cakes',
        'name' => 'Wedding Cakes',
        'path' => 'cakes/wedding-cakes',
        'depth' => 2,
        'sort_order' => 2,
    ]);

    $json = $this->builder->build($this->site->id);

    expect($json['categories']['cakes']['children'])->toBe(['wedding-cakes']);
});

test('categories carry product_slugs array', function () {
    $category = Category::factory()->create(['site_id' => $this->site->id, 'slug' => 'bouquets', 'name' => 'Bouquets']);
    $p1 = Product::factory()->for($this->site)->create(['slug' => 'rose']);
    $p2 = Product::factory()->for($this->site)->create(['slug' => 'lily']);
    $p1->categories()->attach($category, ['is_primary' => true]);
    $p2->categories()->attach($category, ['is_primary' => true]);

    $json = $this->builder->build($this->site->id);

    expect($json['categories']['bouquets']['product_slugs'])->toContain('rose', 'lily');
});

test('featured_slugs lists active featured products', function () {
    $p = Product::factory()->for($this->site)->create(['slug' => 'rose']);
    FeaturedProduct::create(['site_id' => $this->site->id, 'product_id' => $p->id, 'sort_order' => 0]);

    $json = $this->builder->build($this->site->id);

    expect($json['featured_slugs'])->toEqual(['rose']);
});

test('price_display formatted with pound sign and decimal', function () {
    $product = Product::factory()->for($this->site)->create(['slug' => 'rose']);
    ProductVariant::factory()->for($product)->create(['price_cents' => 4500]);

    $json = $this->builder->build($this->site->id);

    expect($json['products']['rose']['price_display'])->toBe('£45.00')
        ->and($json['products']['rose']['product_card']['price_display'])->toBe('£45.00')
        ->and($json['meta']['currency'])->toBe('GBP')
        ->and($json['products']['rose']['product_card']['price_from'])->toBeFalse();
});

test('USD shops format snapshot prices with a dollar sign', function () {
    $this->site->update(['shop_currency' => 'USD']);
    $product = Product::factory()->for($this->site)->create(['slug' => 'rose']);
    ProductVariant::factory()->for($product)->create(['price_cents' => 1250]);

    $json = $this->builder->build($this->site->id);

    expect($json['meta']['currency'])->toBe('USD')
        ->and($json['products']['rose']['price_display'])->toBe('$12.50')
        ->and($json['products']['rose']['product_card']['price_display'])->toBe('$12.50');
});

test('price_from products prefix card and PDP amounts with from', function () {
    $this->site->update(['shop_currency' => 'USD']);
    $product = Product::factory()->for($this->site)->create(['slug' => 'bespoke', 'price_from' => true]);
    ProductVariant::factory()->for($product)->create(['price_cents' => 8500]);

    $json = $this->builder->build($this->site->id);

    expect($json['products']['bespoke']['price_display'])->toBe('from $85')
        ->and($json['products']['bespoke']['product_card']['price_display'])->toBe('from $85')
        ->and($json['products']['bespoke']['product_card']['price_from'])->toBeTrue();
});

test('meta contains product_count and built_at ISO8601', function () {
    Product::factory()->for($this->site)->count(3)->create();

    $json = $this->builder->build($this->site->id);

    expect($json['meta']['product_count'])->toBe(3);
    expect($json['meta']['built_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
});

test('imageUrls are built from the media disk not the default disk', function () {
    Storage::fake('other-disk', ['url' => 'https://default-disk.test/storage']);
    Storage::fake('media-disk', ['url' => 'https://media-disk.test/storage']);
    config([
        'filesystems.default' => 'other-disk',
        'filesystems.media' => 'media-disk',
    ]);

    $product = Product::factory()->for($this->site)->create(['slug' => 'croissant']);
    ProductImage::create([
        'product_id' => $product->id,
        'path' => 'products/croissant.jpg',
        'sort_order' => 0,
        'alt' => 'Croissant',
    ]);

    $json = $this->builder->build($this->site->id);

    expect($json['products']['croissant']['image_urls']['card'])
        ->toStartWith('https://media-disk.test/storage/')
        ->not->toContain('https://default-disk.test/storage')
        ->and($json['products']['croissant']['image_urls'])->toBe([
            'thumb' => $json['products']['croissant']['image_urls']['card'],
            'card' => $json['products']['croissant']['image_urls']['card'],
            'full' => $json['products']['croissant']['image_urls']['card'],
        ]);
});
