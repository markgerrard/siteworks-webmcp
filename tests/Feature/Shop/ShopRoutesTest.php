<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->site = Site::factory()->create(['custom_domain' => 'flowers.example', 'custom_domain_status' => 'active']);
    $json = [
        'meta' => ['site_id' => $this->site->id, 'product_count' => 2],
        'categories' => [
            'bouquets' => ['id' => 1, 'slug' => 'bouquets', 'name' => 'Bouquets', 'product_slugs' => ['rose']],
        ],
        'products' => [
            'rose' => [
                'id' => 1, 'slug' => 'rose', 'status' => 'published',
                'primary_category_slug' => 'bouquets',
                'price_cents' => 4500, 'price_display' => '£45.00',
                'in_stock_any' => true, 'variant_in_stock' => [1 => true],
                'image_urls' => ['thumb' => '/a.jpg', 'card' => '/a.jpg', 'full' => '/a.jpg'],
                'product_card' => ['slug' => 'rose', 'name' => 'Red Rose', 'price_display' => '£45.00'],
                'product_detail' => ['slug' => 'rose', 'name' => 'Red Rose', 'description' => 'Lovely'],
                'variants' => [['id' => 1, 'sku' => 'RR-1', 'label' => 'Std', 'price_cents' => 4500, 'image_urls' => null]],
                'is_ai_seeded' => false, 'is_ai_reviewed' => false,
            ],
            'draft' => [
                'id' => 2, 'slug' => 'draft', 'status' => 'draft',
                'primary_category_slug' => 'bouquets',
                'price_cents' => 1000, 'price_display' => '£10.00',
                'in_stock_any' => true, 'variant_in_stock' => [],
                'image_urls' => null, 'product_card' => ['slug' => 'draft', 'name' => 'Draft'], 'product_detail' => [], 'variants' => [],
                'is_ai_seeded' => false, 'is_ai_reviewed' => false,
            ],
        ],
        'featured_slugs' => ['rose'],
    ];
    $snap = ShopSnapshot::create([
        'site_id' => $this->site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => $json,
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $this->site->id, 'snapshot_id' => $snap->id, 'updated_at' => now()]);
});

test('shop index renders published product on public domain', function () {
    $this->get('http://flowers.example/shop')
        ->assertOk()
        ->assertSee('Red Rose')
        ->assertDontSee('Draft');
});

test('category page shows products in category', function () {
    $this->get('http://flowers.example/collections/bouquets')
        ->assertOk()
        ->assertSee('Red Rose');
});

test('cache-tag header set on response', function () {
    $response = $this->get('http://flowers.example/shop');
    $response->assertHeader('Cache-Tag', "shop:{$this->site->id}");
});
