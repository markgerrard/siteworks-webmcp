<?php

use App\Models\Shop\Product;
use App\Models\Site;
use App\Support\Shop\ShopSlug;

test('product slugs are Str::slug of the name', function () {
    $site = Site::factory()->create();

    expect(ShopSlug::uniqueProduct($site->id, 'Lilac Vintage Ribbon Cake'))
        ->toBe('lilac-vintage-ribbon-cake');
});

test('per-site collisions append -2 then -3', function () {
    $site = Site::factory()->create();
    Product::factory()->for($site)->create(['slug' => 'victoria-sponge']);
    Product::factory()->for($site)->create(['slug' => 'victoria-sponge-2']);

    expect(ShopSlug::uniqueProduct($site->id, 'Victoria Sponge'))->toBe('victoria-sponge-3');
});

test('reserved slugs are treated as taken', function () {
    $site = Site::factory()->create();

    expect(ShopSlug::uniqueProduct($site->id, 'Cart'))->toBe('cart-2');
});

test('a blank name falls back to product', function () {
    $site = Site::factory()->create();

    expect(ShopSlug::uniqueProduct($site->id, '!!!'))->toBe('product');
});

test('collisions are scoped per site', function () {
    $site = Site::factory()->create();
    $other = Site::factory()->create();
    Product::factory()->for($other)->create(['slug' => 'rose']);

    expect(ShopSlug::uniqueProduct($site->id, 'Rose'))->toBe('rose');
});
