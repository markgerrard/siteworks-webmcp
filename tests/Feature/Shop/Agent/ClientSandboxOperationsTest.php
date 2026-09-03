<?php

use App\Models\Client;
use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Site;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\AgentToolsGate;
use App\Services\Site\Editor\CommerceOperations;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\OperationRegistry;
use Database\Seeders\Shop\TaxClassSeeder;
use Tests\Support\CommerceReads;

beforeEach(function () {
    CommerceReads::enableFlags();
    $this->seed(TaxClassSeeder::class);
});

/**
 * @return array{0: User, 1: Site}
 */
function clientSandboxShop(): array
{
    $tenant = Client::factory()->create();
    $actor = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'email_verified_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);
    Category::factory()->for($site)->create(['slug' => 'candles', 'name' => 'Candles']);
    CommerceReads::giveShop($site);

    return [$actor, $site];
}

function openClientSandboxChannel(): void
{
    config([
        'editor.agent_tools.roles' => ['staff', 'client'],
        'editor.agent_tools.client_portal_enabled' => true,
    ]);
}

it('widens allowedRoles to staff and client on every CommerceOperations::SANDBOX operation and no others under test', function () {
    $registry = app(OperationRegistry::class);

    foreach (CommerceOperations::SANDBOX as $name) {
        expect($registry->get($name)->allowedRoles())
            ->toEqualCanonicalizing(['staff', 'client']);
    }

    expect($registry->get('seed_product_reviews')->allowedRoles())->toBe(['staff']);
});

it('lets the agent-tools gate pass a client on every sandbox op when the client channel is open', function () {
    openClientSandboxChannel();
    [$actor] = clientSandboxShop();
    $gate = app(AgentToolsGate::class);
    $registry = app(OperationRegistry::class);

    expect($actor->isClientUser())->toBeTrue();

    foreach (CommerceOperations::SANDBOX as $name) {
        expect($gate->enabledForUserAndOperation(
            $actor,
            ActorChannel::Webmcp,
            $registry->get($name),
        ))->toBeTrue();
    }
});

it('still refuses a client on a non-sandbox op when the client channel is open', function () {
    openClientSandboxChannel();
    [$actor, $site] = clientSandboxShop();

    expect($actor->isClientUser())->toBeTrue();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'seed_product_reviews',
        ['reviews' => []],
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('forbidden')
        ->and($result->error['message'])->toBe('Agent tools are disabled for this actor.')
        ->and(CommerceReads::auditCount($site, 'seed_product_reviews', 'forbidden'))->toBe(1);
});

it('lets a same-tenant client draft_product through Layer 0 when the client channel is open', function () {
    openClientSandboxChannel();
    [$actor, $site] = clientSandboxShop();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'draft_product',
        CommerceReads::draftProductInput(),
    );

    expect($result->ok)->toBeTrue()
        ->and(Product::query()->where('site_id', $site->id)->where('name', 'Hand-poured Candle')->exists())->toBeTrue()
        ->and(CommerceReads::auditCount($site, 'draft_product', 'ok'))->toBe(1);
});

it('forbids a client of a different tenant from draft_product on this site at Layer 0', function () {
    openClientSandboxChannel();
    [, $site] = clientSandboxShop();
    [$stranger] = clientSandboxShop();

    $result = app(EditorOperations::class)->run(
        new EditorContext($stranger, $site, ActorChannel::Webmcp),
        'draft_product',
        CommerceReads::draftProductInput(),
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('forbidden')
        ->and($result->error['message'])->toBe('Not allowed on this site.')
        ->and(Product::query()->where('site_id', $site->id)->where('name', 'Hand-poured Candle')->exists())->toBeFalse()
        ->and(CommerceReads::auditCount($site, 'draft_product', 'forbidden'))->toBe(1);
});

it('refuses a client sandbox op when the portal flag is off even with the role allowlist open', function () {
    config(['editor.agent_tools.roles' => ['staff', 'client']]);
    [$actor, $site] = clientSandboxShop();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'draft_product',
        CommerceReads::draftProductInput(),
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('forbidden')
        ->and($result->error['message'])->toBe('Agent tools are disabled for this actor.')
        ->and(Product::query()->where('site_id', $site->id)->where('name', 'Hand-poured Candle')->exists())->toBeFalse()
        ->and(CommerceReads::auditCount($site, 'draft_product', 'forbidden'))->toBe(1);
});

it('refuses a handcrafted ui-channel client sandbox op when the portal flag is off', function () {
    config([
        'editor.operations.enabled' => true,
        'editor.agent_tools.roles' => ['staff', 'client'],
    ]);
    [$actor, $site] = clientSandboxShop();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Ui),
        'draft_product',
        CommerceReads::draftProductInput(),
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('forbidden')
        ->and($result->error['message'])->toBe('Agent tools are disabled for this actor.')
        ->and(Product::query()->where('site_id', $site->id)->where('name', 'Hand-poured Candle')->exists())->toBeFalse();
});

