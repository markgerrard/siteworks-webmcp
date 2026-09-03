<?php

use App\Models\Site\SiteDraft;
use App\Services\Site\CompositionService;
use App\Services\Site\Editor\OperationRegistry;
use App\Support\ChromeKnobs;
use Tests\Support\EditorSeeds;

beforeEach(function () {
    config(['editor.operations.enabled' => true, 'editor.agent_tools.enabled' => true]);
});

function navContainerRevision(\App\Models\Site $site): int
{
    app(CompositionService::class)->ensureDraftRow($site, $site->created_by_user_id);

    return (int) SiteDraft::query()->where('site_id', $site->id)->value('admin_revision');
}

it('advertises both nav container enums from ChromeKnobs in the registry and WebMCP artefact', function () {
    $schema = OperationRegistry::discover()->get('set_nav_container')->inputSchema();

    expect($schema['properties']['nav_container_style']['enum'] ?? null)->toBe(ChromeKnobs::NAV_CONTAINER_STYLES)
        ->and($schema['properties']['nav_container_fill']['enum'] ?? null)->toBe(ChromeKnobs::NAV_CONTAINER_FILLS);

    $artefact = json_decode(
        (string) file_get_contents(resource_path('js/site-editor/webmcp/schemas.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($artefact['operations']['set_nav_container']['inputSchema']['properties']['nav_container_style']['enum'] ?? null)
        ->toBe(ChromeKnobs::NAV_CONTAINER_STYLES)
        ->and($artefact['operations']['set_nav_container']['inputSchema']['properties']['nav_container_fill']['enum'] ?? null)
        ->toBe(ChromeKnobs::NAV_CONTAINER_FILLS);
});

it('writes both live nav container knobs including explicit none and surface and busts cache', function () {
    config(['site.public_cache_enabled' => true]);
    [$user, $site] = EditorSeeds::homeWithHero();
    $revision = navContainerRevision($site);
    $counterKey = "site:{$site->id}:pubcache_counter";
    $before = (int) cache()->get($counterKey, 0);

    $pill = EditorSeeds::run($user, $site, 'set_nav_container', [
        'nav_container_style' => 'pill',
        'nav_container_fill' => 'glass',
        'composition_revision' => $revision,
    ]);

    expect($pill->ok)->toBeTrue()
        ->and($site->fresh()->nav_container_style)->toBe('pill')
        ->and($site->fresh()->nav_container_fill)->toBe('glass')
        ->and((int) cache()->get($counterKey, 0))->toBeGreaterThan($before);

    $defaults = EditorSeeds::run($user, $site, 'set_nav_container', [
        'nav_container_style' => 'none',
        'nav_container_fill' => 'surface',
        'composition_revision' => $revision,
    ]);

    expect($defaults->ok)->toBeTrue()
        ->and($site->fresh()->nav_container_style)->toBe('none')
        ->and($site->fresh()->nav_container_fill)->toBe('surface');
});

it('rejects invalid nav container values atomically', function (string $style, string $fill) {
    [$user, $site] = EditorSeeds::homeWithHero();

    $result = EditorSeeds::run($user, $site, 'set_nav_container', [
        'nav_container_style' => $style,
        'nav_container_fill' => $fill,
        'composition_revision' => navContainerRevision($site),
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($site->fresh()->nav_container_style)->toBeNull()
        ->and($site->fresh()->nav_container_fill)->toBeNull();
})->with([
    'bad style' => ['rounded', 'surface'],
    'bad fill' => ['pill', 'white'],
]);
