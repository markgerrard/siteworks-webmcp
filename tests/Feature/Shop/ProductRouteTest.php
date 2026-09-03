<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->site = Site::factory()->create(['custom_domain' => 'flowers.example', 'custom_domain_status' => 'active']);
    $snap = ShopSnapshot::create([
        'site_id' => $this->site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => [
            'meta' => ['site_id' => $this->site->id, 'product_count' => 1],
            'categories' => [],
            'products' => [
                'rose' => [
                    'id' => 1, 'slug' => 'rose', 'status' => 'published',
                    'primary_category_slug' => null,
                    'price_cents' => 4500, 'price_display' => '£45.00',
                    'in_stock_any' => true, 'variant_in_stock' => [1 => true],
                    'image_urls' => ['thumb' => '/a.jpg', 'card' => '/a.jpg', 'full' => '/a.jpg'],
                    'product_card' => ['slug' => 'rose', 'name' => 'Red Rose', 'price_display' => '£45.00'],
                    'product_detail' => ['slug' => 'rose', 'name' => 'Red Rose', 'description' => 'Lovely'],
                    'variants' => [['id' => 1, 'sku' => 'RR-1', 'label' => 'Std', 'price_cents' => 4500, 'image_urls' => null]],
                    'is_ai_seeded' => false, 'is_ai_reviewed' => false,
                ],
            ],
            'featured_slugs' => [],
        ],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $this->site->id, 'snapshot_id' => $snap->id, 'updated_at' => now()]);
});

test('product detail renders with description + price', function () {
    $this->get('http://flowers.example/products/rose')
        ->assertOk()
        ->assertSee('Red Rose')
        ->assertSee('£45.00')
        ->assertSee('Lovely');
});

test('404 for missing product slug', function () {
    $this->get('http://flowers.example/products/doesnotexist')->assertNotFound();
});
