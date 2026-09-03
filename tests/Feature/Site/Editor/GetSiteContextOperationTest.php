<?php

use App\Enums\AgentRole;
use App\Models\Client;
use App\Models\Shop\Category;
use App\Models\Shop\ShopDraft;
use App\Models\Site;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\AgentToolsGate;
use App\Services\Site\Editor\CommerceOperations;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\OperationRegistry;
use App\Services\Site\Editor\Operations\GetSiteContextOperation;
use App\Services\Site\Editor\Shop\ShopEntityResolver;
use App\Services\Site\Editor\ToolExposure;
use Tests\Support\CommerceReads;

beforeEach(function () {
    CommerceReads::enableFlags();
});

/**
 * @return array{0: User, 1: Site}
 */
function siteContextStaffSite(array $siteAttrs = []): array
{
    $actor = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(array_merge([
        'created_by_user_id' => $actor->id,
        'business_name' => 'Acme Roofing',
        'slug' => 'acme-roofing',
        'site_type' => 'trades',
        'region' => 'midlands',
    ], $siteAttrs));

    return [$actor, $site];
}

/**
 * @return array{0: User, 1: Site}
 */
function siteContextClientSite(): array
{
    $tenant = Client::factory()->create();
    $actor = User::factory()->create(['client_id' => $tenant->id, 'role' => null]);
    $site = Site::factory()->create([
        'client_id' => $tenant->id,
        'business_name' => 'Client Bakery',
        'slug' => 'client-bakery',
    ]);

    return [$actor, $site];
}

function openSiteContextClientChannel(): void
{
    config([
        'editor.agent_tools.roles' => ['staff', 'client'],
        'editor.agent_tools.client_portal_enabled' => true,
    ]);
}

/**
 * @return list<string>
 */
function expectedSiteContextCapabilities(User $actor, Site $site, ActorChannel $channel): array
{
    $registry = app(OperationRegistry::class);
    $gate = app(AgentToolsGate::class);

    return array_values(array_filter(
        app(ToolExposure::class)->setFor($site),
        function (string $name) use ($registry, $gate, $actor, $channel): bool {
            if (! $registry->has($name)) {
                return false;
            }

            return $gate->enabledForUserAndOperation($actor, $channel, $registry->get($name));
        },
    ));
}

it('is read-only, site-addressed, and staff+client by allowedRoles', function () {
    $operation = app(GetSiteContextOperation::class);

    expect($operation->readOnly())->toBeTrue()
        ->and($operation->address())->toBe('site')
        ->and($operation->allowedRoles())->toBe(['staff', 'client'])
        ->and($operation->wrapInAdminChange())->toBeFalse();
});

it('names get_site_context on the sandbox exposure set and the client SANDBOX allowlist', function () {
    expect(config('editor.exposure.sets.sandbox'))->toContain('get_site_context')
        ->and(CommerceOperations::SANDBOX)->toContain('get_site_context');
});

it('returns identity plus the ToolExposure set the staff caller actually has', function () {
    [$actor, $site] = siteContextStaffSite();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'get_site_context',
        [],
    );

    expect($result->ok)->toBeTrue()
        ->and($result->data['site_id'])->toBe($site->id)
        ->and($result->data['business_name'])->toBe('Acme Roofing')
        ->and($result->data['slug'])->toBe('acme-roofing')
        ->and($result->data['site_type'])->toBe('trades')
        ->and($result->data['region'])->toBe('midlands')
        ->and($result->data['shop_enabled'])->toBeTrue()
        ->and($result->data['has_shop'])->toBeFalse()
        ->and($result->data)->not->toHaveKey('catalogue_revision')
        ->and($result->data['capabilities'])->toBe(expectedSiteContextCapabilities($actor, $site, ActorChannel::Webmcp))
        ->and($result->data['capabilities'])->toContain('get_site_context');
});

it('omits site_type and region when they are empty', function () {
    [$actor, $site] = siteContextStaffSite(['site_type' => null, 'region' => '']);

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'get_site_context',
        [],
    );

    expect($result->ok)->toBeTrue()
        ->and($result->data)->not->toHaveKey('site_type')
        ->and($result->data)->not->toHaveKey('region');
});

it('includes catalogue_revision when the site has a shop', function () {
    [$actor, $site] = siteContextStaffSite();
    Category::factory()->for($site)->create();
    CommerceReads::giveShop($site);
    ShopDraft::query()->updateOrCreate(
        ['site_id' => $site->id],
        ['catalogue_revision' => 7],
    );

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'get_site_context',
        [],
    );

    expect($result->ok)->toBeTrue()
        ->and($result->data['shop_enabled'])->toBeTrue()
        ->and($result->data['has_shop'])->toBe(app(ShopEntityResolver::class)->hasShop($site))
        ->and($result->data['catalogue_revision'])->toBe(7);
});

it('lets a client run get_site_context on their own site over Webmcp', function () {
    openSiteContextClientChannel();
    [$actor, $site] = siteContextClientSite();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'get_site_context',
        [],
    );

    expect($result->ok)->toBeTrue()
        ->and($result->data['business_name'])->toBe('Client Bakery')
        ->and($result->data['capabilities'])->toBe(expectedSiteContextCapabilities($actor, $site, ActorChannel::Webmcp))
        ->and($result->data['capabilities'])->toContain('get_site_context')
        ->and($result->data['capabilities'])->not->toContain('generate_image');
});

it('refuses a client running get_site_context against another tenant site', function () {
    openSiteContextClientChannel();
    [$actor] = siteContextClientSite();
    $other = Site::factory()->create(['client_id' => Client::factory()->create()->id]);

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $other, ActorChannel::Webmcp),
        'get_site_context',
        [],
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('forbidden');
});

it('lets a staff agent call get_site_context over Webmcp once exposed', function () {
    [$actor, $site] = siteContextStaffSite();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'get_site_context',
        [],
    );

    expect($result->ok)->toBeTrue()
        ->and($result->data)->toHaveKey('capabilities');
});

it('refuses a client when the portal flag is off even with the role allowlist open', function () {
    config(['editor.agent_tools.roles' => ['staff', 'client']]);
    [$actor, $site] = siteContextClientSite();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'get_site_context',
        [],
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('forbidden')
        ->and($result->error['message'])->toBe('Agent tools are disabled for this actor.');
});
