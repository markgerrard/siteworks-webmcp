<?php

use App\Models\BusinessProfile;
use App\Models\Site;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use App\Services\Site\ThemeResolver;


test('layout context rejects the two-argument base theme resolution for an inverted composition theme', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $profile = ['archetype' => 'retail_venue'];
    BusinessProfile::factory()->for($site)->create(['profile_data' => $profile]);

    $compositionTheme = [
        'key' => 'trades-bold',
        'primary_override' => '#2a4d69',
        'accent_override' => '#c44536',
        'tertiary_override' => '#7b2cbf',
        'surface_override' => '#e9d8a6',
        'surface_alt_override' => '#94d2bd',
        'border_override' => '#577590',
        'text_override' => '#212529',
        'text_muted_override' => '#495057',
        'display_font_override' => 'bricolage-grotesque',
        'body_font_override' => 'nunito-sans',
        'heading_scale_override' => 'relaxed',
        'spacing_density_override' => 'generous',
        'corner_style_override' => 'rounded',
        'invert_mode_override' => true,
    ];
    $composition = [
        'nav' => ['items' => []],
        'theme' => $compositionTheme,
    ];

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => $composition,
        'page_revisions' => [],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);

    $resolver = app(ThemeResolver::class);
    $expectedTheme = $resolver->resolve($site, $profile, $compositionTheme);
    $wrongTwoArgumentTheme = $resolver->resolve($site, $profile);

    expect($expectedTheme)
        ->not->toBe($wrongTwoArgumentTheme)
        ->and($expectedTheme['surface_color'])->not->toBe($compositionTheme['surface_override'])
        ->and($expectedTheme['band_mode'])->toBe('light-tinted');

    $context = app(PageRenderer::class)->layoutContext($site);

    expect($context['theme'])->toBe($expectedTheme)
        ->and($context['renderTokens'])->toBe($resolver->renderTokens($expectedTheme));
});
