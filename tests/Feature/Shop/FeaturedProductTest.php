<?php

use App\Models\Shop\FeaturedProduct;
use App\Models\Shop\Product;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('active scope includes rows with null starts_at and ends_at', function () {
    $product = Product::factory()->create();
    FeaturedProduct::create(['site_id' => $product->site_id, 'product_id' => $product->id, 'sort_order' => 0]);

    expect(FeaturedProduct::active()->count())->toBe(1);
});

test('active scope excludes future starts_at', function () {
    $product = Product::factory()->create();
    FeaturedProduct::create([
        'site_id' => $product->site_id,
        'product_id' => $product->id,
        'sort_order' => 0,
        'starts_at' => now()->addDay(),
    ]);

    expect(FeaturedProduct::active()->count())->toBe(0);
});

test('active scope excludes past ends_at', function () {
    $product = Product::factory()->create();
    FeaturedProduct::create([
        'site_id' => $product->site_id,
        'product_id' => $product->id,
        'sort_order' => 0,
        'ends_at' => now()->subDay(),
    ]);

    expect(FeaturedProduct::active()->count())->toBe(0);
});
