<?php

use App\Services\Site\ThemeResolver;

beforeEach(fn () => $this->resolver = app(ThemeResolver::class));

// ---------------------------------------------------------------------------
// Dark-surface theme
// ---------------------------------------------------------------------------

test('dark surface: band resolves to surface or deeper (never lighter than surface)', function () {
    // Midlands-style dark surface
    $tokens = $this->resolver->renderTokens([
        'primary_color' => '#1e40af',
        'accent_color'  => '#f59e0b',
        'surface_color' => '#0f172a',
    ]);

    $bandLuminance    = $this->resolver->relativeLuminance($tokens['band']);
    $surfaceLuminance = $this->resolver->relativeLuminance('#0f172a');

    // Band must be no lighter than the surface.
    expect($bandLuminance)->toBeLessThanOrEqual($surfaceLuminance + 0.001);
});

test('dark surface: band luminance stays at or below 0.15', function () {
    $tokens = $this->resolver->renderTokens([
        'primary_color' => '#1e40af',
        'surface_color' => '#1e293b', // slate-800 — dark but not pitch-black
    ]);

    expect($this->resolver->relativeLuminance($tokens['band']))->toBeLessThanOrEqual(0.15);
});

// ---------------------------------------------------------------------------
// Light-surface theme
// ---------------------------------------------------------------------------

test('light surface: band resolves to a dark primary-tinted colour (luminance ≤ 0.15)', function () {
    $tokens = $this->resolver->renderTokens([
        'primary_color' => '#15803d', // green
        'surface_color' => '#ffffff',
    ]);

    $bandLuminance = $this->resolver->relativeLuminance($tokens['band']);
    expect($bandLuminance)->toBeLessThanOrEqual(0.15);
});

test('light surface: band is different from the plain surface', function () {
    $tokens = $this->resolver->renderTokens([
        'primary_color' => '#15803d',
        'surface_color' => '#ffffff',
    ]);

    expect($tokens['band'])->not->toBe('#ffffff');
});

test('light surface with desaturated primary: band still meets luminance target', function () {
    // A grey-leaning primary — saturation clamped floor should kick in.
    $tokens = $this->resolver->renderTokens([
        'primary_color' => '#6b7280', // gray-500
        'surface_color' => '#f9fafb',
    ]);

    expect($this->resolver->relativeLuminance($tokens['band']))->toBeLessThanOrEqual(0.15);
});

// ---------------------------------------------------------------------------
// text_on_band — always WCAG AA against the band
// ---------------------------------------------------------------------------

test('text_on_band passes WCAG AA (4.5:1) against band for dark-surface theme', function () {
    $tokens = $this->resolver->renderTokens([
        'primary_color' => '#1e40af',
        'surface_color' => '#0f172a',
    ]);

    $ratio = $this->resolver->contrastRatio($tokens['text_on_band'], $tokens['band']);
    expect($ratio)->toBeGreaterThanOrEqual(4.5);
});

test('text_on_band passes WCAG AA (4.5:1) against band for light-surface theme', function () {
    $tokens = $this->resolver->renderTokens([
        'primary_color' => '#15803d',
        'surface_color' => '#ffffff',
    ]);

    $ratio = $this->resolver->contrastRatio($tokens['text_on_band'], $tokens['band']);
    expect($ratio)->toBeGreaterThanOrEqual(4.5);
});

test('text_on_band passes WCAG AA across a range of primaries on white', function () {
    $primaries = ['#1e40af', '#15803d', '#dc2626', '#7c3aed', '#b45309', '#0891b2'];

    foreach ($primaries as $primary) {
        $tokens = $this->resolver->renderTokens([
            'primary_color' => $primary,
            'surface_color' => '#ffffff',
        ]);
        $ratio = $this->resolver->contrastRatio($tokens['text_on_band'], $tokens['band']);
        expect($ratio)->toBeGreaterThanOrEqual(4.5, "Failed for primary {$primary}");
    }
});

// ---------------------------------------------------------------------------
// band_overlay equals band
// ---------------------------------------------------------------------------

test('band_overlay equals band', function () {
    foreach (['#0f172a', '#ffffff', '#f0f4f8'] as $surface) {
        $tokens = $this->resolver->renderTokens([
            'primary_color' => '#1e40af',
            'surface_color' => $surface,
        ]);
        expect($tokens['band_overlay'])->toBe($tokens['band']);
    }
});

