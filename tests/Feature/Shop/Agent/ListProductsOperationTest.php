<?php

use App\Enums\Shop\ProductStatus;
use App\Models\Client;
use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Site;
use App\Models\Site\EditorOperationLog;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\Operations\ShopListProductsOperation;
use Tests\Support\CommerceReads;

beforeEach(function () {
    CommerceReads::enableFlags();
});

it('lists site-scoped products including drafts after SitePolicy and returns catalogue_revision', function () {
    [$actor, $site] = CommerceReads::shopSite();
    $draft = Product::factory()->for($site)->create(['name' => 'Draft Candle', 'slug' => 'draft-candle']);
    Product::factory()->for($site)->published()->create(['name' => 'Live Candle', 'slug' => 'live-candle']);
    $foreign = Product::factory()->create(['name' => 'Other Shop', 'slug' => 'other-shop']);

    $result = CommerceReads::run($actor, $site, 'list_products', ['limit' => 50]);

    expect($result->ok)->toBeTrue()
        ->and($result->data['catalogue_revision'])->toBe(0)
        ->and($result->state->pageId)->toBeNull()
        ->and($result->state->draftRevisionId)->toBeNull()
        ->and($result->state->structureEpoch)->toBeNull()
        ->and(collect($result->data['products'])->pluck('slug')->all())->toContain('draft-candle', 'live-candle')
        ->and(collect($result->data['products'])->pluck('slug')->all())->not->toContain($foreign->slug)
        ->and(collect($result->data['products'])->firstWhere('slug', 'draft-candle')['status'])->toBe(ProductStatus::Draft->value)
        ->and(collect($result->data['products'])->firstWhere('slug', 'draft-candle')['revision'])->toBe((int) $draft->revision);
});

it('caps limit at 50 and refuses a larger limit', function () {
    [$actor, $site] = CommerceReads::shopSite();

    $refused = CommerceReads::run($actor, $site, 'list_products', ['limit' => 51]);
    $ok = CommerceReads::run($actor, $site, 'list_products', ['limit' => 50]);

    expect($refused->ok)->toBeFalse()
        ->and($refused->error['code'])->toBe('validation')
        ->and($ok->ok)->toBeTrue();
});

it('uses a server-signed cursor and refuses a tampered or foreign-site cursor', function () {
    [$actor, $site] = CommerceReads::shopSite();
    Product::factory()->for($site)->create(['name' => 'Alpha', 'slug' => 'alpha']);
    Product::factory()->for($site)->create(['name' => 'Beta', 'slug' => 'beta']);
    [$foreignActor, $foreignSite] = CommerceReads::shopSite();
    Product::factory()->for($foreignSite)->create(['name' => 'Foreign', 'slug' => 'foreign-only']);
    Product::factory()->for($foreignSite)->create(['name' => 'Foreign Two', 'slug' => 'foreign-two']);

    $page = CommerceReads::run($actor, $site, 'list_products', ['limit' => 1]);
    expect($page->ok)->toBeTrue()
        ->and($page->data['products'])->toHaveCount(1)
        ->and($page->data['next_cursor'])->toBeString()->not->toBeEmpty();

    $next = CommerceReads::run($actor, $site, 'list_products', [
        'limit' => 1,
        'cursor' => $page->data['next_cursor'],
    ]);
    expect($next->ok)->toBeTrue()
        ->and($next->data['products'])->toHaveCount(1)
        ->and($next->data['products'][0]['slug'])->not->toBe($page->data['products'][0]['slug']);

    $tampered = CommerceReads::run($actor, $site, 'list_products', [
        'cursor' => substr($page->data['next_cursor'], 0, -2).'xx',
    ]);
    expect($tampered->ok)->toBeFalse()
        ->and($tampered->error['code'])->toBe('validation');

    $stolen = CommerceReads::run($actor, $site, 'list_products', [
        'cursor' => CommerceReads::run($foreignActor, $foreignSite, 'list_products', ['limit' => 1])->data['next_cursor'],
    ]);
    expect($stolen->ok)->toBeFalse()
        ->and($stolen->error['code'])->toBe('not_found')
        ->and($foreignActor->id)->toBeInt();
});

it('omits search_vector, html, and raw capability ids from the list payload', function () {
    [$actor, $site] = CommerceReads::shopSite();
    Product::factory()->for($site)->create(['name' => 'Listed', 'slug' => 'listed']);

    $result = CommerceReads::run($actor, $site, 'list_products');
    $encoded = json_encode($result->data);

    expect($result->ok)->toBeTrue()
        ->and($encoded)->not->toContain('search_vector')
        ->and($encoded)->not->toContain('<html')
        ->and($result->data)->not->toHaveKey('html')
        ->and($result->data['products'][0])->not->toHaveKeys(['id', 'product_id', 'variant_id', 'media_id', 'search_vector']);
});

