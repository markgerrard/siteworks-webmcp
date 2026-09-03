<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->site = Site::factory()->create([
        'custom_domain' => 'cakebox.example',
        'custom_domain_status' => 'active',
        'business_name' => 'Cakebox',
    ]);
    $json = [
        'meta' => ['site_id' => $this->site->id, 'product_count' => 2],
        'category_paths' => [
            'cheesecakes' => 'cheesecakes',
            'cakes/wedding-cakes' => 'wedding-cakes',
            'cart' => 'cart',
        ],
        'categories' => [
            'cheesecakes' => [
                'id' => 1,
                'slug' => 'cheesecakes',
                'name' => 'Cheesecakes',
                'path' => 'cheesecakes',
                'visibility' => 'visible',
                'product_slugs' => ['lilac-vintage-ribbon-cake'],
            ],
            'wedding-cakes' => [
                'id' => 2,
                'slug' => 'wedding-cakes',
                'name' => 'Wedding cakes',
                'path' => 'cakes/wedding-cakes',
                'visibility' => 'visible',
                'product_slugs' => [],
            ],
            'cart' => [
                'id' => 3,
                'slug' => 'cart',
                'name' => 'Cart',
                'path' => 'cart',
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
            'cart' => [
                'id' => 2,
                'slug' => 'cart',
                'status' => 'published',
                'primary_category_slug' => null,
                'price_cents' => 1000,
                'price_display' => '£10.00',
                'in_stock_any' => true,
                'variant_in_stock' => [2 => true],
                'image_urls' => null,
                'product_card' => ['slug' => 'cart', 'name' => 'Cart cake', 'price_display' => '£10.00'],
                'product_detail' => ['slug' => 'cart', 'name' => 'Cart cake', 'description' => 'Reserved'],
                'variants' => [['id' => 2, 'sku' => 'CART-1', 'label' => 'Std', 'price_cents' => 1000, 'image_urls' => null]],
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

test('canonical product and collection URLs return 200', function () {
    $this->get('http://cakebox.example/products/lilac-vintage-ribbon-cake')
        ->assertOk()
        ->assertSee('Lilac vintage ribbon cake');

    $this->get('http://cakebox.example/collections/cheesecakes')
        ->assertOk()
        ->assertSee('Cheesecakes');
});

test('shop index stays at /shop', function () {
    $this->get('http://cakebox.example/shop')
        ->assertOk()
        ->assertSee('Lilac vintage ribbon cake');
});

test('legacy product and collection URLs 301 to the canonical paths and preserve the query string', function () {
    $product = $this->get('http://cakebox.example/shop/p/lilac-vintage-ribbon-cake?utm=share');
    $product->assertStatus(301);
    expect($product->headers->get('Location'))
        ->toEndWith('/products/lilac-vintage-ribbon-cake?utm=share');

    $collection = $this->get('http://cakebox.example/shop/c/cheesecakes?sort=price');
    $collection->assertStatus(301);
    expect($collection->headers->get('Location'))
        ->toEndWith('/collections/cheesecakes?sort=price');
});

test('legacy nested collection URLs 301 to /collections/{path}', function () {
    $response = $this->get('http://cakebox.example/shop/c/cakes/wedding-cakes');
    $response->assertStatus(301);
    expect($response->headers->get('Location'))
        ->toEndWith('/collections/cakes/wedding-cakes');

    $this->get('http://cakebox.example/collections/cakes/wedding-cakes')
        ->assertOk()
        ->assertSee('Wedding cakes');
});

test('HEAD is accepted on canonical and legacy shop URLs', function () {
    $this->call('HEAD', 'http://cakebox.example/products/lilac-vintage-ribbon-cake')->assertOk();
    $this->call('HEAD', 'http://cakebox.example/collections/cheesecakes')->assertOk();

    $legacyProduct = $this->call('HEAD', 'http://cakebox.example/shop/p/lilac-vintage-ribbon-cake');
    $legacyProduct->assertStatus(301);
    expect($legacyProduct->headers->get('Location'))
        ->toEndWith('/products/lilac-vintage-ribbon-cake');

    $legacyCollection = $this->call('HEAD', 'http://cakebox.example/shop/c/cheesecakes');
    $legacyCollection->assertStatus(301);
    expect($legacyCollection->headers->get('Location'))
        ->toEndWith('/collections/cheesecakes');
});

test('reserved slugs 404 even when a product or category exists', function (string $path) {
    $this->get('http://cakebox.example'.$path)->assertNotFound();
})->with([
    '/products/cart',
    '/products/search',
    '/products/quote',
    '/products/checkout',
    '/products/account',
    '/products/enquire',
    '/products/products',
    '/products/collections',
    '/collections/cart',
    '/collections/search',
    '/collections/quote',
    '/collections/checkout',
    '/collections/account',
    '/collections/enquire',
    '/collections/products',
    '/collections/collections',
]);

test('legacy reserved slugs 404 rather than 301 into a shadowed route', function () {
    $this->get('http://cakebox.example/shop/p/cart')->assertNotFound();
    $this->get('http://cakebox.example/shop/c/cart')->assertNotFound();
});
