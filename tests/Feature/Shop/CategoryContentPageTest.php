<?php

use App\Enums\Shop\ProductStatus;
use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

/**
 * @return array<string, mixed>
 */
function categoryContentSnapshot(Category $category, Product $product): array
{
    return [
        'meta' => ['site_id' => $category->site_id, 'product_count' => 1, 'currency' => 'GBP'],
        'category_paths' => [$category->path => $category->slug],
        'categories' => [
            $category->slug => [
                'id' => $category->id,
                'slug' => $category->slug,
                'name' => $category->name,
                'path' => $category->path,
                'description' => null,
                'product_slugs' => [$product->slug],
                'children' => [],
                'breadcrumb' => [['name' => $category->name, 'path' => $category->path]],
                'visibility' => 'visible',
            ],
        ],
        'products' => [
            $product->slug => [
                'id' => $product->id,
                'slug' => $product->slug,
                'status' => 'published',
                'price_cents' => 1000,
                'price_display' => '£10.00',
                'in_stock_any' => true,
                'variant_in_stock' => [],
                'image_urls' => null,
                'product_card' => ['slug' => $product->slug, 'name' => $product->name, 'price_display' => '£10.00'],
                'product_detail' => ['slug' => $product->slug, 'name' => $product->name, 'description' => ''],
                'variants' => [],
            ],
        ],
        'featured_slugs' => [],
    ];
}

test('a category page reads copy, faq and meta overrides from its scoped category record', function () {
    $site = Site::factory()->create(['custom_domain' => 'category-content.example', 'custom_domain_status' => 'active']);
    $category = Category::factory()->for($site)->create([
        'name' => 'Collection',
        'slug' => 'collection',
        'path' => 'collection',
        'description_long' => '<h2>More details</h2><p>Choose a Published item for your plan.</p>',
        'faqs' => [['q' => 'Is this safe?', 'a' => 'Yes </script><script>alert(1)</script>']],
        'meta_title' => 'A & B <title>',
        'meta_description' => 'A & B <description>',
    ]);
    $product = Product::factory()->for($site)->published()->create(['name' => 'Published item', 'slug' => 'published-item']);
    $product->categories()->attach($category, ['is_primary' => true]);
    $json = categoryContentSnapshot($category, $product);
    $snapshot = ShopSnapshot::create(['site_id' => $site->id, 'version' => 1, 'status' => ShopSnapshotStatus::Success, 'json' => $json, 'built_at' => now()]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snapshot->id, 'updated_at' => now()]);

    $html = $this->get('http://category-content.example/collections/collection')->assertOk()->getContent();
    $toolbarPosition = strpos($html, 'id="shop-listing-toolbar"');
    $productGridPosition = strpos($html, '<div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 sm:gap-6 max-w-full">');
    $longCopyPosition = strpos($html, 'data-category-long-copy');
    $faqPosition = strpos($html, 'data-category-faqs');

    expect($html)
        ->toContain('<title>A &amp; B &lt;title&gt;</title>')
        ->toContain('<meta property="og:title" content="A &amp; B &lt;title&gt; — '.e($site->business_name).'">')
        ->toContain('<meta name="description" content="A &amp; B &lt;description&gt;">')
        ->toContain('id="shop-listing-toolbar"')
        ->toContain('data-category-long-copy')
        ->toContain('href="/products/published-item"')
        ->toContain('data-category-faqs')
        ->toContain('Is this safe?')
        ->toContain('FAQPage')
        ->toContain('\\u003C/script\\u003E')
        ->not->toContain('<script>alert(1)</script>');

    expect($toolbarPosition)->toBeInt()->toBeLessThan($productGridPosition)
        ->and($productGridPosition)->toBeInt()->toBeLessThan($longCopyPosition)
        ->and($longCopyPosition)->toBeInt()->toBeLessThan($faqPosition);
});

test('a hostile stored faq key cannot enter Alpine expressions', function () {
    $site = Site::factory()->create(['custom_domain' => 'category-faq-key.example', 'custom_domain_status' => 'active']);
    $category = Category::factory()->for($site)->create([
        'name' => 'Collection',
        'slug' => 'collection',
        'path' => 'collection',
        'faqs' => ['0,window.__pwned=1' => ['q' => 'Is this safe?', 'a' => 'Yes.']],
    ]);
    $product = Product::factory()->for($site)->published()->create(['name' => 'Published item', 'slug' => 'published-item']);
    $product->categories()->attach($category, ['is_primary' => true]);
    $snapshot = ShopSnapshot::create(['site_id' => $site->id, 'version' => 1, 'status' => ShopSnapshotStatus::Success, 'json' => categoryContentSnapshot($category, $product), 'built_at' => now()]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snapshot->id, 'updated_at' => now()]);

    $html = $this->get('http://category-faq-key.example/collections/collection')->assertOk()->getContent();

    expect($html)
        ->toContain('x-bind:open="open === 0"')
        ->toContain('x-on:toggle="if ($event.target.open) { open = 0 }"')
        ->not->toContain('0,window.__pwned=1');
});

test('empty category content does not add the content regions', function () {
    $site = Site::factory()->create(['custom_domain' => 'category-empty.example', 'custom_domain_status' => 'active']);
    $category = Category::factory()->for($site)->create(['slug' => 'collection', 'path' => 'collection']);
    $product = Product::factory()->for($site)->published()->create(['slug' => 'published-item']);
    $json = categoryContentSnapshot($category, $product);
    $snapshot = ShopSnapshot::create(['site_id' => $site->id, 'version' => 1, 'status' => ShopSnapshotStatus::Success, 'json' => $json, 'built_at' => now()]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snapshot->id, 'updated_at' => now()]);

    $html = $this->get('http://category-empty.example/collections/collection')->assertOk()->getContent();

    expect($html)->not->toContain('data-category-long-copy')
        ->not->toContain('data-category-faqs')
        ->not->toContain('FAQPage');
});

test('removed or unpublished product names stay plain text in category content', function () {
    $site = Site::factory()->create(['custom_domain' => 'category-status.example', 'custom_domain_status' => 'active']);
    $category = Category::factory()->for($site)->create([
        'slug' => 'collection',
        'path' => 'collection',
        'description_long' => '<p>Archived item remains useful context.</p>',
    ]);
    $product = Product::factory()->for($site)->create(['name' => 'Archived item', 'slug' => 'archived-item', 'status' => ProductStatus::Archived]);
    $product->categories()->attach($category, ['is_primary' => true]);
    $json = categoryContentSnapshot($category, $product);
    $snapshot = ShopSnapshot::create(['site_id' => $site->id, 'version' => 1, 'status' => ShopSnapshotStatus::Success, 'json' => $json, 'built_at' => now()]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snapshot->id, 'updated_at' => now()]);

    $html = $this->get('http://category-status.example/collections/collection')->assertOk()->getContent();

    expect($html)->toContain('Archived item remains useful context.')
        ->not->toContain('>Archived item</a>');
});