it('refuses a handcrafted ui-channel client sandbox op when agent tools are off even with the portal flag on', function () {
    config([
        'editor.operations.enabled' => true,
        'editor.agent_tools.enabled' => false,
        'editor.agent_tools.roles' => ['staff', 'client'],
        'editor.agent_tools.client_portal_enabled' => true,
    ]);
    [$actor, $site] = clientSandboxShop();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Ui),
        'draft_product',
        CommerceReads::draftProductInput(),
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('forbidden')
        ->and($result->error['message'])->toBe('Agent tools are disabled for this actor.')
        ->and(Product::query()->where('site_id', $site->id)->where('name', 'Hand-poured Candle')->exists())->toBeFalse();
});

it('refuses a client sandbox op when agent tools are disabled even with the portal flag on', function () {
    config([
        'editor.agent_tools.enabled' => false,
        'editor.agent_tools.roles' => ['staff', 'client'],
        'editor.agent_tools.client_portal_enabled' => true,
    ]);
    [$actor, $site] = clientSandboxShop();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'draft_product',
        CommerceReads::draftProductInput(),
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('forbidden')
        ->and($result->error['message'])->toBe('Agent tools are disabled for this actor.')
        ->and(Product::query()->where('site_id', $site->id)->where('name', 'Hand-poured Candle')->exists())->toBeFalse();
});

it('still lets staff draft_product when the client-portal setting is off', function () {
    [$actor, $site] = CommerceReads::shopSite();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'draft_product',
        CommerceReads::draftProductInput(),
    );

    expect($result->ok)->toBeTrue()
        ->and(Product::query()->where('site_id', $site->id)->where('name', 'Hand-poured Candle')->exists())->toBeTrue();
});

it('refuses a client on ui for list_theme_token_presets so staff-only ops never skip allowedRoles', function () {
    openClientSandboxChannel();
    [$actor, $site] = clientSandboxShop();
    $gate = app(AgentToolsGate::class);
    $op = app(OperationRegistry::class)->get('list_theme_token_presets');

    expect($actor->isClientUser())->toBeTrue()
        ->and($gate->enabledForUserAndOperation($actor, ActorChannel::Ui, $op))->toBeFalse();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Ui),
        'list_theme_token_presets',
        [],
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('forbidden')
        ->and($result->error['message'])->toBe('Agent tools are disabled for this actor.')
        ->and(CommerceReads::auditCount($site, 'list_theme_token_presets', 'forbidden'))->toBe(1);
});

it('refuses a client on webmcp for list_theme_token_presets when the client channel is open', function () {
    openClientSandboxChannel();
    [$actor] = clientSandboxShop();
    $gate = app(AgentToolsGate::class);
    $op = app(OperationRegistry::class)->get('list_theme_token_presets');

    expect($gate->enabledForUserAndOperation($actor, ActorChannel::Webmcp, $op))->toBeFalse();
});