// ---------------------------------------------------------------------------
// renderTokens emits all three keys
// ---------------------------------------------------------------------------

test('renderTokens emits band, text_on_band, and band_overlay keys', function () {
    $tokens = $this->resolver->renderTokens([
        'primary_color' => '#1e40af',
        'surface_color' => '#ffffff',
    ]);

    expect($tokens)->toHaveKey('band');
    expect($tokens)->toHaveKey('text_on_band');
    expect($tokens)->toHaveKey('band_overlay');
});

// ---------------------------------------------------------------------------
// light-tinted band_mode — boutique / wellness archetypes
// ---------------------------------------------------------------------------

test('light-tinted band: band luminance is above 0.85 (airy, not dark)', function () {
    // Florist rose palette — primary #e11d48 (rose-600), surface cream #fdf6f0
    $tokens = $this->resolver->renderTokens([
        'primary_color' => '#e11d48',
        'surface_color' => '#fdf6f0',
        'band_mode'     => 'light-tinted',
    ]);

    expect($this->resolver->relativeLuminance($tokens['band']))->toBeGreaterThan(0.85);
});

test('light-tinted band: text_on_band luminance is below 0.2 (dark text)', function () {
    $tokens = $this->resolver->renderTokens([
        'primary_color' => '#e11d48',
        'surface_color' => '#fdf6f0',
        'band_mode'     => 'light-tinted',
    ]);

    expect($this->resolver->relativeLuminance($tokens['text_on_band']))->toBeLessThan(0.2);
});

test('light-tinted band: text_on_band passes WCAG AA (4.5:1) against band', function () {
    $tokens = $this->resolver->renderTokens([
        'primary_color' => '#e11d48',
        'surface_color' => '#fdf6f0',
        'band_mode'     => 'light-tinted',
    ]);

    $ratio = $this->resolver->contrastRatio($tokens['text_on_band'], $tokens['band']);
    expect($ratio)->toBeGreaterThanOrEqual(4.5);
});

test('light-tinted band: band is distinct from plain surface (not near-white noise)', function () {
    $tokens = $this->resolver->renderTokens([
        'primary_color' => '#e11d48',
        'surface_color' => '#ffffff',
        'band_mode'     => 'light-tinted',
    ]);

    // Band must differ from pure white by at least a perceptible luminance step.
    $bandLuminance = $this->resolver->relativeLuminance($tokens['band']);
    expect($bandLuminance)->toBeLessThan(0.97);
});

test('light-tinted band: band_overlay equals band', function () {
    $tokens = $this->resolver->renderTokens([
        'primary_color' => '#e11d48',
        'surface_color' => '#fdf6f0',
        'band_mode'     => 'light-tinted',
    ]);

    expect($tokens['band_overlay'])->toBe($tokens['band']);
});

test('light-tinted band: renderTokens emits band_mode token', function () {
    $tokens = $this->resolver->renderTokens([
        'primary_color' => '#e11d48',
        'surface_color' => '#fdf6f0',
        'band_mode'     => 'light-tinted',
    ]);

    expect($tokens)->toHaveKey('band_mode');
    expect($tokens['band_mode'])->toBe('light-tinted');
});

test('dark band_mode (default) still produces luminance <= 0.15 — Midlands unchanged', function () {
    // Original Midlands-style test reproduced explicitly against the new signature.
    $tokens = $this->resolver->renderTokens([
        'primary_color' => '#1e40af',
        'accent_color'  => '#f59e0b',
        'surface_color' => '#0f172a',
        'band_mode'     => 'dark',
    ]);

    expect($this->resolver->relativeLuminance($tokens['band']))->toBeLessThanOrEqual(0.15);
    expect($tokens['band_mode'])->toBe('dark');
});

test('light-tinted band passes WCAG AA across rose and lavender primaries', function () {
    $primaries = ['#e11d48', '#7c3aed', '#db2777', '#0891b2'];

    foreach ($primaries as $primary) {
        $tokens = $this->resolver->renderTokens([
            'primary_color' => $primary,
            'surface_color' => '#ffffff',
            'band_mode'     => 'light-tinted',
        ]);
        $ratio = $this->resolver->contrastRatio($tokens['text_on_band'], $tokens['band']);
        expect($ratio)->toBeGreaterThanOrEqual(4.5, "Failed contrast for primary {$primary}");
        expect($this->resolver->relativeLuminance($tokens['band']))->toBeGreaterThan(0.85, "Failed luminance for primary {$primary}");
    }
});

