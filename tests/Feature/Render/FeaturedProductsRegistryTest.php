<?php

use App\Services\Site\PageLayoutRegistry;

it('lists featured_products as a home file-backed family', function () {
    expect(PageLayoutRegistry::ALLOWED_FAMILIES['home'])->toContain('featured_products')
        ->and(PageLayoutRegistry::FILE_BACKED_FAMILIES)->toContain('featured_products')
        ->and(app(PageLayoutRegistry::class)->fileBackedFamiliesFor('home'))->toContain('featured_products')
        ->and(PageLayoutRegistry::ALLOWED_FAMILIES['service'])->not->toContain('featured_products')
        ->and(PageLayoutRegistry::ALLOWED_FAMILIES['about'])->not->toContain('featured_products');
});

it('does not stamp featured_products on any home preset and every preset still validates', function () {
    $registry = app(PageLayoutRegistry::class);
    $presets = config('site_home_layouts');

    expect($presets)->toBeArray()->not->toBeEmpty();

    foreach ($presets as $key => $recipe) {
        expect($recipe)->toBeArray()
            ->and($recipe['variants'] ?? [])->not->toHaveKey('featured_products');

        expect($registry->isUsable($recipe, 'home'))->toBeTrue();

        $hard = array_values(array_filter(
            $registry->validate($recipe, 'home'),
            fn (string $error): bool => ! str_starts_with($error, 'Warning:'),
        ));
        expect($hard)->toBe([]);
    }
});
