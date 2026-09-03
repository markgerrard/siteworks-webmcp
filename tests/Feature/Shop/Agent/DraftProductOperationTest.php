<?php

use App\Enums\Shop\ProductStatus;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Client;
use App\Models\Shop\Category;
use App\Models\Shop\InventoryMovement;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopDraft;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Shop\TaxClass;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Models\Site\EditorOperationLog;
use App\Models\User;
use App\Services\Shop\ProductSearchService;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\Operations\ShopDraftProductOperation;
use Database\Seeders\Shop\TaxClassSeeder;
use Illuminate\Support\Facades\Bus;
use Tests\Support\CommerceReads;

beforeEach(function () {
    CommerceReads::enableFlags();
    $this->seed(TaxClassSeeder::class);
});

it('forces status to draft and never accepts status as an input', function () {
    [$actor, $site] = CommerceReads::shopSite();
    $schema = app(ShopDraftProductOperation::class)->inputSchema();

    $result = CommerceReads::run($actor, $site, 'draft_product', CommerceReads::draftProductInput([
        'status' => 'published',
    ]));

    expect($schema['properties'])->not->toHaveKey('status')
        ->and($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and(Product::query()->where('site_id', $site->id)->exists())->toBeFalse();

    $created = CommerceReads::run($actor, $site, 'draft_product', CommerceReads::draftProductInput());

    expect($created->ok)->toBeTrue()
        ->and(Product::query()->where('site_id', $site->id)->first()->status)->toBe(ProductStatus::Draft);
});

it('rejects a caller-supplied slug and generates the slug on the server', function () {
    [$actor, $site] = CommerceReads::shopSite();
    $schema = app(ShopDraftProductOperation::class)->inputSchema();

    $refused = CommerceReads::run($actor, $site, 'draft_product', CommerceReads::draftProductInput([
        'slug' => 'caller-chosen',
    ]));

    expect($schema['properties'])->not->toHaveKey('slug')
        ->and($refused->ok)->toBeFalse()
        ->and($refused->error['code'])->toBe('validation')
        ->and(Product::query()->where('slug', 'caller-chosen')->exists())->toBeFalse();

    $created = CommerceReads::run($actor, $site, 'draft_product', CommerceReads::draftProductInput());
    $product = Product::query()->where('site_id', $site->id)->first();

    expect($created->ok)->toBeTrue()
        ->and($product->slug)->toBe('hand-poured-candle')
        ->and($created->data['slug'])->toBe($product->slug);
});

it('accepts weight_grams on a variant and persists it', function () {
    [$actor, $site] = CommerceReads::shopSite();
    $schema = app(ShopDraftProductOperation::class)->inputSchema();

    expect($schema['properties']['variants']['items']['properties']['weight_grams'])->toMatchArray([
        'type' => ['integer', 'null'],
        'minimum' => 0,
        'maximum' => 100000,
    ]);

    $result = CommerceReads::run($actor, $site, 'draft_product', CommerceReads::draftProductInput([
        'variants' => [
            ['sku' => 'CNDL-DEF', 'label' => 'Default', 'price_pence' => 1299, 'weight_grams' => 420],
        ],
    ]));

    $product = Product::query()->where('site_id', $site->id)->first();

    expect($result->ok)->toBeTrue()
        ->and($product->variants()->first()->weight_grams)->toBe(420);
});

it('rejects weight_grams below 0 and above 100000', function (mixed $weight) {
    [$actor, $site] = CommerceReads::shopSite();

    $result = CommerceReads::run($actor, $site, 'draft_product', CommerceReads::draftProductInput([
        'variants' => [['sku' => 'CNDL-DEF', 'price_pence' => 1299, 'weight_grams' => $weight]],
    ]));

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and(Product::query()->where('site_id', $site->id)->exists())->toBeFalse();
})->with([
    'negative' => -1,
    'too heavy' => 100001,
]);

it('rejects non-integer and non-positive price_pence values', function (mixed $price) {
    [$actor, $site] = CommerceReads::shopSite();
    Bus::fake();

    $result = CommerceReads::run($actor, $site, 'draft_product', CommerceReads::draftProductInput([
        'variants' => [['sku' => 'CNDL-DEF', 'price_pence' => $price]],
    ]));

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and(Product::query()->where('site_id', $site->id)->exists())->toBeFalse();
    Bus::assertNothingDispatched();
})->with([
    'decimal-string' => '12.99',
    'float' => 12.99,
    'zero' => 0,
    'negative' => -1,
    'null' => null,
    'integer-string' => '12',
]);

it('initialises a zero on_hand stock row per variant with no inventory_movements row', function () {
    [$actor, $site] = CommerceReads::shopSite();

    $result = CommerceReads::run($actor, $site, 'draft_product', CommerceReads::draftProductInput([
        'variants' => [
            ['sku' => 'CNDL-DEF', 'label' => 'Default', 'price_pence' => 1299],
            ['sku' => 'CNDL-LG', 'label' => 'Large', 'price_pence' => 1899],
        ],
    ]));

    $product = Product::query()->where('site_id', $site->id)->where('slug', $result->data['slug'])->first();
    $variantIds = $product->variants()->pluck('id');

    expect($result->ok)->toBeTrue()
        ->and($variantIds)->toHaveCount(2)
        ->and(VariantStock::query()->whereIn('variant_id', $variantIds)->count())->toBe(2)
        ->and(VariantStock::query()->whereIn('variant_id', $variantIds)->pluck('on_hand')->unique()->all())->toBe([0])
        ->and(InventoryMovement::query()->whereIn('variant_id', $variantIds)->count())->toBe(0);
});

it('allowlists tax_class_code against tax_classes with no fallback', function () {
    [$actor, $site] = CommerceReads::shopSite();
    expect(TaxClass::query()->where('code', 'standard')->exists())->toBeTrue();

    $missing = CommerceReads::run($actor, $site, 'draft_product', CommerceReads::draftProductInput([
        'tax_class_code' => 'not-a-class',
    ]));

    expect($missing->ok)->toBeFalse()
        ->and($missing->error['code'])->toBe('validation')
        ->and(Product::query()->where('site_id', $site->id)->exists())->toBeFalse();

    $ok = CommerceReads::run($actor, $site, 'draft_product', CommerceReads::draftProductInput([
        'tax_class_code' => 'standard',
    ]));
    $product = Product::query()->where('site_id', $site->id)->first();

    expect($ok->ok)->toBeTrue()
        ->and($product->tax_class_id)->toBe(TaxClass::query()->where('code', 'standard')->value('id'));
});

it('sets is_ai_seeded true, is_ai_reviewed false, and ai_seed_source agent_tool on agent channels', function () {
    [$actor, $site] = CommerceReads::shopSite();
    CommerceReads::exposeOnSandbox('draft_product');

    $result = CommerceReads::run(
        $actor,
        $site,
        'draft_product',
        CommerceReads::draftProductInput(),
        ActorChannel::Webmcp,
    );
    $product = Product::query()->where('site_id', $site->id)->first();

    expect($result->ok)->toBeTrue()
        ->and($product->is_ai_seeded)->toBeTrue()
        ->and($product->is_ai_reviewed)->toBeFalse()
        ->and($product->ai_seed_source)->toBe('agent_tool');
});

it('does not stamp is_ai_seeded on a ui-channel create', function () {
    [$actor, $site] = CommerceReads::shopSite();

    $result = CommerceReads::run(
        $actor,
        $site,
        'draft_product',
        CommerceReads::draftProductInput(),
        ActorChannel::Ui,
    );
    $product = Product::query()->where('site_id', $site->id)->first();

    expect($result->ok)->toBeTrue()
        ->and($product->is_ai_seeded)->toBeFalse()
        ->and($product->is_ai_reviewed)->toBeFalse()
        ->and($product->ai_seed_source)->toBeNull();
});

it('holds the drafts-law quintet and never projects the new draft publicly', function () {
    [$actor, $site] = CommerceReads::shopSite();
    $published = Product::factory()->for($site)->published()->create(['slug' => 'live-candle', 'name' => 'Live Candle']);
    $published->categories()->attach(Category::query()->where('site_id', $site->id)->value('id'), ['is_primary' => true]);
    (new RebuildShopSnapshot($site->id))->handle(app(\App\Services\Shop\SnapshotBuilder::class));

    $statusesBefore = Product::query()->where('site_id', $site->id)->pluck('status', 'id')->all();
    $projectionBefore = CommerceReads::publicProjection($site);
    $pointerBefore = $projectionBefore['_pointer_version'];
    unset($projectionBefore['_pointer_version']);
    $tablesBefore = CommerceReads::commerceSideTableCounts();
    $stockBefore = VariantStock::query()->count();

    $result = CommerceReads::run($actor, $site, 'draft_product', CommerceReads::draftProductInput());
    CommerceReads::drainRebuild($site->id);
    $product = Product::query()->where('site_id', $site->id)->where('slug', $result->data['slug'])->first();
    $projectionAfter = CommerceReads::publicProjection($site);
    $pointerAfter = $projectionAfter['_pointer_version'];
    unset($projectionAfter['_pointer_version']);

    expect($result->ok)->toBeTrue()
        ->and($product->status)->toBe(ProductStatus::Draft)
        ->and(Product::query()->where('site_id', $site->id)->whereIn('id', array_keys($statusesBefore))->pluck('status', 'id')->all())->toBe($statusesBefore)
        ->and($projectionAfter)->toBe($projectionBefore)
        ->and($pointerAfter)->toBeGreaterThanOrEqual($pointerBefore)
        ->and(CommerceReads::commerceSideTableCounts())->toBe($tablesBefore)
        ->and(VariantStock::query()->count())->toBe($stockBefore + 1)
        ->and($projectionAfter['products'])->not->toHaveKey($product->slug)
        ->and(app(ProductSearchService::class)->search($site->id, 'Hand-poured', false, 10)->modelKeys())->not->toContain($product->id);
});

it('does not flip a published product in_stock_any when an agent creates a draft', function () {
    [$actor, $site, $category] = CommerceReads::shopSite();
    $published = Product::factory()->for($site)->published()->create(['slug' => 'live-jam', 'name' => 'Live Jam']);
    $published->categories()->attach($category->id, ['is_primary' => true]);
    $variant = ProductVariant::factory()->for($published)->create(['sku' => 'JAM-1', 'price_cents' => 500]);
    VariantStock::query()->create(['variant_id' => $variant->id, 'on_hand' => 5, 'updated_at' => now()]);
    CommerceReads::drainRebuild($site->id);

    $before = CommerceReads::publicProjection($site);
    expect($before['products']['live-jam']['in_stock_any'])->toBeTrue();

    $result = CommerceReads::run($actor, $site, 'draft_product', CommerceReads::draftProductInput());
    CommerceReads::drainRebuild($site->id);
    $after = CommerceReads::publicProjection($site);

    expect($result->ok)->toBeTrue()
        ->and($after['products']['live-jam']['in_stock_any'])->toBe($before['products']['live-jam']['in_stock_any'])
        ->and($after['products'])->not->toHaveKey($result->data['slug']);
});

it('returns not_found on a site with no shop and creates no shop_snapshot_current row', function () {
    $actor = User::factory()->staff(\App\Enums\AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $actor->id]);
    $before = EditorOperationLog::query()->count();

    $result = CommerceReads::run($actor, $site, 'draft_product', CommerceReads::draftProductInput());

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('not_found')
        ->and(Product::query()->where('site_id', $site->id)->exists())->toBeFalse()
        ->and(ShopSnapshotCurrent::query()->where('site_id', $site->id)->exists())->toBeFalse()
        ->and(EditorOperationLog::query()->count())->toBe($before + 1)
        ->and(CommerceReads::auditCount($site, 'draft_product', 'not_found'))->toBe(1);
});

