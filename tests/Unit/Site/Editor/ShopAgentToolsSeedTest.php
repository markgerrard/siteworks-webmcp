<?php

use App\Services\Site\Editor\CommerceOperations;
use App\Services\Site\Editor\ShopAgentToolsSeed;
use Tests\Support\CommerceReads;

it('defaults the seed surface to shop-admin', function () {
    CommerceReads::enableFlags();
    [$actor, $site] = CommerceReads::shopSite();

    $config = app(ShopAgentToolsSeed::class)->config($actor, $site);

    expect($config['surface'])->toBe('shop-admin');
});

it('tags the seed surface as portal-shop when that surface is requested', function () {
    CommerceReads::enableFlags();
    [$actor, $site] = CommerceReads::shopSite();

    $config = app(ShopAgentToolsSeed::class)->config($actor, $site, 'portal-shop');

    expect($config['surface'])->toBe('portal-shop');
});

it('omits agent_tools on portal-shop when the client-portal setting is off, even with the user-channel gate open', function () {
    CommerceReads::enableFlags();
    [$actor, $site] = CommerceReads::shopSite();

    $config = app(ShopAgentToolsSeed::class)->config($actor, $site, 'portal-shop');

    expect($config['surface'])->toBe('portal-shop')
        ->and($config['capabilities'])->not->toContain('agent_tools')
        ->and($config)->not->toHaveKey('agentTools')
        ->and($config)->not->toHaveKey('operationUrl')
        ->and($config)->not->toHaveKey('csrfToken')
        ->and($config)->not->toHaveKey('protocol');
});

it('still seeds shop-admin agent_tools when the client-portal setting is off', function () {
    CommerceReads::enableFlags();
    [$actor, $site] = CommerceReads::shopSite();

    $config = app(ShopAgentToolsSeed::class)->config($actor, $site);

    expect($config['surface'])->toBe('shop-admin')
        ->and($config['capabilities'])->toContain('agent_tools')
        ->and($config['agentTools'])->toEqualCanonicalizing(CommerceOperations::SANDBOX);
});

it('seeds portal-shop agent_tools only when the user-channel gate and the client-portal setting are both on', function () {
    CommerceReads::enableFlags();
    config(['editor.agent_tools.client_portal_enabled' => true]);
    [$actor, $site] = CommerceReads::shopSite();

    $config = app(ShopAgentToolsSeed::class)->config($actor, $site, 'portal-shop');

    expect($config['surface'])->toBe('portal-shop')
        ->and($config['capabilities'])->toContain('agent_tools')
        ->and($config['agentTools'])->toEqualCanonicalizing(CommerceOperations::SANDBOX)
        ->and($config['operationUrl'])->toBe("/sites/{$site->id}/operations/__operation__")
        ->and($config)->toHaveKey('csrfToken')
        ->and($config['protocol'])->toBe('siteworks-editor-1');
});

it('omits portal-shop agent_tools when the client-portal setting is on but the user-channel gate is off', function () {
    CommerceReads::enableFlags();
    config([
        'editor.agent_tools.enabled' => false,
        'editor.agent_tools.client_portal_enabled' => true,
    ]);
    [$actor, $site] = CommerceReads::shopSite();

    $config = app(ShopAgentToolsSeed::class)->config($actor, $site, 'portal-shop');

    expect($config['capabilities'])->not->toContain('agent_tools')
        ->and($config)->not->toHaveKey('agentTools')
        ->and($config)->not->toHaveKey('operationUrl')
        ->and($config)->not->toHaveKey('csrfToken')
        ->and($config)->not->toHaveKey('protocol');
});

it('advertises the portal_base page set instead of commerce writes when that set is requested', function () {
    CommerceReads::enableFlags();
    config(['editor.agent_tools.client_portal_enabled' => true]);
    [$actor, $site] = CommerceReads::shopSite();

    $config = app(ShopAgentToolsSeed::class)->config($actor, $site, 'portal-shop', 'portal_base');

    expect($config['surface'])->toBe('portal-shop')
        ->and($config['capabilities'])->toContain('agent_tools')
        ->and($config['agentTools'])->toEqualCanonicalizing(CommerceOperations::PORTAL_BASE)
        ->and($config['agentTools'])->toContain('get_brand_system')
        ->and($config['agentTools'])->not->toContain('import_products')
        ->and($config['agentTools'])->not->toContain('draft_product')
        ->and($config['agentTools'])->not->toContain('edit_field')
        ->and($config['agentTools'])->not->toContain('add_section')
        ->and($config['exposureSet'])->toBe('sandbox');
});

it('still advertises the fuller SANDBOX set when the page set is sandbox', function () {
    CommerceReads::enableFlags();
    [$actor, $site] = CommerceReads::shopSite();

    $config = app(ShopAgentToolsSeed::class)->config($actor, $site, 'shop-admin', 'sandbox');

    expect($config['agentTools'])->toEqualCanonicalizing(CommerceOperations::SANDBOX)
        ->and($config['agentTools'])->toContain('draft_product')
        // Drift guard: the page set 'sandbox' must resolve to the commerce
        // allowlist, never config('editor.exposure.sets.sandbox') — routing it
        // through the config would globalise specialist editor mutations.
        ->and($config['agentTools'])->not->toContain('edit_field')
        ->and($config['agentTools'])->not->toContain('add_section')
        ->and($config['agentTools'])->not->toContain('update_brand_theme');
});

it('drops shop-addressed portal_base tools on a brochure site with no shop', function () {
    CommerceReads::enableFlags();
    $actor = \App\Models\User::factory()->staff()->create();
    $site = \App\Models\Site::factory()->create(['created_by_user_id' => $actor->id]);

    $config = app(ShopAgentToolsSeed::class)->config($actor, $site, 'shop-admin', 'portal_base');

    expect($config['agentTools'])->toContain('get_brand_system')
        ->and($config['agentTools'])->toContain('get_site_context')
        ->and($config['agentTools'])->toContain('get_logo_assets')
        ->and($config['agentTools'])->toContain('upload_image')
        ->and($config['agentTools'])->not->toContain('list_products')
        ->and($config['agentTools'])->not->toContain('get_product')
        ->and($config['agentTools'])->not->toContain('import_products')
        ->and($config['agentTools'])->not->toContain('export_products')
        ->and($config['agentTools'])->not->toContain('draft_product');
});

it('refuses an unknown page-level exposure set name', function () {
    CommerceReads::enableFlags();
    [$actor, $site] = CommerceReads::shopSite();

    app(ShopAgentToolsSeed::class)->config($actor, $site, 'shop-admin', 'internal');
})->throws(InvalidArgumentException::class, 'page-level exposure set');


