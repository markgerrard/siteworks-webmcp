<?php

use App\Models\Client;
use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Site;
use App\Models\User;
use App\Services\Shop\ProductImportContract;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\CommerceOperations;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\ToolExposure;
use Database\Seeders\Shop\TaxClassSeeder;
use Illuminate\Support\Str;
use Tests\Support\CommerceReads;

beforeEach(function () {
    CommerceReads::enableFlags();
    $this->seed(TaxClassSeeder::class);
});

/**
 * @return array{0: User, 1: Site}
 */
function importClientShop(): array
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

function openImportClientChannel(): void
{
    config([
        'editor.agent_tools.roles' => ['staff', 'client'],
        'editor.agent_tools.client_portal_enabled' => true,
    ]);
}

/**
 * @return array<string, mixed>
 */
function clientImportInput(): array
{
    return [
        'schema_version' => ProductImportContract::SCHEMA_VERSION,
        'format' => 'json',
        'data' => json_encode([[
            'name' => 'Client Croissant',
            'slug' => 'client-croissant',
            'primary_category_slug' => 'candles',
            'variants' => [['sku' => 'CC-1', 'price_pence' => 800]],
        ]], JSON_THROW_ON_ERROR),
        'expected_revision' => 0,
        'dry_run' => false,
        'idempotency_key' => (string) Str::uuid(),
    ];
}

it('names import_products and describe_import_products on the sandbox set and client SANDBOX allowlist', function () {
    $sandbox = config('editor.exposure.sets.sandbox');

    expect($sandbox)->toContain('import_products')
        ->and($sandbox)->toContain('describe_import_products')
        ->and(CommerceOperations::SANDBOX)->toContain('import_products')
        ->and(CommerceOperations::SANDBOX)->toContain('describe_import_products');
});

it('exposes import_products for a staff agent on a shop sandbox site', function () {
    [$actor, $site] = CommerceReads::shopSite();

    expect(app(ToolExposure::class)->exposes($site, 'import_products'))->toBeTrue()
        ->and(app(ToolExposure::class)->exposes($site, 'describe_import_products'))->toBeTrue();
});

it('lets a staff agent import_products over Webmcp', function () {
    [$actor, $site] = CommerceReads::shopSite();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'import_products',
        clientImportInput(),
    );

    expect($result->ok)->toBeTrue()
        ->and(Product::query()->where('site_id', $site->id)->where('slug', 'client-croissant')->exists())->toBeTrue();
});

it('lets a same-tenant client import_products when the portal channel is open', function () {
    openImportClientChannel();
    [$actor, $site] = importClientShop();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'import_products',
        clientImportInput(),
    );

    expect($result->ok)->toBeTrue()
        ->and(Product::query()->where('site_id', $site->id)->where('slug', 'client-croissant')->exists())->toBeTrue()
        ->and(CommerceReads::auditCount($site, 'import_products', 'ok'))->toBe(1);
});

it('lets a same-tenant client describe_import_products when the portal channel is open', function () {
    openImportClientChannel();
    [$actor, $site] = importClientShop();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'describe_import_products',
        [],
    );

    expect($result->ok)->toBeTrue()
        ->and($result->data['schema_version'])->toBe(1);
});

it('forbids a client of a different tenant from import_products at Layer 0', function () {
    openImportClientChannel();
    [, $site] = importClientShop();
    [$stranger] = importClientShop();

    $result = app(EditorOperations::class)->run(
        new EditorContext($stranger, $site, ActorChannel::Webmcp),
        'import_products',
        clientImportInput(),
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('forbidden')
        ->and($result->error['message'])->toBe('Not allowed on this site.')
        ->and(Product::query()->where('site_id', $site->id)->where('slug', 'client-croissant')->exists())->toBeFalse()
        ->and(CommerceReads::auditCount($site, 'import_products', 'forbidden'))->toBe(1);
});

it('refuses a client import_products when the portal flag is off even with the role allowlist open', function () {
    config(['editor.agent_tools.roles' => ['staff', 'client']]);
    [$actor, $site] = importClientShop();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'import_products',
        clientImportInput(),
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('forbidden')
        ->and($result->error['message'])->toBe('Agent tools are disabled for this actor.')
        ->and(Product::query()->where('site_id', $site->id)->exists())->toBeFalse()
        ->and(CommerceReads::auditCount($site, 'import_products', 'forbidden'))->toBe(1);
});

it('refuses import_products as not_found on Webmcp when omitted from the sandbox set', function () {
    [$actor, $site] = CommerceReads::shopSite();
    CommerceReads::omitFromSandbox('import_products');

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'import_products',
        clientImportInput(),
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('not_found')
        ->and($result->error['message'])->toBe('Unknown operation.');
});
