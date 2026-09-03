<?php

use App\Models\Client;
use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Site;
use App\Models\User;
use App\Services\Site\Editor\CommerceOperations;
use Tests\Support\CommerceReads;

beforeEach(function () {
    $this->withoutVite();
});

/**
 * @return array<string, mixed>
 */
function portalBaseToolsShellConfig(string $html): array
{
    preg_match("/window\\.__siteworks_editor_shell_config__ = JSON\\.parse\\('(.*)'\\);/", $html, $matches);
    expect($matches)->toHaveKey(1);

    $json = json_decode('"'.$matches[1].'"', true, 512, JSON_THROW_ON_ERROR);

    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @return array{0: Site, 1: User}
 */
function portalBaseToolsClient(): array
{
    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);
    Category::factory()->for($site)->create();
    Product::factory()->for($site)->published()->create();
    CommerceReads::giveShop($site);

    return [$site, $client];
}

function enablePortalBaseWebmcp(): void
{
    config([
        'editor.operations.enabled' => true,
        'editor.agent_tools.enabled' => true,
        'editor.agent_tools.roles' => ['staff', 'client'],
        'editor.agent_tools.client_portal_enabled' => true,
    ]);
}

it('seeds portal_base tools on the Design page when both portal gates are on', function () {
    enablePortalBaseWebmcp();
    [$site, $client] = portalBaseToolsClient();

    $html = $this->actingAs($client)
        ->get(route('client.portal.design', $site))
        ->assertOk()
        ->getContent();

    $config = portalBaseToolsShellConfig($html);

    expect($config['surface'])->toBe('portal-shop')
        ->and($config['capabilities'])->toContain('agent_tools')
        ->and($config['agentTools'])->toEqualCanonicalizing(CommerceOperations::PORTAL_BASE)
        ->and($config['agentTools'])->toContain('get_brand_system')
        ->and($config['agentTools'])->toContain('get_site_context')
        ->and($config['agentTools'])->toContain('get_logo_assets')
        ->and($config['agentTools'])->not->toContain('draft_product')
        ->and($config['agentTools'])->not->toContain('edit_field')
        ->and($config['agentTools'])->not->toContain('add_section')
        ->and($config['operationUrl'])->toBe("/sites/{$site->id}/operations/__operation__");
});

it('seeds portal_base tools on Business Info when both portal gates are on', function () {
    enablePortalBaseWebmcp();
    [$site, $client] = portalBaseToolsClient();

    $html = $this->actingAs($client)
        ->get(route('client.portal.business-info', $site))
        ->assertOk()
        ->getContent();

    $config = portalBaseToolsShellConfig($html);

    expect($config['surface'])->toBe('portal-shop')
        ->and($config['agentTools'])->toEqualCanonicalizing(CommerceOperations::PORTAL_BASE)
        ->and($config['agentTools'])->not->toContain('edit_field');
});

it('omits agent_tools on Design when the client-portal setting is off', function () {
    CommerceReads::enableFlags();
    config(['editor.agent_tools.roles' => ['staff', 'client']]);
    [$site, $client] = portalBaseToolsClient();

    $html = $this->actingAs($client)
        ->get(route('client.portal.design', $site))
        ->assertOk()
        ->getContent();

    $config = portalBaseToolsShellConfig($html);

    expect($config['surface'])->toBe('portal-shop')
        ->and($config['capabilities'])->not->toContain('agent_tools')
        ->and($config)->not->toHaveKey('agentTools')
        ->and($config)->not->toHaveKey('operationUrl');
});

it('does not mount the seed on login, account, or the multi-site listing', function () {
    enablePortalBaseWebmcp();
    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    Site::factory()->count(2)->create(['client_id' => $tenant->id]);

    $login = $this->get(route('login'))->assertOk()->getContent();
    $account = $this->actingAs($client)->get(route('client.account'))->assertOk()->getContent();
    $listing = $this->actingAs($client)->get(route('client.portal.sites'))->assertOk()->getContent();

    expect($login)->not->toContain('__siteworks_editor_shell_config__')
        ->and($account)->not->toContain('__siteworks_editor_shell_config__')
        ->and($listing)->not->toContain('__siteworks_editor_shell_config__');
});

it('still seeds the fuller commerce set on a portal shop page', function () {
    enablePortalBaseWebmcp();
    [$site, $client] = portalBaseToolsClient();

    $html = $this->actingAs($client)
        ->get(route('client.portal.shop.products', $site))
        ->assertOk()
        ->getContent();

    $config = portalBaseToolsShellConfig($html);

    expect($config['surface'])->toBe('portal-shop')
        ->and($config['agentTools'])->toEqualCanonicalizing(CommerceOperations::SANDBOX)
        ->and($config['agentTools'])->toContain('draft_product')
        ->and($config['agentTools'])->toContain('manage_category');
});

it('seeds the fuller commerce set on the client product editor (new in this change, locked deliberately)', function () {
    enablePortalBaseWebmcp();
    [$site, $client] = portalBaseToolsClient();
    $product = \App\Models\Shop\Product::query()->where('site_id', $site->id)->firstOrFail();

    $html = $this->actingAs($client)
        ->get(route('client.portal.shop.products.edit', [$site, $product]))
        ->assertOk()
        ->getContent();

    $config = portalBaseToolsShellConfig($html);

    expect($config['surface'])->toBe('portal-shop')
        ->and($config['agentTools'])->toEqualCanonicalizing(CommerceOperations::SANDBOX)
        ->and($config['agentTools'])->toContain('update_draft_product')
        ->and($config['agentTools'])->not->toContain('edit_field');
});

it('keeps import_products off the portal_base advertisement on non-shop pages', function () {
    enablePortalBaseWebmcp();
    [$site, $client] = portalBaseToolsClient();

    $html = $this->actingAs($client)
        ->get(route('client.portal.design', $site))
        ->assertOk()
        ->getContent();

    $config = portalBaseToolsShellConfig($html);

    expect($config['agentTools'])->not->toContain('import_products')
        ->and($config['agentTools'])->toContain('get_brand_system');
});
