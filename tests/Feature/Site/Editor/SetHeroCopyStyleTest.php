<?php

use App\Services\Site\CompositionService;
use App\Services\Site\Editor\OperationRegistry;
use App\Models\Site\SiteDraft;
use Tests\Support\EditorSeeds;

beforeEach(function () {
    config(['editor.operations.enabled' => true, 'editor.agent_tools.enabled' => true]);
});

function heroCopyStyleRevision(\App\Models\Site $site): int
{
    app(CompositionService::class)->ensureDraftRow($site, $site->created_by_user_id);

    return (int) SiteDraft::query()->where('site_id', $site->id)->value('admin_revision');
}

it('advertises hero_copy_style as preset plain panel boxed', function () {
    $schema = OperationRegistry::discover()->get('set_hero_copy_style')->inputSchema();

    expect($schema['properties']['hero_copy_style']['enum'] ?? null)
        ->toBe(\App\Support\ChromeKnobs::HERO_COPY_STYLES);
});

it('writes a live hero_copy_style and stores preset as null', function () {
    [$user, $site] = EditorSeeds::homeWithHero();
    $revision = heroCopyStyleRevision($site);

    $boxed = EditorSeeds::run($user, $site, 'set_hero_copy_style', [
        'hero_copy_style' => 'boxed',
        'composition_revision' => $revision,
    ]);

    expect($boxed->ok)->toBeTrue()
        ->and($site->fresh()->hero_copy_style)->toBe('boxed');

    $preset = EditorSeeds::run($user, $site, 'set_hero_copy_style', [
        'hero_copy_style' => 'preset',
        'composition_revision' => $revision,
    ]);

    expect($preset->ok)->toBeTrue()
        ->and($site->fresh()->hero_copy_style)->toBeNull();
});

it('busts the public page cache when hero_copy_style changes', function () {
    config(['site.public_cache_enabled' => true]);
    [$user, $site] = EditorSeeds::homeWithHero();
    $revision = heroCopyStyleRevision($site);
    $counterKey = "site:{$site->id}:pubcache_counter";
    $before = (int) cache()->get($counterKey, 0);

    $result = EditorSeeds::run($user, $site, 'set_hero_copy_style', [
        'hero_copy_style' => 'boxed',
        'composition_revision' => $revision,
    ]);

    expect($result->ok)->toBeTrue()
        ->and((int) cache()->get($counterKey, 0))->toBeGreaterThan($before);
});

it('rejects a hero_copy_style outside the enum', function () {
    [$user, $site] = EditorSeeds::homeWithHero();
    $revision = heroCopyStyleRevision($site);

    $result = EditorSeeds::run($user, $site, 'set_hero_copy_style', [
        'hero_copy_style' => 'banner',
        'composition_revision' => $revision,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($site->fresh()->hero_copy_style)->toBeNull();
});
