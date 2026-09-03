<?php

use App\Enums\Shop\ProductStatus;
use App\Models\Client;
use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopDraft;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use App\Models\Site\EditorOperationLog;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use Tests\Support\CommerceReads;

beforeEach(function () {
    CommerceReads::enableFlags();
});

it('returns the envelope with a valid signed download url, not the catalogue, carrying sku/price/category per format', function (string $format, string $mime) {
    [$actor, $site, $category] = CommerceReads::shopSite();
    $product = Product::factory()->for($site)->published()->create(['name' => 'Scarlet Rose', 'slug' => 'scarlet-rose']);
    $product->categories()->attach($category->id, ['is_primary' => true]);
    ProductVariant::factory()->for($product)->create(['sku' => 'ROSE-STEM', 'label' => 'Stem', 'price_cents' => 1250]);

    $result = CommerceReads::run($actor, $site, 'export_products', ['format' => $format]);

    expect($result->ok)->toBeTrue()
        ->and($result->data)->toHaveKeys(['download_url', 'filename', 'mime', 'bytes', 'sha256', 'product_count', 'expires_at', 'requires_current_session', 'catalogue_revision'])
        ->and($result->data['mime'])->toBe($mime)
        ->and($result->data['product_count'])->toBe(1)
        ->and($result->data['requires_current_session'])->toBeTrue()
        ->and($result->data['bytes'])->toBeGreaterThan(0)
        ->and($result->data['sha256'])->toMatch('/^[0-9a-f]{64}$/')
        ->and(json_encode($result->data))->not->toContain('Scarlet Rose');

    $download = $this->actingAs($actor)->get($result->data['download_url']);
    $download->assertSuccessful();
    $body = $download->getContent();

    // The envelope sha256/bytes must match the file the client actually saves.
    expect(hash('sha256', $body))->toBe($result->data['sha256'])
        ->and(strlen($body))->toBe($result->data['bytes']);

    expect($download->headers->get('content-type'))->toContain($mime)
        ->and($body)->toContain('Scarlet Rose')
        ->and($body)->toContain('scarlet-rose')
        ->and($body)->toContain('ROSE-STEM');

    match ($format) {
        'csv' => expect($body)->toContain('12.50')->and($body)->toContain($category->name),
        'md' => expect($body)->toContain('12.50')->and($body)->toContain($category->slug),
        'json' => expect($body)->toContain('1250')->and($body)->toContain($category->slug),
    };
})->with([
    ['csv', 'text/csv'],
    ['md', 'text/markdown'],
    ['json', 'application/json'],
]);

it('defaults to csv when format is omitted', function () {
    [$actor, $site] = CommerceReads::shopSite();
    Product::factory()->for($site)->published()->create();

    $result = CommerceReads::run($actor, $site, 'export_products', []);

    expect($result->ok)->toBeTrue()
        ->and($result->data['mime'])->toBe('text/csv')
        ->and($result->data['filename'])->toEndWith('-products.csv');
});

it('narrows product_count and the downloaded rows by status', function () {
    [$actor, $site] = CommerceReads::shopSite();
    Product::factory()->for($site)->published()->create(['name' => 'Published Rose', 'slug' => 'published-rose']);
    Product::factory()->for($site)->create(['name' => 'Draft Lily', 'slug' => 'draft-lily', 'status' => ProductStatus::Draft]);

    $any = CommerceReads::run($actor, $site, 'export_products', ['status' => 'any']);
    $published = CommerceReads::run($actor, $site, 'export_products', ['status' => 'published']);

    expect($any->data['product_count'])->toBe(2)
        ->and($published->data['product_count'])->toBe(1);

    $csv = $this->actingAs($actor)->get($published->data['download_url'])->getContent();
    expect($csv)->toContain('Published Rose')
        ->and($csv)->not->toContain('Draft Lily');
});

it('narrows product_count by category_slug and refuses an unknown slug', function () {
    [$actor, $site, $category] = CommerceReads::shopSite();
    $product = Product::factory()->for($site)->published()->create(['name' => 'Candle Jar', 'slug' => 'candle-jar']);
    $product->categories()->attach($category->id, ['is_primary' => true]);
    Product::factory()->for($site)->published()->create(['name' => 'Uncategorised Thing', 'slug' => 'uncategorised']);

    $scoped = CommerceReads::run($actor, $site, 'export_products', ['category_slug' => $category->slug]);
    expect($scoped->ok)->toBeTrue()
        ->and($scoped->data['product_count'])->toBe(1);

    $missing = CommerceReads::run($actor, $site, 'export_products', ['category_slug' => 'does-not-exist']);
    expect($missing->ok)->toBeFalse()
        ->and($missing->error['code'])->toBe('not_found');
});