it('returns a shop receipt whose new_revision is catalogue_revision and whose state is the null shape', function () {
    [$actor, $site] = CommerceReads::shopSite();

    $result = CommerceReads::run($actor, $site, 'draft_product', CommerceReads::draftProductInput());
    $product = Product::query()->where('site_id', $site->id)->first();
    $catalogue = (int) ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision');
    $receipt = $result->toArray()['receipt'];
    $encoded = json_encode($result->toArray());

    expect($result->ok)->toBeTrue()
        ->and($catalogue)->toBe(1)
        ->and($receipt['new_revision'])->toBe($catalogue)
        ->and($receipt['publishable'])->toBeFalse()
        ->and($result->data['revision'])->toBe((int) $product->revision)
        ->and($receipt['effective']['slug'])->toBe($product->slug)
        ->and($receipt['effective']['status'])->toBe(ProductStatus::Draft->value)
        ->and($receipt['effective']['variants'][0]['price_pence'])->toBe(1299)
        ->and($receipt['changed'][0]['scope'])->toBe('product')
        ->and($receipt['changed'][0]['product_slug'])->toBe($product->slug)
        ->and($receipt['changed'][0])->toHaveKeys(['path', 'before', 'after', 'kind'])
        ->and($result->state->pageId)->toBeNull()
        ->and($result->state->draftRevisionId)->toBeNull()
        ->and($result->state->structureEpoch)->toBeNull()
        ->and($encoded)->not->toContain('<html')
        ->and($result->data)->not->toHaveKey('html');
});

