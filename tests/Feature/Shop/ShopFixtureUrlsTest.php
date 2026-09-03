<?php

test('shop-mode and shop-enabled byte fixtures carry canonical URLs only', function () {
    $files = [
        base_path('tests/Fixtures/shop-mode/cart-product-card.html'),
        base_path('tests/Fixtures/shop-mode/enquire-product-card.html'),
        base_path('tests/Fixtures/shop-mode/cart-cart-page.html'),
        base_path('tests/Fixtures/shop-mode/cart-drawer.html'),
        base_path('tests/Fixtures/shop-mode/enquire-drawer.html'),
        base_path('tests/Fixtures/ByteIdentity/shop-enabled-chrome.html'),
    ];

    foreach ($files as $file) {
        $html = (string) file_get_contents($file);
        expect($html)->not->toContain('/shop/p/', $file.' still has /shop/p/')
            ->and($html)->not->toContain('/shop/c/', $file.' still has /shop/c/');
    }

    expect((string) file_get_contents(base_path('tests/Fixtures/shop-mode/cart-product-card.html')))
        ->toContain('/products/conserve');
});
