<?php

use App\Models\Shop\Product;
use App\Support\Shop\ShopUrls;
use Illuminate\Support\Facades\URL;

test('product builds a relative storefront path from a slug or product', function () {
    $product = new Product(['slug' => 'lilac-vintage-ribbon-cake']);

    expect(ShopUrls::product('lilac-vintage-ribbon-cake'))->toBe('/products/lilac-vintage-ribbon-cake')
        ->and(ShopUrls::product($product))->toBe('/products/lilac-vintage-ribbon-cake');
});

test('collection preserves nested paths', function () {
    expect(ShopUrls::collection('cheesecakes'))->toBe('/collections/cheesecakes')
        ->and(ShopUrls::collection('cakes/wedding-cakes'))->toBe('/collections/cakes/wedding-cakes');
});

test('absolute variants prefix the current request root', function () {
    URL::forceRootUrl('http://cakebox.example');
    URL::forceScheme('http');

    expect(ShopUrls::productAbsolute('lilac-vintage-ribbon-cake'))
        ->toBe('http://cakebox.example/products/lilac-vintage-ribbon-cake')
        ->and(ShopUrls::collectionAbsolute('cakes/wedding-cakes'))
        ->toBe('http://cakebox.example/collections/cakes/wedding-cakes');
});

test('reserved slugs and collection path segments are detected', function () {
    expect(ShopUrls::isReservedSlug('cart'))->toBeTrue()
        ->and(ShopUrls::isReservedSlug('products'))->toBeTrue()
        ->and(ShopUrls::isReservedSlug('lilac-vintage-ribbon-cake'))->toBeFalse()
        ->and(ShopUrls::isReservedPath('cart'))->toBeTrue()
        ->and(ShopUrls::isReservedPath('cakes/cart'))->toBeTrue()
        ->and(ShopUrls::isReservedPath('cheesecakes'))->toBeFalse();
});
