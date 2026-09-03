<?php

use App\Services\Site\CompositionService;
use App\Services\Site\Editor\OperationRegistry;
use App\Models\Site\SiteDraft;
use Tests\Support\EditorSeeds;
use Tests\Support\FulfilmentFixtures;

beforeEach(function () {
    config(['editor.operations.enabled' => true, 'editor.agent_tools.enabled' => true]);
});

function fulfilmentOpRevision(\App\Models\Site $site): int
{
    app(CompositionService::class)->ensureDraftRow($site, $site->created_by_user_id);

    return (int) SiteDraft::query()->where('site_id', $site->id)->value('admin_revision');
}

it('advertises a fulfilment object on set_fulfilment', function () {
    $schema = OperationRegistry::discover()->get('set_fulfilment')->inputSchema();

    expect($schema['required'])->toContain('fulfilment')
        ->and($schema['properties']['fulfilment']['properties'])->toHaveKeys(['delivery', 'collect', 'shipping', 'widget']);
});

it('writes live sites.fulfilment and busts the public page cache', function () {
    config(['site.public_cache_enabled' => true]);
    [$user, $site] = EditorSeeds::homeWithHero();
    $revision = fulfilmentOpRevision($site);
    $counterKey = "site:{$site->id}:pubcache_counter";
    $before = (int) cache()->get($counterKey, 0);

    $result = EditorSeeds::run($user, $site, 'set_fulfilment', [
        'fulfilment' => FulfilmentFixtures::camino(),
        'composition_revision' => $revision,
    ]);

    expect($result->ok)->toBeTrue()
        ->and($site->fresh()->fulfilment['delivery']['zones'][0]['prefixes'])->toBe(['SW1A', 'SW1'])
        ->and((int) cache()->get($counterKey, 0))->toBeGreaterThan($before);
});

it('rejects duplicate prefixes', function () {
    [$user, $site] = EditorSeeds::homeWithHero();
    $revision = fulfilmentOpRevision($site);

    $result = EditorSeeds::run($user, $site, 'set_fulfilment', [
        'fulfilment' => [
            'delivery' => [
                'enabled' => true,
                'zones' => [
                    ['name' => 'A', 'prefixes' => ['SW1'], 'fee_cents' => 0],
                    ['name' => 'B', 'prefixes' => ['SW1'], 'fee_cents' => 0],
                ],
            ],
        ],
        'composition_revision' => $revision,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($site->fresh()->fulfilment)->toBeNull();
});

it('rejects a zone name longer than 80 characters', function () {
    [$user, $site] = EditorSeeds::homeWithHero();
    $revision = fulfilmentOpRevision($site);

    $result = EditorSeeds::run($user, $site, 'set_fulfilment', [
        'fulfilment' => [
            'delivery' => [
                'enabled' => true,
                'zones' => [
                    ['name' => str_repeat('Z', 120), 'prefixes' => ['SW1'], 'fee_cents' => 0],
                ],
            ],
        ],
        'composition_revision' => $revision,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($result->error['fields']['delivery.zones.0.name'] ?? null)->not->toBeNull()
        ->and($site->fresh()->fulfilment)->toBeNull();
});

it('saves an accented zone name within the schema character limit', function () {
    [$user, $site] = EditorSeeds::homeWithHero();
    $revision = fulfilmentOpRevision($site);
    $name = str_repeat('é', 45);
    $fulfilment = FulfilmentFixtures::camino();
    $fulfilment['delivery']['zones'][0]['name'] = $name;

    $result = EditorSeeds::run($user, $site, 'set_fulfilment', [
        'fulfilment' => $fulfilment,
        'composition_revision' => $revision,
    ]);

    expect(mb_strlen($name))->toBe(45)
        ->and($result->ok)->toBeTrue()
        ->and($site->fresh()->fulfilment['delivery']['zones'][0]['name'])->toBe($name);
});
