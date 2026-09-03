<?php

use App\Models\Site;
use App\Services\Site\ThemeResolver;


beforeEach(fn () => $this->resolver = app(ThemeResolver::class));

function defaultBriefFixture(): array
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

function resolveWithOverrides(Site $site, array $composition, ?array $brief = null): array
{
    // Pass null explicitly for "no brief"; omit to use the default fixture.
    $briefPayload = func_num_args() >= 3 ? $brief : defaultBriefFixture();

    return app(ThemeResolver::class)->resolve($site, [], $composition, $briefPayload);
}

test('tertiary colour override wins over design brief', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $theme = resolveWithOverrides($site, ['tertiary_override' => '#abcdef']);

    expect($theme['tertiary_color'])->toBe('#abcdef');
    expect($theme['primary_color'])->toBe('#1f3a5f'); // brief default, untouched
});

test('surface + surface_alt + border + text + text_muted overrides all honoured', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $theme = resolveWithOverrides($site, [
        'surface_override' => '#112233',
        'surface_alt_override' => '#223344',
        'border_override' => '#334455',
        'text_override' => '#445566',
        'text_muted_override' => '#556677',
    ]);

    expect($theme['surface_color'])->toBe('#112233');
    expect($theme['surface_alt_color'])->toBe('#223344');
    expect($theme['border_color'])->toBe('#334455');
    expect($theme['text_color'])->toBe('#445566');
    expect($theme['text_muted_color'])->toBe('#556677');
});

test('display_font + body_font overrides applied when in allowlist', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $theme = resolveWithOverrides($site, [
        'display_font_override' => 'space-grotesk',
        'body_font_override' => 'manrope',
    ]);

    expect($theme['display_font'])->toBe('space-grotesk');
    expect($theme['body_font'])->toBe('manrope');
});

test('font override rejected when value is not in the allowlist', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $theme = resolveWithOverrides($site, [
        'display_font_override' => 'comic-sans',
    ]);

    // Falls through to brief's value
    expect($theme['display_font'])->toBe('fraunces');
});

test('heading_scale + spacing_density + corner_style overrides honoured', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $theme = resolveWithOverrides($site, [
        'heading_scale_override' => 'tight',
        'spacing_density_override' => 'generous',
        'corner_style_override' => 'rounded',
    ]);

    expect($theme['heading_scale'])->toBe('tight');
    expect($theme['spacing_density'])->toBe('generous');
    expect($theme['corner_style'])->toBe('rounded');
});

test('legacy 2-key composition.theme still applies primary + accent only', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $theme = resolveWithOverrides($site, [
        'key' => 'trades-bold',
        'primary_override' => '#ff7300',
        'accent_override' => '#005a87',
    ]);

    expect($theme['primary_color'])->toBe('#ff7300');
    expect($theme['accent_color'])->toBe('#005a87');
    // No tertiary_override → brief value wins
    expect($theme['tertiary_color'])->toBe('#f4ede0');
});

test('composition with only invalid data is a no-op', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $theme = resolveWithOverrides($site, [
        'primary_override' => 'not-a-hex',
        'display_font_override' => 'papyrus',
    ]);

    // Brief defaults preserved
    expect($theme['primary_color'])->toBe('#1f3a5f');
    expect($theme['display_font'])->toBe('fraunces');
});

test('override without brief — extracts first then applies overrides', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $theme = app(ThemeResolver::class)->resolve($site, [], [
        'primary_override' => '#112233',
        'tertiary_override' => '#445566',
    ], null);

    expect($theme['primary_color'])->toBe('#112233');
    expect($theme['tertiary_color'])->toBe('#445566');
    // Surface falls back to hardcoded default — no brief + no override
    expect($theme['surface_color'])->toBe('#ffffff');
});