it('refuses an invalid format or status with a validation error', function () {
    [$actor, $site] = CommerceReads::shopSite();

    $badFormat = CommerceReads::run($actor, $site, 'export_products', ['format' => 'xlsx']);
    $badStatus = CommerceReads::run($actor, $site, 'export_products', ['status' => 'bogus']);

    expect($badFormat->ok)->toBeFalse()
        ->and($badFormat->error['code'])->toBe('validation')
        ->and($badStatus->ok)->toBeFalse()
        ->and($badStatus->error['code'])->toBe('validation');
});

it('refuses a client (non-staff) actor with forbidden and denies the op on every channel', function () {
    $tenant = Client::factory()->create();
    $actor = User::factory()->create(['client_id' => $tenant->id, 'role' => null]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);
    Category::factory()->for($site)->create();
    CommerceReads::giveShop($site);
    // Exposure is a separate question from role (Part 3 adds export_products
    // to the sandbox set); force it exposed here so this test isolates the
    // role/SANDBOX-const denial rather than an exposure-set miss.
    CommerceReads::exposeOnSandbox('export_products');

    foreach ([ActorChannel::Ui, ActorChannel::Webmcp] as $channel) {
        $result = app(EditorOperations::class)->run(
            new EditorContext($actor, $site, $channel),
            'export_products',
            ['format' => 'csv'],
        );

        expect($result->ok)->toBeFalse()
            ->and($result->error['code'])->toBe('forbidden');
    }
});

it('serves the frozen mint-time bytes even after the catalogue mutates inside the TTL (no re-render race)', function () {
    [$actor, $site, $category] = CommerceReads::shopSite();
    $product = Product::factory()->for($site)->published()->create(['name' => 'Original Rose', 'slug' => 'original-rose']);
    $product->categories()->attach($category->id, ['is_primary' => true]);

    $result = CommerceReads::run($actor, $site, 'export_products', ['format' => 'csv']);
    expect($result->ok)->toBeTrue();

    // Mutate the catalogue AFTER minting but before the download. A re-render on
    // fetch would pick this up and break the minted sha256 — the whole bug.
    Product::factory()->for($site)->published()->create(['name' => 'Late Tulip', 'slug' => 'late-tulip']);

    $download = $this->actingAs($actor)->get($result->data['download_url']);
    $download->assertSuccessful();
    $body = $download->getContent();

    // The download is byte-identical to what was hashed at mint time: the new
    // product is absent and the sha256 still matches exactly.
    expect(hash('sha256', $body))->toBe($result->data['sha256'])
        ->and(strlen($body))->toBe($result->data['bytes'])
        ->and($body)->toContain('Original Rose')
        ->and($body)->not->toContain('Late Tulip');
});

it('serves the frozen bytes idempotently: a retried download within the TTL still succeeds', function () {
    [$actor, $site] = CommerceReads::shopSite();
    Product::factory()->for($site)->published()->create(['name' => 'Repeat Rose']);

    $result = CommerceReads::run($actor, $site, 'export_products', ['format' => 'csv']);

    $first = $this->actingAs($actor)->get($result->data['download_url']);
    $second = $this->actingAs($actor)->get($result->data['download_url']);

    $first->assertSuccessful();
    $second->assertSuccessful();
    expect($second->getContent())->toBe($first->getContent())
        ->and(hash('sha256', $second->getContent()))->toBe($result->data['sha256']);
});

it('409s export_stale when the frozen bytes have been evicted before download', function () {
    [$actor, $site] = CommerceReads::shopSite();
    Product::factory()->for($site)->published()->create();

    $result = CommerceReads::run($actor, $site, 'export_products', ['format' => 'csv']);

    // Simulate the frozen artefact expiring/evicting inside the URL TTL. The
    // download must refuse (409 export_stale), never silently re-render.
    \Illuminate\Support\Facades\Cache::flush();

    $stale = $this->actingAs($actor)->get($result->data['download_url']);
    $stale->assertStatus(409);
    expect($stale->getContent())->toContain('export_stale');
});

it('refuses an expired signed download url', function () {
    [$actor, $site] = CommerceReads::shopSite();
    Product::factory()->for($site)->published()->create();

    $result = CommerceReads::run($actor, $site, 'export_products', []);
    $this->travel(6)->minutes();

    $this->actingAs($actor)->get($result->data['download_url'])->assertForbidden();
});

it('refuses a tampered signed download url', function () {
    [$actor, $site] = CommerceReads::shopSite();
    Product::factory()->for($site)->published()->create();

    $result = CommerceReads::run($actor, $site, 'export_products', []);
    $tampered = str_contains($result->data['download_url'], 'status=any')
        ? str_replace('status=any', 'status=published', $result->data['download_url'])
        : $result->data['download_url'].'&status=published';

    $this->actingAs($actor)->get($tampered)->assertForbidden();
});

