<?php

use App\Models\Shop\Product;
use App\Models\Shop\ProductImage;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ProductVariantImage;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('product has many images ordered by sort_order', function () {
    $product = Product::factory()->create();
    ProductImage::create(['product_id' => $product->id, 'path' => 'b.jpg', 'sort_order' => 2]);
    ProductImage::create(['product_id' => $product->id, 'path' => 'a.jpg', 'sort_order' => 1]);

    expect($product->images->pluck('path')->toArray())->toBe(['a.jpg', 'b.jpg']);
});

test('variant has own image set overriding product gallery', function () {
    $variant = ProductVariant::factory()->create();
    ProductVariantImage::create(['variant_id' => $variant->id, 'path' => 'red.jpg']);

    expect($variant->images)->toHaveCount(1);
});

test('url is built from the media disk not the default disk', function () {
    Storage::fake('other-disk', ['url' => 'https://default-disk.test/storage']);
    Storage::fake('media-disk', ['url' => 'https://media-disk.test/storage']);
    config([
        'filesystems.default' => 'other-disk',
        'filesystems.media' => 'media-disk',
    ]);

    $product = Product::factory()->create();
    $image = ProductImage::create([
        'product_id' => $product->id,
        'path' => 'products/croissant.jpg',
        'sort_order' => 0,
        'alt' => 'Croissant',
    ]);

    expect($image->url())
        ->toStartWith('https://media-disk.test/storage/')
        ->not->toContain('https://default-disk.test/storage');
});

