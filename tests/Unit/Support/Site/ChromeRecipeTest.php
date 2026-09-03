<?php

use App\Support\Site\ChromeRecipe;

/**
 * @return array<string, mixed>
 */
function chromeClassicRecipe(array $overrides = []): array
{
    return array_merge([
        'label' => 'Classic',
        'description' => 'Current header',
        'schema_version' => 1,
        'layout' => 'standard',
        'top_bar' => 'auto',
        'nav_row' => 'inline',
        'nav_case' => 'default',
        'logo_height' => 'md',
        'store_controls' => 'icons',
        'sticky_shrink' => 'on',
    ], $overrides);
}

/**
 * @return array<string, mixed>
 */
function chromeCentredRecipe(array $overrides = []): array
{
    return array_merge([
        'schema_version' => 1,
        'layout' => 'centred',
        'top_bar' => 'off',
        'nav_row' => 'beneath',
        'nav_case' => 'caps',
        'logo_height' => 'md',
        'store_controls' => 'icons+labels',
        'sticky_shrink' => 'on',
    ], $overrides);
}

it('accepts the classic chrome recipe', function () {
    expect(ChromeRecipe::errors(chromeClassicRecipe()))->toBe([]);
});

it('accepts a centred chrome recipe with only the required layout key', function () {
    expect(ChromeRecipe::errors(['layout' => 'centred']))->toBe([]);
});

it('requires layout', function () {
    $errors = ChromeRecipe::errors(['top_bar' => 'off']);

    expect($errors)->not->toBeEmpty()
        ->and(collect($errors)->implode(' '))->toContain('layout');
});

it('rejects an unknown key', function () {
    $errors = ChromeRecipe::errors(chromeCentredRecipe(['hero_mode' => 'force']));

    expect(collect($errors)->implode(' '))->toContain('hero_mode');
});

it('rejects a bad enum value', function (string $key, mixed $value) {
    $errors = ChromeRecipe::errors(chromeCentredRecipe([$key => $value]));

    expect($errors)->not->toBeEmpty()
        ->and(collect($errors)->implode(' '))->toContain($key);
})->with([
    'layout' => ['layout', 'split'],
    'top_bar' => ['top_bar', 'on'],
    'nav_row' => ['nav_row', 'above'],
    'nav_case' => ['nav_case', 'upper'],
    'nav_container_style' => ['nav_container_style', 'rounded'],
    'nav_container_fill' => ['nav_container_fill', 'white'],
    'logo_height' => ['logo_height', 'xxl'],
    'store_controls' => ['store_controls', 'labels'],
    'sticky_shrink' => ['sticky_shrink', 'true'],
    'shop_nav_style' => ['shop_nav_style', 'menu'],
    'store_controls_slot' => ['store_controls_slot', 'left'],
]);

test('shop_nav_style accepts link dropdown and mega', function (string $value) {
    expect(ChromeRecipe::errors(chromeCentredRecipe(['shop_nav_style' => $value])))->toBe([]);
})->with(['link', 'dropdown', 'mega']);

it('uses the ChromeKnobs nav container enums', function () {
    expect(ChromeRecipe::ENUMS['nav_container_style'])->toBe(\App\Support\ChromeKnobs::NAV_CONTAINER_STYLES)
        ->and(ChromeRecipe::ENUMS['nav_container_fill'])->toBe(\App\Support\ChromeKnobs::NAV_CONTAINER_FILLS);

    foreach (\App\Support\ChromeKnobs::NAV_CONTAINER_STYLES as $style) {
        expect(ChromeRecipe::errors(chromeCentredRecipe(['nav_container_style' => $style])))->toBe([]);
    }

    foreach (\App\Support\ChromeKnobs::NAV_CONTAINER_FILLS as $fill) {
        expect(ChromeRecipe::errors(chromeCentredRecipe(['nav_container_fill' => $fill])))->toBe([]);
    }
});

it('rejects centred recipes that leave top_bar on auto', function () {
    $errors = ChromeRecipe::errors(chromeCentredRecipe(['top_bar' => 'auto']));

    expect(collect($errors)->implode(' '))->toContain('top_bar')
        ->and(collect($errors)->implode(' '))->toContain('off');
});

it('accepts centred when top_bar is omitted (defaults to off)', function () {
    $recipe = chromeCentredRecipe();
    unset($recipe['top_bar']);

    expect(ChromeRecipe::errors($recipe))->toBe([]);
});

it('rejects a non-integer schema_version', function () {
    $errors = ChromeRecipe::errors(chromeClassicRecipe(['schema_version' => '1']));

    expect(collect($errors)->implode(' '))->toContain('schema_version');
});

test('logo_height accepts xl (added 29 Aug for badge logos on the centred layout)', function () {
    expect(ChromeRecipe::errors(chromeCentredRecipe(['logo_height' => 'xl'])))->toBe([]);
});

test('brand_pattern accepts image', function () {
    expect(ChromeRecipe::errors(chromeCentredRecipe(['brand_pattern' => 'image'])))->toBe([]);
});

test('brand_pattern still accepts none swirl and dots', function (string $value) {
    expect(ChromeRecipe::errors(chromeCentredRecipe(['brand_pattern' => $value])))->toBe([]);
})->with(['none', 'swirl', 'dots']);

test('brand_pattern rejects an unknown value', function () {
    $errors = ChromeRecipe::errors(chromeCentredRecipe(['brand_pattern' => 'stripes']));

    expect(collect($errors)->implode(' '))->toContain('brand_pattern');
});

test('rejects brand_image_fit as a recipe key — fit is per-site data', function () {
    $errors = ChromeRecipe::errors(chromeCentredRecipe(['brand_image_fit' => 'cover']));

    expect(collect($errors)->implode(' '))->toContain('brand_image_fit');
});

test('nav_row_pattern accepts none swirl dots and image', function (string $value) {
    expect(ChromeRecipe::errors(chromeCentredRecipe(['nav_row_pattern' => $value])))->toBe([]);
})->with(['none', 'swirl', 'dots', 'image']);

test('nav_row_pattern rejects an unknown value', function () {
    $errors = ChromeRecipe::errors(chromeCentredRecipe(['nav_row_pattern' => 'stripes']));

    expect(collect($errors)->implode(' '))->toContain('nav_row_pattern');
});

test('rejects nav_row_bg as a recipe key — colour is per-site data', function () {
    $errors = ChromeRecipe::errors(chromeCentredRecipe(['nav_row_bg' => '#111111']));

    expect(collect($errors)->implode(' '))->toContain('nav_row_bg');
});