it('records subject_type product and subject_ref slug on a successful draft_product audit row', function () {
    [$actor, $site] = CommerceReads::shopSite();

    $result = CommerceReads::run($actor, $site, 'draft_product', CommerceReads::draftProductInput());
    $row = EditorOperationLog::query()->where('site_id', $site->id)->where('operation', 'draft_product')->latest('id')->first();

    expect($result->ok)->toBeTrue()
        ->and($row->result_code)->toBe('ok')
        ->and($row->subject_type)->toBe('product')
        ->and($row->subject_ref)->toBe($result->data['slug']);
});

it('dispatches exactly one RebuildShopSnapshot after commit and none when the write fails', function () {
    [$actor, $site] = CommerceReads::shopSite();
    Bus::fake();

    $failed = CommerceReads::run($actor, $site, 'draft_product', CommerceReads::draftProductInput([
        'variants' => [['sku' => 'CNDL-DEF', 'price_pence' => 0]],
    ]));
    Bus::assertNothingDispatched();

    $ok = CommerceReads::run($actor, $site, 'draft_product', CommerceReads::draftProductInput());

    expect($failed->ok)->toBeFalse()
        ->and($ok->ok)->toBeTrue();
    Bus::assertDispatchedTimes(RebuildShopSnapshot::class, 1);
    Bus::assertDispatched(RebuildShopSnapshot::class, function (RebuildShopSnapshot $job) use ($site): bool {
        return $job->siteId === $site->id && $job->afterCommit === true;
    });
});

