<?php

use App\Models\Shop\CartItem;

/**
 * @return list<array{value: string, checked: bool, disabled: bool, html: string}>
 */
function variantBoxesRadios(string $html): array
{
    preg_match_all('#<input\b(?=[^>]*\btype="radio")(?=[^>]*\bname="variant_id")[^>]*>#i', $html, $matches);

    return array_map(function (string $input): array {
        preg_match('#\bvalue="([^"]*)"#i', $input, $value);

        return [
            'value' => $value[1] ?? '',
            'checked' => (bool) preg_match('#\bchecked\b#i', $input),
            'disabled' => (bool) preg_match('#\bdisabled\b#i', $input),
            'html' => $input,
        ];
    }, $matches[0]);
}

test('a multi-variant PDP renders price-labelled radio boxes and no select', function () {
    [, , $variants] = shopModeMatrixSite('boxes-multi.example', 'cart', [
        ['label' => 'Classic', 'price' => 3800, 'stock' => 4],
        ['label' => 'Grand', 'price' => 4800, 'stock' => 4],
        ['label' => 'Luxe', 'price' => 5800, 'stock' => 4],
    ], slug: 'bouquet', name: 'Bouquet');

    $html = shopModeMatrixGet('boxes-multi.example', '/products/bouquet');
    $radios = variantBoxesRadios($html);

    expect($html)->not->toMatch('/<select\b[^>]*name="variant_id"/i')
        ->and($radios)->toHaveCount(3)
        ->and(array_column($radios, 'value'))->toBe([
            (string) $variants[0]->id,
            (string) $variants[1]->id,
            (string) $variants[2]->id,
        ])
        ->and($html)->toContain('<span class="shop-variant-box__label">Classic</span>')
        ->and($html)->toContain('<span class="shop-variant-box__price">£38.00</span>')
        ->and($html)->toContain('<span class="shop-variant-box__label">Grand</span>')
        ->and($html)->toContain('<span class="shop-variant-box__price">£48.00</span>')
        ->and($html)->toContain('<span class="shop-variant-box__label">Luxe</span>')
        ->and($html)->toContain('<span class="shop-variant-box__price">£58.00</span>');
});

test('the first in-stock variant is checked and an out-of-stock first option is disabled', function () {
    [, , $variants] = shopModeMatrixSite('boxes-oos.example', 'cart', [
        ['label' => 'Classic', 'price' => 3800, 'stock' => 0],
        ['label' => 'Grand', 'price' => 4800, 'stock' => 4],
        ['label' => 'Luxe', 'price' => 5800, 'stock' => 4],
    ], slug: 'bouquet', name: 'Bouquet');

    $html = shopModeMatrixGet('boxes-oos.example', '/products/bouquet');
    $radios = variantBoxesRadios($html);

    expect($radios)->toHaveCount(3)
        ->and($radios[0]['value'])->toBe((string) $variants[0]->id)
        ->and($radios[0]['disabled'])->toBeTrue()
        ->and($radios[0]['checked'])->toBeFalse()
        ->and($radios[1]['value'])->toBe((string) $variants[1]->id)
        ->and($radios[1]['checked'])->toBeTrue()
        ->and($radios[1]['disabled'])->toBeFalse()
        ->and($radios[2]['checked'])->toBeFalse()
        ->and($html)->toContain('<span class="shop-variant-box__note">Out of stock</span>');
});

test('a single-variant product still emits the hidden variant_id input and no fieldset', function () {
    shopModeMatrixSite('boxes-single.example', 'cart');

    $html = shopModeMatrixGet('boxes-single.example', '/products/conserve');

    expect($html)->not->toContain('shop-variant-boxes')
        ->and($html)->not->toMatch('/<fieldset\b/i')
        ->and($html)->toMatch('/<input type="hidden" name="variant_id"/');
});

test('enquire mode renders no variant-box fieldset', function () {
    shopModeMatrixSite('boxes-enquire.example', 'enquire', [
        ['label' => 'Classic', 'price' => 3800, 'stock' => 4],
        ['label' => 'Grand', 'price' => 4800, 'stock' => 4],
        ['label' => 'Luxe', 'price' => 5800, 'stock' => 4],
    ], slug: 'bouquet', name: 'Bouquet');

    $html = shopModeMatrixGet('boxes-enquire.example', '/products/bouquet');

    expect($html)->not->toContain('shop-variant-boxes')
        ->and($html)->not->toMatch('/<fieldset\b/i')
        ->and($html)->toContain('Enquire about this cake');
});

test('POST /shop/cart/add with a chosen box variant_id adds that variant', function () {
    [, , $variants] = shopModeMatrixSite('boxes-add.example', 'cart', [
        ['label' => 'Classic', 'price' => 3800, 'stock' => 4],
        ['label' => 'Grand', 'price' => 4800, 'stock' => 4],
        ['label' => 'Luxe', 'price' => 5800, 'stock' => 4],
    ], slug: 'bouquet', name: 'Bouquet');

    $this->post('http://boxes-add.example/shop/cart/add', [
        'product_slug' => 'bouquet',
        'variant_id' => $variants[2]->id,
        'qty' => 1,
    ])->assertRedirect();

    expect(CartItem::where('variant_id', $variants[2]->id)->first())->not->toBeNull();
});
