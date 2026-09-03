<?php

use App\Models\LayoutPreset;
use App\Models\Site;
use App\Services\Site\PageLayoutRegistry;
use App\Support\ChromeKnobs;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function chromeRegistryCentredRecipe(array $overrides = []): array
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

it('lists classic from config and the site active chrome rows', function () {
    $site = Site::factory()->create();
    LayoutPreset::factory()->for($site)->active()->create([
        'page_kind' => 'chrome',
        'key' => 'centred-badge',
        'label' => 'Centred badge',
        'description' => 'Badge logo, nav beneath',
        'recipe' => chromeRegistryCentredRecipe(),
    ]);

    $options = app(PageLayoutRegistry::class)->optionsFor($site, 'chrome');

    expect($options)->toHaveKey('classic')
        ->and($options['classic']['label'])->toBe('Classic')
        ->and($options)->toHaveKey('centred-badge')
        ->and($options['centred-badge']['label'])->toBe('Centred badge');
});

it('resolves an active site chrome row over config and falls back to classic', function () {
    $site = Site::factory()->create(['chrome_layout' => 'centred-badge']);
    LayoutPreset::factory()->for($site)->active()->create([
        'page_kind' => 'chrome',
        'key' => 'centred-badge',
        'label' => 'Centred badge',
        'recipe' => chromeRegistryCentredRecipe(),
    ]);

    $registry = app(PageLayoutRegistry::class);

    expect($registry->resolve($site, 'chrome')['layout'])->toBe('centred')
        ->and($registry->resolveKey($site, 'chrome', 'classic'))->toBeNull();

    $classicSite = Site::factory()->create(['chrome_layout' => 'classic']);
    expect($registry->resolve($classicSite, 'chrome'))->toBeNull()
        ->and(ChromeKnobs::recipe($classicSite)['layout'])->toBe('standard');
});

it('falls back to classic when the chrome row is hard-invalid', function () {
    $site = Site::factory()->create(['chrome_layout' => 'broken-chrome']);
    LayoutPreset::factory()->for($site)->active()->create([
        'page_kind' => 'chrome',
        'key' => 'broken-chrome',
        'recipe' => ['layout' => 'centred', 'unknown' => true],
    ]);

    $registry = app(PageLayoutRegistry::class);

    expect($registry->resolve($site, 'chrome'))->toBeNull()
        ->and($registry->isUsable(['layout' => 'centred', 'unknown' => true], 'chrome'))->toBeFalse()
        ->and(ChromeKnobs::recipe($site->fresh())['layout'])->toBe('standard');
});

it('headerMode returns solid when the chrome recipe layout is centred', function () {
    $site = Site::factory()->create([
        'header_mode' => 'overlay',
        'chrome_layout' => 'centred-badge',
    ]);
    LayoutPreset::factory()->for($site)->active()->create([
        'page_kind' => 'chrome',
        'key' => 'centred-badge',
        'recipe' => chromeRegistryCentredRecipe(),
    ]);

    expect(ChromeKnobs::headerMode($site->fresh()))->toBe('solid')
        ->and(ChromeKnobs::layout($site->fresh()))->toBe('centred')
        ->and(ChromeKnobs::navCase($site->fresh()))->toBe('caps');
});

it('keeps existing knob getters for the classic chrome recipe', function () {
    $site = Site::factory()->create([
        'header_mode' => 'overlay',
        'nav_case' => 'upper',
        'header_shrink' => 'off',
        'chrome_layout' => 'classic',
    ]);

    expect(ChromeKnobs::headerMode($site))->toBe('overlay')
        ->and(ChromeKnobs::layout($site))->toBe('standard')
        ->and(ChromeKnobs::navCase($site))->toBe('upper')
        ->and(ChromeKnobs::headerShrink($site))->toBe('off');
});
