<?php

use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Site;


test('shopperFacingLabel is empty for a single variant even when the label is set', function () {
    $site = Site::factory()->create();
    $product = Product::factory()->for($site)->create();
    $variant = ProductVariant::factory()->for($product)->create(['label' => 'Jar']);

    expect($variant->shopperFacingLabel())->toBe('');
});

test('shopperFacingLabel is empty for the Default placeholder', function () {
    $site = Site::factory()->create();
    $product = Product::factory()->for($site)->create();
    ProductVariant::factory()->for($product)->create(['label' => 'Small']);
    $variant = ProductVariant::factory()->for($product)->create(['label' => 'Default']);

    expect($variant->shopperFacingLabel())->toBe('');
});

test('shopperFacingLabel returns the label when the product has multiple named variants', function () {
    $site = Site::factory()->create();
    $product = Product::factory()->for($site)->create();
    $jar = ProductVariant::factory()->for($product)->create(['label' => 'Jar']);
    ProductVariant::factory()->for($product)->create(['label' => 'Tin']);

    expect($jar->shopperFacingLabel())->toBe('Jar');
});
