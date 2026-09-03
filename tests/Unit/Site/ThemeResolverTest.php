<?php

use App\Models\Site;
use App\Services\Site\ThemeResolver;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => $this->resolver = app(ThemeResolver::class));

test('returns trades-bold defaults for unknown theme handle', function () {
    $site = Site::factory()->create(['theme' => 'unknown-theme']);
    $theme = $this->resolver->resolve($site, []);

    expect($theme['primary_color'])->toBe('#1e40af');
    expect($theme['accent_color'])->toBe('#f59e0b');
});

test('returns correct defaults for professional-clean', function () {
    $site = Site::factory()->create(['theme' => 'professional-clean']);
    $theme = $this->resolver->resolve($site, []);

    expect($theme['primary_color'])->toBe('#1f2937');
    expect($theme['accent_color'])->toBe('#6366f1');
});

test('returns correct defaults for local-friendly', function () {
    $site = Site::factory()->create(['theme' => 'local-friendly']);
    $theme = $this->resolver->resolve($site, []);

    expect($theme['primary_color'])->toBe('#15803d');
    expect($theme['accent_color'])->toBe('#ea580c');
});

test('applies visual palette primary when provided', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $profile = [
        'visual' => ['palette' => ['primary' => '#e63946', 'accent' => '#457b9d']],
    ];

    $theme = $this->resolver->resolve($site, $profile);

    expect($theme['primary_color'])->toBe('#e63946');
});

test('applies visual palette accent when sufficiently distinct from primary', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $profile = [
        'visual' => ['palette' => ['primary' => '#e63946', 'accent' => '#457b9d']],
    ];

    $theme = $this->resolver->resolve($site, $profile);

    // #457b9d is a blue-ish colour, distinct from #e63946 (red)
    expect($theme['accent_color'])->toBe('#457b9d');
});

test('falls back to theme accent when visual palette accent is near-white', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $profile = [
        'visual' => ['palette' => ['primary' => '#e63946', 'accent' => '#f0f0f0']],
    ];

    $theme = $this->resolver->resolve($site, $profile);

    // #f0f0f0 is near-white — should be rejected, keeping default accent
    expect($theme['accent_color'])->toBe('#f59e0b');
});

test('normaliseHex handles 3-char shorthand', function () {
    expect($this->resolver->normaliseHex('#abc'))->toBe('#aabbcc');
});

test('normaliseHex rejects invalid hex', function () {
    expect($this->resolver->normaliseHex('not-a-color'))->toBeNull();
    expect($this->resolver->normaliseHex('#gggggg'))->toBeNull();
});

test('hexToHsl converts correctly for pure red', function () {
    $hsl = $this->resolver->hexToHsl('#ff0000');

    expect($hsl['h'])->toEqual(0.0);
    expect((float) $hsl['s'])->toEqual(1.0);
    expect($hsl['l'])->toEqual(0.5);
});

test('availableThemes returns all three preset handles', function () {
    expect(ThemeResolver::availableThemes())->toContain('trades-bold')
        ->toContain('professional-clean')
        ->toContain('local-friendly');
});

test('isDarkSurface matches renderTokens text_on_primary light-ink decision', function (string $hex) {
    $tokens = $this->resolver->renderTokens(['primary_color' => $hex]);
    $textOnPrimaryIsLight = $this->resolver->relativeLuminance($tokens['text_on_primary']) > 0.5;

    expect($this->resolver->isDarkSurface($hex))->toBe($textOnPrimaryIsLight);
})->with([
    '#0077ff' => ['#0077ff'],
    '#0088aa' => ['#0088aa'],
    '#558844' => ['#558844'],
    '#15803d' => ['#15803d'],
    '#1e40af' => ['#1e40af'],
    '#f97316' => ['#f97316'],
    '#ffffff' => ['#ffffff'],
    '#0f172a' => ['#0f172a'],
]);

test('renderTokens polarity keys stay byte-identical for stock palettes', function (string $source, string $key, array $expected) {
    $theme = $source === 'theme'
        ? $this->resolver->baseTheme($key)
        : themeResolverFw2FixtureTheme($key);
    $tokens = $this->resolver->renderTokens($theme);

    expect([
        'primary_text' => $tokens['primary_text'],
        'text_on_primary' => $tokens['text_on_primary'],
        'text_on_band' => $tokens['text_on_band'],
    ])->toBe($expected);
})->with(themeResolverFw2PolarityPins());

/**
 * renderTokens polarity keys captured before the prefersLightInk extract.
 *
 * @return array<string, array{0: string, 1: string, 2: array{primary_text: string, text_on_primary: string, text_on_band: string}}>
 */
function themeResolverFw2PolarityPins(): array
{
    return [
        'theme trades-bold' => ['theme', 'trades-bold', [
            'primary_text' => '#1e40af',
            'text_on_primary' => '#ffffff',
            'text_on_band' => '#ffffff',
        ]],
        'theme professional-clean' => ['theme', 'professional-clean', [
            'primary_text' => '#1f2937',
            'text_on_primary' => '#ffffff',
            'text_on_band' => '#ffffff',
        ]],
        'theme local-friendly' => ['theme', 'local-friendly', [
            'primary_text' => '#15803d',
            'text_on_primary' => '#ffffff',
            'text_on_band' => '#ffffff',
        ]],
        'fixture 51-eden' => ['fixture', '51-eden', [
            'primary_text' => '#1a1a1c',
            'text_on_primary' => '#ffffff',
            'text_on_band' => '#ffffff',
        ]],
        'fixture 52-hunt' => ['fixture', '52-hunt', [
            'primary_text' => '#2e4429',
            'text_on_primary' => '#ffffff',
            'text_on_band' => '#ffffff',
        ]],
        'fixture 54-nh' => ['fixture', '54-nh', [
            'primary_text' => '#707987',
            'text_on_primary' => '#ffffff',
            'text_on_band' => '#ffffff',
        ]],
        'fixture light-archetype' => ['fixture', 'light-archetype', [
            'primary_text' => '#1e40af',
            'text_on_primary' => '#ffffff',
            'text_on_band' => '#ffffff',
        ]],
    ];
}

/**
 * @return array<string, mixed>
 */
function themeResolverFw2FixtureTheme(string $key): array
{
    $decoded = json_decode((string) file_get_contents(base_path('tests/fixtures/home-themes/demo-site-themes.json')), true);
    expect($decoded[$key] ?? null)->toBeArray();

    return $decoded[$key];
}
