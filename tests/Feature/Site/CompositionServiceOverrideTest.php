<?php

use App\Enums\MutationSource;
use App\Models\Site;
use App\Models\Site\SiteDraft;
use App\Services\Site\CompositionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('updateThemeOverrides merges partial overrides into composition.theme', function () {
    $site = Site::factory()->create();
    $cs = app(CompositionService::class);
    $draft = $cs->getOrCreateDraft($site);

    // Seed an existing state
    $cs->updateTheme($draft, 'trades-bold', '#ff0000', '#00ff00', MutationSource::Admin);
    $draft->refresh();

    // Merge a new override for tertiary — primary + accent should stay
    $cs->updateThemeOverrides($draft->fresh(), [
        'tertiary_override' => '#123456',
    ], MutationSource::Admin);

    $theme = SiteDraft::where('site_id', $site->id)->first()->composition['theme'];
    expect($theme['primary_override'])->toBe('#ff0000');
    expect($theme['accent_override'])->toBe('#00ff00');
    expect($theme['tertiary_override'])->toBe('#123456');
    expect($theme['key'])->toBe('trades-bold');
});

test('updateThemeOverrides removes a key when value is null or empty', function () {
    $site = Site::factory()->create();
    $cs = app(CompositionService::class);
    $draft = $cs->getOrCreateDraft($site);
    $cs->updateTheme($draft, 'trades-bold', '#ff0000', '#00ff00', MutationSource::Admin);

    // Nullify accent
    $cs->updateThemeOverrides($draft->fresh(), [
        'accent_override' => null,
    ], MutationSource::Admin);

    $theme = SiteDraft::where('site_id', $site->id)->first()->composition['theme'];
    expect($theme)->not->toHaveKey('accent_override');
    expect($theme['primary_override'])->toBe('#ff0000');
});

test('updateThemeOverrides bumps admin_revision when source is Admin', function () {
    $site = Site::factory()->create();
    $cs = app(CompositionService::class);
    $draft = $cs->getOrCreateDraft($site);
    $before = (int) $draft->admin_revision;

    $cs->updateThemeOverrides($draft, [
        'tertiary_override' => '#abcdef',
    ], MutationSource::Admin);

    $after = (int) SiteDraft::where('site_id', $site->id)->value('admin_revision');
    expect($after)->toBe($before + 1);
});

test('clearAllThemeOverrides wipes every *_override key but preserves preset key', function () {
    $site = Site::factory()->create();
    $cs = app(CompositionService::class);
    $draft = $cs->getOrCreateDraft($site);
    $cs->updateTheme($draft, 'professional-clean', '#ff0000', '#00ff00', MutationSource::Admin);
    $cs->updateThemeOverrides($draft->fresh(), [
        'tertiary_override' => '#123456',
        'display_font_override' => 'space-grotesk',
        'spacing_density_override' => 'generous',
    ], MutationSource::Admin);

    $cs->clearAllThemeOverrides($site, MutationSource::Admin);

    $theme = SiteDraft::where('site_id', $site->id)->first()->composition['theme'];
    expect($theme['key'])->toBe('professional-clean'); // preset key preserved
    expect($theme)->not->toHaveKey('primary_override');
    expect($theme)->not->toHaveKey('accent_override');
    expect($theme)->not->toHaveKey('tertiary_override');
    expect($theme)->not->toHaveKey('display_font_override');
    expect($theme)->not->toHaveKey('spacing_density_override');
});

test('clearAllThemeOverrides is a no-op when no draft exists', function () {
    $site = Site::factory()->create();

    app(CompositionService::class)->clearAllThemeOverrides($site, MutationSource::Admin);

    // No exception, no draft created
    expect(SiteDraft::where('site_id', $site->id)->exists())->toBeFalse();
});

test('legacy updateTheme call preserves extended overrides set by updateThemeOverrides', function () {
    $site = Site::factory()->create();
    $cs = app(CompositionService::class);
    $draft = $cs->getOrCreateDraft($site);

    // Admin sets a tertiary override via the new path
    $cs->updateThemeOverrides($draft, [
        'tertiary_override' => '#aabbcc',
        'display_font_override' => 'space-grotesk',
    ], MutationSource::Admin);

    // Later, the theme-picker's legacy updateTheme path changes the preset
    // key + primary/accent. Tertiary + font overrides must survive.
    $cs->updateTheme($draft->fresh(), 'professional-clean', '#112233', '#445566', MutationSource::Admin);

    $theme = SiteDraft::where('site_id', $site->id)->first()->composition['theme'];
    expect($theme['key'])->toBe('professional-clean');
    expect($theme['primary_override'])->toBe('#112233');
    expect($theme['accent_override'])->toBe('#445566');
    expect($theme['tertiary_override'])->toBe('#aabbcc');
    expect($theme['display_font_override'])->toBe('space-grotesk');
});
