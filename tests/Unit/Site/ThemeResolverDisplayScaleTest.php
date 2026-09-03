<?php

use App\Models\Site;
use App\Services\Site\ThemeResolver;

beforeEach(fn () => $this->resolver = app(ThemeResolver::class));

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function displayScaleTokens(array $extra = []): array
{
    return app(ThemeResolver::class)->renderTokens(array_merge([
        'primary_color' => '#1f3a5f',
        'accent_color' => '#8b6b2f',
        'spacing_density' => 'balanced',
        'heading_scale' => 'balanced',
        'corner_style' => 'soft',
        'display_font' => 'inter',
        'body_font' => 'inter',
    ], $extra));
}

test('auto container_width matches the spacing_density map for every density', function (string $density, string $expected) {
    $tokens = displayScaleTokens(['spacing_density' => $density]);

    expect($tokens['container_width'])->toBe($expected);
})->with([
    'compact' => ['compact', '1280px'],
    'balanced' => ['balanced', '1280px'],
    'generous' => ['generous', '1360px'],
]);

test('explicit container_width auto is identical to omitting the key', function (string $density) {
    $omitted = displayScaleTokens(['spacing_density' => $density]);
    $explicitAuto = displayScaleTokens([
        'spacing_density' => $density,
        'container_width' => 'auto',
    ]);

    expect($explicitAuto['container_width'])->toBe($omitted['container_width']);
})->with(['compact', 'balanced', 'generous']);

test('explicit container_width tiers ignore spacing_density', function (string $tier, string $expected) {
    foreach (['compact', 'balanced', 'generous'] as $density) {
        $tokens = displayScaleTokens([
            'spacing_density' => $density,
            'container_width' => $tier,
        ]);

        expect($tokens['container_width'])->toBe($expected);
    }
})->with([
    'standard' => ['standard', '1280px'],
    'wide' => ['wide', '1440px'],
    'grand' => ['grand', '1680px'],
]);

test('resolve defaults container_width to auto so existing sites keep the density map', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold', 'design_brief' => null]);

    $theme = $this->resolver->resolve($site, []);
    $tokens = $this->resolver->renderTokens($theme);

    expect($theme['container_width'])->toBe('auto')
        ->and($tokens['container_width'])->toBe('1280px');
});

test('container_width_override is honoured and invalid values fall through to auto', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold', 'design_brief' => null]);

    $wide = $this->resolver->resolve($site, [], ['container_width_override' => 'wide']);
    $invalid = $this->resolver->resolve($site, [], ['container_width_override' => 'full']);

    expect($wide['container_width'])->toBe('wide')
        ->and($this->resolver->renderTokens($wide)['container_width'])->toBe('1440px')
        ->and($invalid['container_width'])->toBe('auto')
        ->and($this->resolver->renderTokens($invalid)['container_width'])->toBe('1280px');
});

test('standard display_scale emits the current token literals', function () {
    $tokens = displayScaleTokens([
        'display_scale' => 'standard',
        'spacing_density' => 'balanced',
        'container_width' => 'auto',
    ]);
    $omitted = displayScaleTokens(['spacing_density' => 'balanced']);

    expect($tokens['container_width'])->toBe('1280px')
        ->and($tokens['section_spacing'])->toBe('6rem')
        ->and($tokens['hero_home_clamp_cap'])->toBe('3.75rem')
        ->and($tokens['hero_inner_clamp_cap'])->toBe('3rem')
        ->and($tokens['nav_padding_class'])->toBe('px-4 sm:px-6 lg:px-8')
        ->and($tokens['chrome_padding_y'])->toBe('')
        ->and($tokens['store_control_icon_class'])->toBe('h-4 w-4')
        ->and($tokens['store_control_text_class'])->toBe('text-sm')
        ->and($tokens['shell_inset_xl'])->toBe('')
        ->and($tokens['chrome_brand_row_class'])->toBe('h-[104px] lg:h-[120px]')
        ->and($omitted['container_width'])->toBe($tokens['container_width'])
        ->and($omitted['section_spacing'])->toBe($tokens['section_spacing'])
        ->and($omitted['hero_home_clamp_cap'])->toBe($tokens['hero_home_clamp_cap'])
        ->and($omitted['hero_inner_clamp_cap'])->toBe($tokens['hero_inner_clamp_cap'])
        ->and($omitted['nav_padding_class'])->toBe($tokens['nav_padding_class'])
        ->and($omitted['chrome_padding_y'])->toBe($tokens['chrome_padding_y'])
        ->and($omitted['shell_inset_xl'])->toBe($tokens['shell_inset_xl'])
        ->and($omitted['chrome_brand_row_class'])->toBe($tokens['chrome_brand_row_class']);
});

