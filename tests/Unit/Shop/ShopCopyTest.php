<?php

use App\Models\Site;
use App\Support\Shop\ShopCopy;

test('shop copy defaults to item/items when the site sets no shop_noun', function () {
    expect(ShopCopy::noun(null, 1))->toBe('item')
        ->and(ShopCopy::noun(null, 2))->toBe('items')
        ->and(ShopCopy::noun(null, 0))->toBe('items')
        ->and(ShopCopy::counted(1))->toBe('1 item')
        ->and(ShopCopy::counted(3))->toBe('3 items')
        ->and(ShopCopy::heading())->toBe('Items');
});

test('shop copy reads sites.shop_noun and pluralises it', function () {
    $site = new Site(['shop_noun' => 'bake']);

    expect(ShopCopy::noun($site, 1))->toBe('bake')
        ->and(ShopCopy::noun($site, 2))->toBe('bakes')
        ->and(ShopCopy::counted(3, $site))->toBe('3 bakes')
        ->and(ShopCopy::heading($site))->toBe('Bakes')
        ->and(ShopCopy::pair($site))->toBe(['singular' => 'bake', 'plural' => 'bakes']);
});

test('shop copy treats a blank shop_noun as unset', function () {
    $site = new Site(['shop_noun' => '  ']);

    expect(ShopCopy::noun($site, 2))->toBe('items');
});
