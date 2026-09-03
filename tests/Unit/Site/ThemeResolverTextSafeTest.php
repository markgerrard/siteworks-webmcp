<?php

use App\Services\Site\ThemeResolver;

beforeEach(fn () => $this->resolver = app(ThemeResolver::class));

test('contrastRatio returns ~21 for pure black on pure white', function () {
    $ratio = $this->resolver->contrastRatio('#000000', '#ffffff');

    expect($ratio)->toBeGreaterThan(20.99)->toBeLessThan(21.01);
});

test('contrastRatio returns 1.0 when both colours match', function () {
    expect($this->resolver->contrastRatio('#ff7300', '#ff7300'))->toBe(1.0);
});

test('deriveTextSafeColor returns the brand unchanged when it already passes WCAG AA', function () {
    // Deep navy #1f3a5f on white — ratio ~9.5, way above 4.5.
    expect($this->resolver->deriveTextSafeColor('#1f3a5f', '#ffffff'))->toBe('#1f3a5f');
});

test('deriveTextSafeColor darkens a pale orange primary on a white surface until it passes', function () {
    $derived = $this->resolver->deriveTextSafeColor('#ff7300', '#ffffff');
    $ratio = $this->resolver->contrastRatio($derived, '#ffffff');

    expect($ratio)->toBeGreaterThanOrEqual(4.5);
    // The derived hex should still read as orange (hue preserved).
    expect($derived)->not->toBe('#ff7300');
});

test('deriveTextSafeColor preserves hue — same orange family before and after', function () {
    $derived = $this->resolver->deriveTextSafeColor('#ff7300', '#ffffff');
    $originalHsl = $this->resolver->hexToHsl('#ff7300');
    $derivedHsl = $this->resolver->hexToHsl($derived);

    // Hue should be within 5° (minor rounding drift allowed).
    expect(abs($originalHsl['h'] - $derivedHsl['h']))->toBeLessThan(5.0);
});

test('deriveTextSafeColor lightens a dark brand on a dark surface', function () {
    // Dark-mode scenario: surface near-black, brand midtone.
    $derived = $this->resolver->deriveTextSafeColor('#555555', '#111111');
    $ratio = $this->resolver->contrastRatio($derived, '#111111');

    expect($ratio)->toBeGreaterThanOrEqual(4.5);
});

test('renderTokens emits primary_text and accent_text keys', function () {
    $tokens = $this->resolver->renderTokens([
        'primary_color' => '#ff7300',
        'accent_color' => '#1f3a5f',
        'surface_color' => '#ffffff',
    ]);

    expect($tokens)->toHaveKey('primary_text');
    expect($tokens)->toHaveKey('accent_text');

    // primary is pale → primary_text should differ (darkened)
    expect($tokens['primary_text'])->not->toBe($tokens['primary']);
    // accent is already dark enough → accent_text stays equal
    expect($tokens['accent_text'])->toBe($tokens['accent']);
});

test('renderTokens primary_text passes 4.5:1 contrast vs surface even for pathological primary', function () {
    foreach (['#ff7300', '#ffeb3b', '#fef08a', '#f87171', '#ffffff'] as $primary) {
        $tokens = $this->resolver->renderTokens([
            'primary_color' => $primary,
            'surface_color' => '#ffffff',
        ]);
        $ratio = $this->resolver->contrastRatio($tokens['primary_text'], '#ffffff');
        expect($ratio)->toBeGreaterThanOrEqual(4.5);
    }
});