test('grand display_scale shifts tokens when no explicit knobs are set', function (string $density, string $expectedSpacing) {
    $tokens = displayScaleTokens([
        'display_scale' => 'grand',
        'spacing_density' => $density,
        'container_width' => 'auto',
    ]);

    expect($tokens['container_width'])->toBe('1680px')
        ->and($tokens['section_spacing'])->toBe($expectedSpacing)
        ->and($tokens['hero_home_clamp_cap'])->toBe('4.5rem')
        ->and($tokens['hero_inner_clamp_cap'])->toBe('3.75rem')
        ->and($tokens['nav_padding_class'])->toBe('px-4 sm:px-6 lg:px-8') // same inset as sections: header edges align at every scale
        ->and($tokens['chrome_padding_y'])->toBe('0.5rem')
        ->and($tokens['chrome_brand_row_class'])->toBe('h-[120px] lg:h-[136px]')
        ->and($tokens['shell_inset_xl'])->toBe('4rem')
        ->and($tokens['store_control_icon_class'])->toBe('h-5 w-5')
        ->and($tokens['store_control_text_class'])->toBe('text-base');
})->with([
    'compact' => ['compact', '6rem'],
    'balanced' => ['balanced', '8rem'],
    'generous' => ['generous', '8rem'],
]);

test('explicit container_width beats the grand display_scale preset', function () {
    $tokens = displayScaleTokens([
        'display_scale' => 'grand',
        'container_width' => 'standard',
        'spacing_density' => 'balanced',
    ]);

    expect($tokens['container_width'])->toBe('1280px')
        ->and($tokens['section_spacing'])->toBe('8rem');
});

test('explicit spacing_density beats the grand section_spacing bump', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold', 'design_brief' => null]);

    $theme = $this->resolver->resolve($site, [], [
        'display_scale_override' => 'grand',
        'spacing_density_override' => 'compact',
    ]);
    $tokens = $this->resolver->renderTokens($theme);

    expect($theme['display_scale'])->toBe('grand')
        ->and($theme['spacing_density'])->toBe('compact')
        ->and($theme['spacing_density_explicit'])->toBeTrue()
        ->and($tokens['section_spacing'])->toBe('4rem')
        ->and($tokens['container_width'])->toBe('1680px');
});

test('brief spacing_density is not an explicit knob so grand still bumps it', function () {
    $site = Site::factory()->create([
        'theme' => 'trades-bold',
        'design_brief' => [
            'mood' => 'warm-traditional',
            'display_font' => 'fraunces',
            'body_font' => 'source-sans-3',
            'heading_scale' => 'balanced',
            'spacing_density' => 'compact',
            'corner_style' => 'soft',
            'palette' => [
                'primary' => '#1f3a5f',
                'accent' => '#8b6b2f',
                'tertiary' => '#f4ede0',
                'surface' => '#ffffff',
                'surface_alt' => '#f8f5ee',
                'border' => '#e4ddcf',
                'text' => '#1a1a1a',
                'text_muted' => '#6b7280',
            ],
        ],
    ]);

    $theme = $this->resolver->resolve($site, [], ['display_scale_override' => 'grand']);
    $tokens = $this->resolver->renderTokens($theme);

    expect($theme['spacing_density'])->toBe('compact')
        ->and($theme['spacing_density_explicit'] ?? false)->toBeFalse()
        ->and($tokens['section_spacing'])->toBe('6rem');
});

test('resolve defaults display_scale to standard and rejects unknown values', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold', 'design_brief' => null]);

    $default = $this->resolver->resolve($site, []);
    $invalid = $this->resolver->resolve($site, [], ['display_scale_override' => 'xl']);
    $grand = $this->resolver->resolve($site, [], ['display_scale_override' => 'grand']);

    expect($default['display_scale'])->toBe('standard')
        ->and($invalid['display_scale'])->toBe('standard')
        ->and($grand['display_scale'])->toBe('grand');
});