it('is a shop-addressed unwrapped write that does not consult RenderContext::fromRequest', function () {
    $operation = app(ShopDraftProductOperation::class);
    $source = file_get_contents(app_path('Services/Site/Editor/Operations/ShopDraftProductOperation.php'));

    expect($operation->address())->toBe('shop')
        ->and($operation->readOnly())->toBeFalse()
        ->and($operation->wrapInAdminChange())->toBeFalse()
        ->and($source)->not->toContain('RenderContext::fromRequest');
});

it('writes one audit row and nothing else when the agent-tools flag is off', function () {
    [$actor, $site] = CommerceReads::shopSite();
    CommerceReads::exposeOnSandbox('draft_product');
    config(['editor.agent_tools.enabled' => false]);
    $before = EditorOperationLog::query()->count();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'draft_product',
        CommerceReads::draftProductInput(),
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('forbidden')
        ->and(Product::query()->where('site_id', $site->id)->exists())->toBeFalse()
        ->and(EditorOperationLog::query()->count())->toBe($before + 1)
        ->and(CommerceReads::auditCount($site, 'draft_product', 'forbidden'))->toBe(1);
});

it('writes one audit row and nothing else when the exposure set omits the operation', function () {
    [$actor, $site] = CommerceReads::shopSite();
    CommerceReads::omitFromSandbox('draft_product');
    $before = EditorOperationLog::query()->count();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'draft_product',
        CommerceReads::draftProductInput(),
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('not_found')
        ->and(Product::query()->where('site_id', $site->id)->exists())->toBeFalse()
        ->and(EditorOperationLog::query()->count())->toBe($before + 1)
        ->and(CommerceReads::auditCount($site, 'draft_product', 'not_found'))->toBe(1);
});

it('writes one audit row and nothing else when the actor role is denied', function () {
    $client = Client::factory()->create();
    $actor = User::factory()->create(['client_id' => $client->id, 'role' => null]);
    $site = Site::factory()->create(['client_id' => $client->id]);
    Category::factory()->for($site)->create(['slug' => 'candles']);
    CommerceReads::giveShop($site);
    CommerceReads::exposeOnSandbox('draft_product');
    $before = EditorOperationLog::query()->count();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'draft_product',
        CommerceReads::draftProductInput(),
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('forbidden')
        ->and(Product::query()->where('site_id', $site->id)->exists())->toBeFalse()
        ->and(EditorOperationLog::query()->count())->toBe($before + 1)
        ->and(CommerceReads::auditCount($site, 'draft_product', 'forbidden'))->toBe(1);
});