it('refuses a client regenerate_hero on webmcp even with the portal channel fully open', function () {
    openClientSandboxChannel();
    [$actor, $site] = clientSandboxShop();
    $gate = app(AgentToolsGate::class);
    $op = app(OperationRegistry::class)->get('regenerate_hero');

    expect($op->allowedRoles())->toBeNull()
        ->and($gate->enabledForUserAndOperation($actor, ActorChannel::Webmcp, $op))->toBeFalse();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'regenerate_hero',
        ['composition_revision' => 0],
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('forbidden')
        ->and($result->error['message'])->toBe('Agent tools are disabled for this actor.')
        ->and(CommerceReads::auditCount($site, 'regenerate_hero', 'forbidden'))->toBe(1);
});

it('refuses a client regenerate_hero on ui even with the portal channel fully open', function () {
    openClientSandboxChannel();
    [$actor] = clientSandboxShop();
    $gate = app(AgentToolsGate::class);
    $op = app(OperationRegistry::class)->get('regenerate_hero');

    expect($gate->enabledForUserAndOperation($actor, ActorChannel::Ui, $op))->toBeFalse();
});

it('still lets staff list_theme_token_presets on the ui channel', function () {
    [$actor, $site] = CommerceReads::shopSite();
    $gate = app(AgentToolsGate::class);
    $op = app(OperationRegistry::class)->get('list_theme_token_presets');

    expect($actor->isStaff())->toBeTrue()
        ->and($gate->enabledForUserAndOperation($actor, ActorChannel::Ui, $op))->toBeTrue();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Ui),
        'list_theme_token_presets',
        [],
    );

    expect($result->ok)->toBeTrue()
        ->and($result->data['presets'])->toBe([]);
});

it('refuses a client edit_field on mcp even with the portal channel fully open', function () {
    openClientSandboxChannel();
    [$actor, $site] = clientSandboxShop();
    $gate = app(AgentToolsGate::class);
    $op = app(OperationRegistry::class)->get('edit_field');

    expect($op->allowedRoles())->toBeNull()
        ->and($gate->enabledForUserAndOperation($actor, ActorChannel::Mcp, $op))->toBeFalse();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Mcp),
        'edit_field',
        ['revision_base' => 0],
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('forbidden')
        ->and($result->error['message'])->toBe('Agent tools are disabled for this actor.')
        ->and(CommerceReads::auditCount($site, 'edit_field', 'forbidden'))->toBe(1);
});

it('lets a same-tenant client draft_product on mcp when the client channel is open', function () {
    openClientSandboxChannel();
    [$actor, $site] = clientSandboxShop();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Mcp),
        'draft_product',
        CommerceReads::draftProductInput(),
    );

    expect($result->ok)->toBeTrue()
        ->and(Product::query()->where('site_id', $site->id)->where('name', 'Hand-poured Candle')->exists())->toBeTrue()
        ->and(CommerceReads::auditCount($site, 'draft_product', 'ok'))->toBe(1);
});

it('refuses a client sandbox op on mcp when the portal flag is off even with the role allowlist open', function () {
    config(['editor.agent_tools.roles' => ['staff', 'client']]);
    [$actor, $site] = clientSandboxShop();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Mcp),
        'draft_product',
        CommerceReads::draftProductInput(),
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('forbidden')
        ->and($result->error['message'])->toBe('Agent tools are disabled for this actor.')
        ->and(Product::query()->where('site_id', $site->id)->where('name', 'Hand-poured Candle')->exists())->toBeFalse()
        ->and(CommerceReads::auditCount($site, 'draft_product', 'forbidden'))->toBe(1);
});

it('still lets staff draft_product on mcp when the client-portal setting is off', function () {
    [$actor, $site] = CommerceReads::shopSite();
    $gate = app(AgentToolsGate::class);
    $op = app(OperationRegistry::class)->get('edit_field');

    expect($actor->isStaff())->toBeTrue()
        ->and($gate->enabledForUserAndOperation($actor, ActorChannel::Mcp, $op))->toBeTrue();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Mcp),
        'draft_product',
        CommerceReads::draftProductInput(),
    );

    expect($result->ok)->toBeTrue()
        ->and(Product::query()->where('site_id', $site->id)->where('name', 'Hand-poured Candle')->exists())->toBeTrue();
});
