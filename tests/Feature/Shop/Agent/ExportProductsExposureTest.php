<?php

use App\Models\Client;
use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Site;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\CommerceOperations;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\ToolExposure;
use Tests\Support\CommerceReads;

beforeEach(function () {
    CommerceReads::enableFlags();
});

it('names export_products on the sandbox exposure set and the client SANDBOX allowlist (read-only)', function () {
    $sandbox = config('editor.exposure.sets.sandbox');

    // Clients get export via WebMCP — a read-only, tenant-scoped
    // tool, so it joins the client SANDBOX allowlist like list/get_product.
    expect($sandbox)->toContain('export_products')
        ->and(CommerceOperations::SANDBOX)->toContain('export_products');
});

it('exposes export_products for a staff agent on a shop sandbox site', function () {
    [$actor, $site] = CommerceReads::shopSite();

    $exposed = app(ToolExposure::class)->setFor($site);

    expect($exposed)->toContain('export_products')
        ->and(app(ToolExposure::class)->exposes($site, 'export_products'))->toBeTrue();
});

it('lets a staff agent call export_products over the Webmcp channel once exposed', function () {
    [$actor, $site] = CommerceReads::shopSite();
    Product::factory()->for($site)->published()->create();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'export_products',
        ['format' => 'csv'],
    );

    expect($result->ok)->toBeTrue()
        ->and($result->data)->toHaveKey('download_url');
});

it('lets a client run export_products on their own site over Webmcp (read-only sandbox tool)', function () {
    $tenant = Client::factory()->create();
    $actor = User::factory()->create(['client_id' => $tenant->id, 'role' => null]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);
    Category::factory()->for($site)->create();
    Product::factory()->for($site)->published()->create();
    CommerceReads::giveShop($site);
    config(['editor.agent_tools.roles' => ['staff', 'client'], 'editor.agent_tools.client_portal_enabled' => true]);

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'export_products',
        ['format' => 'csv'],
    );

    expect($result->ok)->toBeTrue()
        ->and($result->data)->toHaveKey('download_url');
});

it('refuses a client running export_products against another tenant site', function () {
    $mine = Client::factory()->create();
    $actor = User::factory()->create(['client_id' => $mine->id, 'role' => null]);
    $other = Site::factory()->create(['client_id' => Client::factory()->create()->id]);
    CommerceReads::giveShop($other);
    config(['editor.agent_tools.roles' => ['staff', 'client'], 'editor.agent_tools.client_portal_enabled' => true]);

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $other, ActorChannel::Webmcp),
        'export_products',
        ['format' => 'csv'],
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('forbidden');
});

it('refuses export_products as not_found on Webmcp when omitted from the sandbox set', function () {
    [$actor, $site] = CommerceReads::shopSite();
    CommerceReads::omitFromSandbox('export_products');

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'export_products',
        ['format' => 'csv'],
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('not_found')
        ->and($result->error['message'])->toBe('Unknown operation.');
});