it('never consults RenderContext::fromRequest for includeDrafts', function () {
    $source = file_get_contents(app_path('Services/Site/Editor/Operations/ShopListProductsOperation.php'));

    expect($source)->not->toContain('RenderContext::fromRequest')
        ->and(app(ShopListProductsOperation::class)->readOnly())->toBeTrue()
        ->and(app(ShopListProductsOperation::class)->address())->toBe('shop');
});

it('writes one audit row and nothing else when the agent-tools flag is off', function () {
    [$actor, $site] = CommerceReads::shopSite();
    CommerceReads::exposeOnSandbox('list_products');
    config(['editor.agent_tools.enabled' => false]);
    $before = EditorOperationLog::query()->count();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'list_products',
        [],
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('forbidden')
        ->and(EditorOperationLog::query()->count())->toBe($before + 1)
        ->and(CommerceReads::auditCount($site, 'list_products', 'forbidden'))->toBe(1);
});

it('writes one audit row and nothing else when the exposure set omits the operation', function () {
    [$actor, $site] = CommerceReads::shopSite();
    CommerceReads::omitFromSandbox('list_products');
    $before = EditorOperationLog::query()->count();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'list_products',
        [],
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('not_found')
        ->and($result->error['message'])->toBe('Unknown operation.')
        ->and(EditorOperationLog::query()->count())->toBe($before + 1)
        ->and(CommerceReads::auditCount($site, 'list_products', 'not_found'))->toBe(1);
});

it('writes one audit row and nothing else when the actor role is denied', function () {
    $client = Client::factory()->create();
    $actor = User::factory()->create(['client_id' => $client->id, 'role' => null]);
    $site = Site::factory()->create(['client_id' => $client->id]);
    Category::factory()->for($site)->create();
    CommerceReads::giveShop($site);
    CommerceReads::exposeOnSandbox('list_products');
    $before = EditorOperationLog::query()->count();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'list_products',
        [],
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('forbidden')
        ->and(EditorOperationLog::query()->count())->toBe($before + 1)
        ->and(CommerceReads::auditCount($site, 'list_products', 'forbidden'))->toBe(1);
});

it('writes one audit row and nothing else when the site has no shop', function () {
    $actor = User::factory()->staff(\App\Enums\AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $actor->id]);
    $beforeSnapshots = \App\Models\Shop\ShopSnapshotCurrent::query()->where('site_id', $site->id)->count();
    $before = EditorOperationLog::query()->count();

    $result = CommerceReads::run($actor, $site, 'list_products');

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('not_found')
        ->and(EditorOperationLog::query()->count())->toBe($before + 1)
        ->and(CommerceReads::auditCount($site, 'list_products', 'not_found'))->toBe(1)
        ->and(\App\Models\Shop\ShopSnapshotCurrent::query()->where('site_id', $site->id)->count())->toBe($beforeSnapshots);
});

it('filters by q on the test database driver, matching name and description words', function () {
    [$actor, $site] = CommerceReads::shopSite();
    Product::factory()->for($site)->create(['name' => 'Fig & Walnut Tart', 'slug' => 'fig-walnut-tart', 'status' => ProductStatus::Published]);
    Product::factory()->for($site)->create(['name' => 'Meyer Lemon Tart', 'slug' => 'meyer-lemon-tart', 'status' => ProductStatus::Draft]);
    Product::factory()->for($site)->create(['name' => 'Almond Croissant', 'slug' => 'almond-croissant', 'description' => 'Frangipane, like a tart in a pastry', 'status' => ProductStatus::Published]);
    Product::factory()->for($site)->create(['name' => 'Plain Loaf', 'slug' => 'plain-loaf', 'status' => ProductStatus::Published]);

    $result = CommerceReads::run($actor, $site, 'list_products', ['q' => 'Tart']);

    expect($result->ok)->toBeTrue()
        ->and(collect($result->data['products'])->pluck('slug')->all())
        ->toEqualCanonicalizing(['fig-walnut-tart', 'meyer-lemon-tart', 'almond-croissant']);

    $twoWords = CommerceReads::run($actor, $site, 'list_products', ['q' => 'lemon tart']);

    expect(collect($twoWords->data['products'])->pluck('slug')->all())->toBe(['meyer-lemon-tart']);
});
