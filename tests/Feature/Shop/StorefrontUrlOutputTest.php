<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->site = Site::factory()->create([
        'custom_domain' => 'output.example',
        'custom_domain_status' => 'active',
        'business_name' => 'Cakebox',
    ]);
    $json = [
        'meta' => ['site_id' => $this->site->id, 'product_count' => 1],
        'category_paths' => [
            'cheesecakes' => 'cheesecakes',
            'cakes/wedding-cakes' => 'wedding-cakes',
        ],
        'categories' => [
            'cheesecakes' => [
                'id' => 1,
                'slug' => 'cheesecakes',
                'name' => 'Cheesecakes',
                'path' => 'cheesecakes',
                'visibility' => 'visible',
                'product_slugs' => ['lilac-vintage-ribbon-cake'],
                'children' => ['wedding-cakes'],
            ],
            'wedding-cakes' => [
                'id' => 2,
                'slug' => 'wedding-cakes',
                'name' => 'Wedding cakes',
                'path' => 'cakes/wedding-cakes',
                'visibility' => 'visible',
                'product_slugs' => [],
            ],
        ],
        'products' => [
            'lilac-vintage-ribbon-cake' => [
                'id' => 1,
                'slug' => 'lilac-vintage-ribbon-cake',
                'status' => 'published',
                'primary_category_slug' => 'cheesecakes',
                'price_cents' => 4500,
                'price_display' => '£45.00',
                'in_stock_any' => true,
                'variant_in_stock' => [1 => true],
                'image_urls' => ['thumb' => '/a.jpg', 'card' => '/a.jpg', 'full' => '/a.jpg'],
                'product_card' => ['slug' => 'lilac-vintage-ribbon-cake', 'name' => 'Lilac vintage ribbon cake', 'price_display' => '£45.00'],
                'product_detail' => ['slug' => 'lilac-vintage-ribbon-cake', 'name' => 'Lilac vintage ribbon cake', 'description' => 'A ribbon cake'],
                'variants' => [['id' => 1, 'sku' => 'LVR-1', 'label' => 'Std', 'price_cents' => 4500, 'image_urls' => null]],
                'is_ai_seeded' => false,
                'is_ai_reviewed' => false,
            ],
        ],
        'featured_slugs' => ['lilac-vintage-ribbon-cake'],
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

test('storefront HTML never emits /shop/p/ or /shop/c/', function () {
    $shop = $this->get('http://output.example/shop')->assertOk()->getContent();
    $pdp = $this->get('http://output.example/products/lilac-vintage-ribbon-cake')->assertOk()->getContent();
    $collection = $this->get('http://output.example/collections/cheesecakes')->assertOk()->getContent();
    $nested = $this->get('http://output.example/collections/cakes/wedding-cakes')->assertOk()->getContent();

    foreach ([$shop, $pdp, $collection, $nested] as $html) {
        expect($html)->not->toContain('/shop/p/')
            ->and($html)->not->toContain('/shop/c/');
    }

    expect($shop)->toContain('/products/lilac-vintage-ribbon-cake')
        ->and($pdp)->toContain('/products/lilac-vintage-ribbon-cake')
        ->and($collection)->toContain('/collections/cheesecakes')
        ->and($nested)->toContain('/collections/cakes/wedding-cakes');
});
