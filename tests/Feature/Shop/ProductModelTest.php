<?php

use App\Enums\Shop\ProductStatus;
use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('product can have variants', function () {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();

    expect($product->variants)->toHaveCount(1);
    expect($variant->product->id)->toBe($product->id);
});

test('product belongs to site via site_id', function () {
    $product = Product::factory()->create();
    expect($product->site)->not->toBeNull();
});

test('product can be attached to category with primary pivot flag', function () {
    $product = Product::factory()->create();
    $category = Category::factory()->create(['site_id' => $product->site_id]);
    $product->categories()->attach($category, ['is_primary' => true]);

    expect($product->primaryCategory()->first()->id)->toBe($category->id);
});

test('status cast returns ProductStatus enum', function () {
    $product = Product::factory()->published()->create();
    expect($product->status)->toBe(ProductStatus::Published);
});
