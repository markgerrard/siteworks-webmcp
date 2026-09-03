<?php

use App\Services\Site\PageLayoutRegistry;

it('lists promo_tiles as a home and about file-backed family', function () {
    expect(PageLayoutRegistry::ALLOWED_FAMILIES['home'])->toContain('promo_tiles')
        ->and(PageLayoutRegistry::ALLOWED_FAMILIES['about'])->toContain('promo_tiles')
        ->and(PageLayoutRegistry::FILE_BACKED_FAMILIES)->toContain('promo_tiles')
        ->and(app(PageLayoutRegistry::class)->fileBackedFamiliesFor('home'))->toContain('promo_tiles')
        ->and(app(PageLayoutRegistry::class)->fileBackedFamiliesFor('about'))->toContain('promo_tiles')
        ->and(PageLayoutRegistry::ALLOWED_FAMILIES['service'])->not->toContain('promo_tiles')
        ->and(app(PageLayoutRegistry::class)->fileBackedFamiliesFor('service'))->not->toContain('promo_tiles');
});

it('does not stamp promo_tiles on any home or about preset and every preset still validates', function () {
    $registry = app(PageLayoutRegistry::class);

    foreach (['home' => config('site_home_layouts'), 'about' => config('site_about_layouts')] as $kind => $presets) {
        expect($presets)->toBeArray()->not->toBeEmpty();

        foreach ($presets as $recipe) {
            expect($recipe)->toBeArray()
                ->and($recipe['variants'] ?? [])->not->toHaveKey('promo_tiles');

            expect($registry->isUsable($recipe, $kind))->toBeTrue();

            $hard = array_values(array_filter(
                $registry->validate($recipe, $kind),
                fn (string $error): bool => ! str_starts_with($error, 'Warning:'),
            ));
            expect($hard)->toBe([]);
        }
    }
});
