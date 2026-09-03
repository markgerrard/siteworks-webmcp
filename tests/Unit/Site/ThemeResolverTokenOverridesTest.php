<?php

use App\Models\Site;
use App\Services\Site\ThemeResolver;


beforeEach(fn () => $this->resolver = app(ThemeResolver::class));

function tokenOverrideBrief(): array
{
    return [
        'mood' => 'warm-traditional',
        'display_font' => 'fraunces',
        'body_font' => 'source-sans-3',
        'heading_scale' => 'balanced',
        'spacing_density' => 'balanced',
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
    ];
}

function resolveOverrideTokens(Site $site, array $compositionTheme): array
{
    $resolver = app(ThemeResolver::class);
    $theme = $resolver->resolve($site, [], $compositionTheme, tokenOverrideBrief());

    return $resolver->renderTokens($theme);
}

test('absent token_overrides is byte-identical to an empty map', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);

    $absent = resolveOverrideTokens($site, []);
    $empty = resolveOverrideTokens($site, ['token_overrides' => []]);

    expect($empty)->toBe($absent);
});

test('a site-wide token override changes exactly the named emitted token', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $baseline = resolveOverrideTokens($site, []);

    $overridden = resolveOverrideTokens($site, [
        'token_overrides' => ['color-band' => '#f7f2ea'],
    ]);

    expect($overridden['band'])->toBe('#f7f2ea')
        ->and($overridden['band'])->not->toBe($baseline['band']);

    unset($baseline['band'], $overridden['band']);

    expect($overridden)->toBe($baseline);
});

test('token_overrides beat a legacy colour override for the same final variable', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);

    $tokens = resolveOverrideTokens($site, [
        'surface_override' => '#112233',
        'token_overrides' => ['color-surface' => '#f7f2ea'],
    ]);

    expect($tokens['surface'])->toBe('#f7f2ea');
});

test('token_overrides are a post-invert literal', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);

    $inverted = resolveOverrideTokens($site, [
        'surface_override' => '#ffffff',
        'invert_mode_override' => true,
    ]);
    $literal = resolveOverrideTokens($site, [
        'surface_override' => '#ffffff',
        'invert_mode_override' => true,
        'token_overrides' => ['color-surface' => '#f7f2ea'],
    ]);

    expect($inverted['surface'])->not->toBe('#ffffff')
        ->and($inverted['surface'])->not->toBe('#f7f2ea')
        ->and($literal['surface'])->toBe('#f7f2ea');
});

test('a radius family override accepts a unit-suffixed value', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $baseline = resolveOverrideTokens($site, []);

    $overridden = resolveOverrideTokens($site, [
        'token_overrides' => ['radius-card' => '2px'],
    ]);

    expect($overridden['radius_card'])->toBe('2px')
        ->and($baseline['radius_card'])->not->toBe('2px');
});

test('unknown or invalid token_overrides are skipped at render time', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $baseline = resolveOverrideTokens($site, []);

    $skipped = resolveOverrideTokens($site, [
        'token_overrides' => [
            'color-footer-bg' => '#101010',
            'color-band' => 'not-a-hex',
            'radius-card' => 'roundy',
        ],
    ]);

    expect($skipped)->toBe($baseline);
});