test('soft brand section tokens use an accent tint for black primary and AA-safe ink', function () {
    $tokens = $this->resolver->renderTokens([
        'primary_color' => '#111111',
        'accent_color' => '#f5b800',
        'surface_color' => '#ffffff',
        'surface_alt_color' => '#f5f4f0',
        'text_color' => '#111111',
        'text_muted_color' => '#5f6368',
        'brand_section_scheme' => 'soft',
    ]);

    expect($tokens['brand_section_scheme'])->toBe('soft')
        ->and($this->resolver->relativeLuminance($tokens['brand_section_surface']))->toBeGreaterThan(0.80)
        ->and($tokens['brand_section_surface'])->not->toBe('#ffffff')
        ->and($tokens['accent'])->toBe('#f5b800')
        ->and($this->resolver->contrastRatio($tokens['brand_section_ink'], $tokens['brand_section_surface']))->toBeGreaterThanOrEqual(4.5)
        ->and($this->resolver->contrastRatio($tokens['brand_section_muted_ink'], $tokens['brand_section_surface']))->toBeGreaterThanOrEqual(4.5)
        ->and($this->resolver->contrastRatio($tokens['brand_section_accent_ink'], $tokens['brand_section_surface']))->toBeGreaterThanOrEqual(4.5);
});

// ---------------------------------------------------------------------------
// Achromatic-primary band derivation
// ---------------------------------------------------------------------------
//
// Bug: deriveBandColor used max(0.40, primaryHsl['s']) on the light-surface
// dark-mode path, which forces saturation up. For an achromatic primary
// (#000000 / pure grey), hexToHsl returns h=0 as a placeholder — the
// saturation clamp then paints a dark red at L=0.12 (#2b1212), visually a
// brown. A tenant with primary=#000000 rendered with a dark red-brown CTA
// band on every page.
//
// Fix: when primary is achromatic, fall back to the accent's hue (if it's
// chromatic) or to deepSlate, instead of inventing a hue from h=0.

test('regression: black primary + chromatic accent → band uses accent hue, never invented red-brown', function () {
    // Exactly the BBT case — primary=black, accent=Facebook-blue, white surface.
    $tokens = $this->resolver->renderTokens([
        'primary_color' => '#000000',
        'accent_color'  => '#0866ff',
        'surface_color' => '#ffffff',
    ]);

    // Sanity: should be a dark colour (luminance ≤ 0.15).
    expect($this->resolver->relativeLuminance($tokens['band']))->toBeLessThanOrEqual(0.15);

    // The bad value was exactly #2b1212 — guard against any future
    // refactor that re-introduces the same dark-red.
    expect(strtolower($tokens['band']))->not->toBe('#2b1212');

    // Hue should derive from the chromatic accent (#0866ff, hue ≈ 216°).
    // Convert the resolved band back to HSL and check the hue is in the
    // blue half of the wheel (180-260°), not in the red zone (0±30°).
    $rgb = [
        hexdec(substr($tokens['band'], 1, 2)),
        hexdec(substr($tokens['band'], 3, 2)),
        hexdec(substr($tokens['band'], 5, 2)),
    ];
    [$r, $g, $b] = array_map(fn ($v) => $v / 255, $rgb);
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $delta = $max - $min;
    if ($delta === 0.0) {
        $hue = 0.0;
    } elseif ($max === $r) {
        $hue = 60 * fmod((($g - $b) / $delta), 6);
    } elseif ($max === $g) {
        $hue = 60 * ((($b - $r) / $delta) + 2);
    } else {
        $hue = 60 * ((($r - $g) / $delta) + 4);
    }
    if ($hue < 0) {
        $hue += 360;
    }
    expect($hue)->toBeGreaterThan(180.0)->toBeLessThan(260.0);
});

test('regression: achromatic primary AND achromatic accent → band falls back to deepSlate, no invented hue', function () {
    $tokens = $this->resolver->renderTokens([
        'primary_color' => '#000000',
        'accent_color'  => '#404040', // also achromatic
        'surface_color' => '#ffffff',
    ]);

    // No hue invented — should be the deepSlate fallback exactly.
    expect(strtolower($tokens['band']))->toBe('#0f172a');
});