it('403s a signed download url for a staff actor SitePolicy forbids, even with a valid signature', function () {
    [$actor, $site] = CommerceReads::shopSite();
    Product::factory()->for($site)->published()->create();
    $stranger = User::factory()->staff(\App\Enums\AgentRole::Agent)->create();

    $result = CommerceReads::run($actor, $site, 'export_products', []);

    $this->actingAs($stranger)->get($result->data['download_url'])->assertForbidden();
});

it('performs zero writes: no catalogue_revision bump and no snapshot rebuild', function () {
    [$actor, $site] = CommerceReads::shopSite();
    Product::factory()->for($site)->published()->create();
    ProductVariant::factory()->for(Product::query()->where('site_id', $site->id)->first())->create();

    $beforeRevision = (int) (ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision') ?? 0);
    $beforeSnapshotId = ShopSnapshotCurrent::query()->where('site_id', $site->id)->value('snapshot_id');

    $result = CommerceReads::run($actor, $site, 'export_products', ['format' => 'json']);
    $this->actingAs($actor)->get($result->data['download_url'])->assertSuccessful();

    $afterRevision = (int) (ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision') ?? 0);

    expect($result->data['catalogue_revision'])->toBe($beforeRevision)
        ->and($afterRevision)->toBe($beforeRevision)
        ->and(ShopSnapshotCurrent::query()->where('site_id', $site->id)->value('snapshot_id'))->toBe($beforeSnapshotId);
});

it('writes one audit row when the agent-tools flag is off', function () {
    [$actor, $site] = CommerceReads::shopSite();
    CommerceReads::exposeOnSandbox('export_products');
    config(['editor.agent_tools.enabled' => false]);
    $before = EditorOperationLog::query()->count();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'export_products',
        [],
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('forbidden')
        ->and(EditorOperationLog::query()->count())->toBe($before + 1)
        ->and(CommerceReads::auditCount($site, 'export_products', 'forbidden'))->toBe(1);
});

it('is read-only, shop-addressed, and staff+client by allowedRoles', function () {
    $operation = app(\App\Services\Site\Editor\Operations\ExportProductsOperation::class);

    expect($operation->readOnly())->toBeTrue()
        ->and($operation->address())->toBe('shop')
        ->and($operation->allowedRoles())->toBe(['staff', 'client'])
        ->and($operation->sideEffects())->toContain('CURRENT')
        ->and($operation->sideEffects())->toContain('Read-only');
});

it('403s a client signed export download after the client-portal flag is turned off', function () {
    $tenant = Client::factory()->create();
    $actor = User::factory()->create(['client_id' => $tenant->id, 'role' => null]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);
    Category::factory()->for($site)->create();
    Product::factory()->for($site)->published()->create(['name' => 'Revoked Cake']);
    CommerceReads::giveShop($site);
    config(['editor.agent_tools.roles' => ['staff', 'client'], 'editor.agent_tools.client_portal_enabled' => true]);

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'export_products',
        ['format' => 'csv'],
    );

    expect($result->ok)->toBeTrue();
    $url = $result->data['download_url'];

    config(['editor.agent_tools.client_portal_enabled' => false]);

    $this->actingAs($actor)->get($url)->assertForbidden();
});

it('still serves a staff signed export download when the client-portal flag is off', function () {
    [$actor, $site] = CommerceReads::shopSite();
    Product::factory()->for($site)->published()->create(['name' => 'Staff Cake']);

    $result = CommerceReads::run($actor, $site, 'export_products', ['format' => 'csv']);

    expect($result->ok)->toBeTrue();

    config(['editor.agent_tools.client_portal_enabled' => false]);

    $this->actingAs($actor)->get($result->data['download_url'])->assertSuccessful();
});

it('mints the client-portal download URL for a client actor, not the staff agents origin', function () {
    $tenant = Client::factory()->create();
    $actor = User::factory()->create(['client_id' => $tenant->id, 'role' => null]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);
    Category::factory()->for($site)->create();
    Product::factory()->for($site)->published()->create(['name' => 'Client Cake']);
    CommerceReads::giveShop($site);
    config(['editor.agent_tools.roles' => ['staff', 'client'], 'editor.agent_tools.client_portal_enabled' => true]);

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'export_products',
        ['format' => 'csv'],
    );

    expect($result->ok)->toBeTrue();
    $url = $result->data['download_url'];

    // Regression guard: the URL must target the customer origin, not the
    // staff agents origin.
    expect($url)->toContain(config('domains.customer_domain'))
        ->and($url)->not->toContain(config('domains.agent_domain'))
        ->and($url)->toContain('/products/export-download');

    $this->actingAs($actor)->get($url)->assertSuccessful();
});

it('mints the staff agents download URL for a staff actor', function () {
    [$actor, $site] = CommerceReads::shopSite();

    $result = CommerceReads::run($actor, $site, 'export_products', ['format' => 'csv']);

    expect($result->ok)->toBeTrue()
        ->and($result->data['download_url'])->toContain(config('domains.agent_domain'))
        ->and($result->data['download_url'])->not->toContain(config('domains.customer_domain'));
});
