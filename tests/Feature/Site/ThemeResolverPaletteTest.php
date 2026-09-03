<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Site;
use App\Services\Site\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('falls back to fingerprint primary_hex_guess when no scraped colours', function () {
    $site = Site::factory()->for(Client::factory())->create(['theme' => 'trades-bold']);
    BusinessProfile::factory()->for($site)->create([
        'layout_fingerprint' => [
            'palette' => [
                'primary_hex_guess' => '#abcdef',
                'dark_sections_used' => false,
            ],
        ],
    ]);

    $theme = app(ThemeResolver::class)->resolve($site, []);

    expect($theme['primary_color'])->toBe('#abcdef');
});

test('explicit profile visual palette wins over fingerprint guess', function () {
    $site = Site::factory()->for(Client::factory())->create(['theme' => 'trades-bold']);
    BusinessProfile::factory()->for($site)->create([
        'layout_fingerprint' => [
            'palette' => ['primary_hex_guess' => '#ff0000'],
        ],
    ]);

    $profile = [
        'visual' => ['palette' => ['primary' => '#123456', 'accent' => '#654321']],
    ];

    $theme = app(ThemeResolver::class)->resolve($site, $profile);

    expect($theme['primary_color'])->toBe('#123456');
});

test('default theme returned when fingerprint also null', function () {
    $site = Site::factory()->for(Client::factory())->create(['theme' => 'trades-bold']);
    BusinessProfile::factory()->for($site)->create(['layout_fingerprint' => null]);

    $theme = app(ThemeResolver::class)->resolve($site, []);

    expect($theme['primary_color'])->toBe('#1e40af');
});
