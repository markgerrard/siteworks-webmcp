<?php

use App\Models\Site;
use App\Services\Site\ThemeResolver;


beforeEach(fn () => $this->resolver = app(ThemeResolver::class));

test('composition preset key alone (no overrides) does NOT pre-empt palette extraction', function () {
    // Previously a stored preset key was treated as admin intent and
    // short-circuited the extraction chain. That regressed every site
    // whose composition was seeded with CompositionDefaults (key =
    // trades-bold, null overrides) — the blue/gold preset base
    // overrode the real brand palette. Real admin intent from the
    // theme-picker always writes both overrides, so key-alone is
    // never a "picked" signal.
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $composition = ['key' => 'professional-clean', 'primary_override' => null, 'accent_override' => null];
    $profile = ['visual' => ['palette' => ['primary' => '#e63946', 'accent' => '#457b9d']]];

    $theme = $this->resolver->resolve($site, $profile, $composition);

    expect($theme['primary_color'])->toBe('#e63946');
    expect($theme['accent_color'])->toBe('#457b9d');
});

test('composition overrides trump the preset', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $composition = [
        'key' => 'trades-bold',
        'primary_override' => '#ff00ff',
        'accent_override' => '#00ff00',
    ];

    $theme = $this->resolver->resolve($site, [], $composition);

    expect($theme['primary_color'])->toBe('#ff00ff');
    expect($theme['accent_color'])->toBe('#00ff00');
});

test('composition theme beats the visual palette extraction', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $profile = [
        'visual' => ['palette' => ['primary' => '#e63946', 'accent' => '#457b9d']],
    ];
    $composition = [
        'key' => 'trades-bold',
        'primary_override' => '#123456',
        'accent_override' => null,
    ];

    $theme = $this->resolver->resolve($site, $profile, $composition);

    expect($theme['primary_color'])->toBe('#123456');
    // accent_override null → visual palette remains in place.
    expect($theme['accent_color'])->toBe('#457b9d');
});

test('null composition delegates to existing palette-extraction priority', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $profile = [
        'visual' => ['palette' => ['primary' => '#e63946', 'accent' => '#457b9d']],
    ];

    $theme = $this->resolver->resolve($site, $profile, null);

    // Visual palette wins without a composition override — matches
    // pre-existing behaviour.
    expect($theme['primary_color'])->toBe('#e63946');
    expect($theme['accent_color'])->toBe('#457b9d');
});

test('empty composition array falls through to palette extraction', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $profile = ['visual' => ['palette' => ['primary' => '#e63946', 'accent' => '#457b9d']]];

    $theme = $this->resolver->resolve($site, $profile, []);

    expect($theme['primary_color'])->toBe('#e63946');
});

test('composition with invalid preset key + valid override still applies the override', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $composition = [
        'key' => 'not-a-real-preset',
        'primary_override' => '#abcdef',
        'accent_override' => null,
    ];

    $theme = $this->resolver->resolve($site, [], $composition);

    // Base falls back to trades-bold for the accent; primary is the override.
    expect($theme['primary_color'])->toBe('#abcdef');
    expect($theme['accent_color'])->toBe('#f59e0b');
});

test('composition with invalid preset key and no overrides falls through to extraction', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $profile = ['visual' => ['palette' => ['primary' => '#e63946', 'accent' => '#457b9d']]];
    $composition = ['key' => 'not-a-real-preset', 'primary_override' => null, 'accent_override' => null];

    $theme = $this->resolver->resolve($site, $profile, $composition);

    // No actionable composition signal → visual palette wins.
    expect($theme['primary_color'])->toBe('#e63946');
});

test('short-form hex in override is normalised', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $composition = [
        'key' => 'trades-bold',
        'primary_override' => '#abc',
        'accent_override' => null,
    ];

    $theme = $this->resolver->resolve($site, [], $composition);

    // #abc → expanded to #aabbcc
    expect($theme['primary_color'])->toBe('#aabbcc');
});

test('legacy two-argument resolve call still works (PreviewRenderer contract)', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $theme = $this->resolver->resolve($site, []); // no third arg — legacy call

    expect($theme['primary_color'])->toBe('#1e40af');
    expect($theme['accent_color'])->toBe('#f59e0b');
});
