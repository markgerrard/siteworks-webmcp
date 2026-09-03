<?php

use App\Services\Site\PageLayoutRegistry;

it('lists category_rail as a home file-backed family', function () {
    expect(PageLayoutRegistry::ALLOWED_FAMILIES['home'])->toContain('category_rail')
        ->and(PageLayoutRegistry::FILE_BACKED_FAMILIES)->toContain('category_rail')
        ->and(app(PageLayoutRegistry::class)->fileBackedFamiliesFor('home'))->toContain('category_rail')
        ->and(PageLayoutRegistry::ALLOWED_FAMILIES['service'])->not->toContain('category_rail')
        ->and(PageLayoutRegistry::ALLOWED_FAMILIES['about'])->not->toContain('category_rail');
});

it('does not stamp category_rail on any home preset and every preset still validates', function () {
    $registry = app(PageLayoutRegistry::class);
    $presets = config('site_home_layouts');

    expect($presets)->toBeArray()->not->toBeEmpty();

    foreach ($presets as $key => $recipe) {
        expect($recipe)->toBeArray()
            ->and($recipe['variants'] ?? [])->not->toHaveKey('category_rail');

        expect($registry->isUsable($recipe, 'home'))->toBeTrue();

        $hard = array_values(array_filter(
            $registry->validate($recipe, 'home'),
            fn (string $error): bool => ! str_starts_with($error, 'Warning:'),
        ));
        expect($hard)->toBe([]);
    }
});
