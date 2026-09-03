<?php

use App\Enums\Shop\ProductStatus;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\Category;
use App\Models\Shop\InventoryMovement;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopDraft;
use App\Models\Shop\TaxClass;
use App\Models\Shop\VariantStock;
use App\Models\Site\EditorOperationLog;
use App\Services\Shop\ProductSearchService;
use App\Services\Shop\StockService;
use App\Services\Site\Editor\Operations\ShopUpdateDraftProductOperation;
use Database\Seeders\Shop\TaxClassSeeder;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\Support\CommerceReads;

beforeEach(function () {
    CommerceReads::enableFlags();
    $this->seed(TaxClassSeeder::class);
});

/**
 * @return array{0: \App\Models\User, 1: \App\Models\Site, 2: Product, 3: ProductVariant}
 */
function updateDraftProductFixture(): array
{
    [$actor, $site, $category] = CommerceReads::shopSite();
    $product = Product::factory()->for($site)->create([
        'name' => 'Original Candle',
        'slug' => 'original-candle',
        'description' => 'Original copy.',
    ]);
    $product->categories()->attach($category->id, ['is_primary' => true]);
    $variant = ProductVariant::factory()->for($product)->create([
        'sku' => 'CNDL-DEF',
        'label' => 'Default',
        'price_cents' => 1299,
    ]);
    app(StockService::class)->initialiseVariant($variant->id);

    return [$actor, $site, $product, $variant];
}

it('refuses published and archived products with published_product_immutable and writes nothing', function (ProductStatus $status) {
    [$actor, $site, $product] = updateDraftProductFixture();
    $product->update(['status' => $status, 'name' => 'Frozen']);
    Bus::fake();

    $result = CommerceReads::run($actor, $site, 'update_draft_product', [
        'catalogue_revision' => 0,
        'slug' => 'original-candle',
        'product_revision' => (int) $product->revision,
        'name' => 'Mutated',
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('published_product_immutable')
        ->and($product->fresh()->name)->toBe('Frozen');
    Bus::assertNothingDispatched();
})->with([
    'published' => ProductStatus::Published,
    'archived' => ProductStatus::Archived,
]);

it('catches a human publish between the agent read and write under the row lock', function () {
    [$actor, $site, $product] = updateDraftProductFixture();
    $this->actingAs($actor);

    $read = CommerceReads::run($actor, $site, 'get_product', ['slug' => 'original-candle']);
    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->call('publish');

    $write = CommerceReads::run($actor, $site, 'update_draft_product', [
        'catalogue_revision' => $read->data['catalogue_revision'],
        'slug' => 'original-candle',
        'product_revision' => $read->data['revision'],
        'name' => 'Agent Name',
    ]);

    expect($write->ok)->toBeFalse()
        ->and($write->error['code'])->toBe('stale_revision')
        ->and($product->fresh()->status)->toBe(ProductStatus::Published)
        ->and($product->fresh()->name)->toBe('Original Candle');
});

it('returns stale_revision and keeps merchant text when a human editor saves between read and write', function () {
    [$actor, $site, $product] = updateDraftProductFixture();
    $this->actingAs($actor);

    $read = CommerceReads::run($actor, $site, 'get_product', ['slug' => 'original-candle']);
    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->set('name', 'Merchant Name')
        ->call('save');

    $write = CommerceReads::run($actor, $site, 'update_draft_product', [
        'catalogue_revision' => $read->data['catalogue_revision'],
        'slug' => 'original-candle',
        'product_revision' => $read->data['revision'],
        'name' => 'Agent Name',
    ]);

    expect($write->ok)->toBeFalse()
        ->and($write->error['code'])->toBe('stale_revision')
        ->and($product->fresh()->name)->toBe('Merchant Name');
});

it('does not delete a variant omitted from variants and appends a new sku with stock', function () {
    [$actor, $site, $product, $variant] = updateDraftProductFixture();

    $result = CommerceReads::run($actor, $site, 'update_draft_product', [
        'catalogue_revision' => 0,
        'product_id' => $product->id,
        'product_revision' => (int) $product->revision,
        'variants' => [
            ['sku' => 'CNDL-LG', 'label' => 'Large', 'price_pence' => 1899],
        ],
    ]);

    $product->refresh();
    $skus = $product->variants()->pluck('sku')->sort()->values()->all();
    $new = $product->variants()->where('sku', 'CNDL-LG')->first();

    expect($result->ok)->toBeTrue()
        ->and($skus)->toBe(['CNDL-DEF', 'CNDL-LG'])
        ->and((int) $variant->fresh()->price_cents)->toBe(1299)
        ->and((int) VariantStock::query()->where('variant_id', $new->id)->value('on_hand'))->toBe(0)
        ->and(InventoryMovement::query()->where('variant_id', $new->id)->count())->toBe(0);
});

it('holds the drafts-law quintet and never projects the updated draft publicly', function () {
    [$actor, $site, $product] = updateDraftProductFixture();
    $published = Product::factory()->for($site)->published()->create(['slug' => 'live-candle', 'name' => 'Live Candle']);
    $published->categories()->attach(Category::query()->where('site_id', $site->id)->value('id'), ['is_primary' => true]);
    CommerceReads::drainRebuild($site->id);

    $statusesBefore = Product::query()->where('site_id', $site->id)->orderBy('id')->pluck('status', 'id')->all();
    $projectionBefore = CommerceReads::publicProjection($site);
    $pointerBefore = $projectionBefore['_pointer_version'];
    unset($projectionBefore['_pointer_version']);
    $tablesBefore = CommerceReads::commerceSideTableCounts();
    $stockBefore = VariantStock::query()->count();

    $result = CommerceReads::run($actor, $site, 'update_draft_product', [
        'catalogue_revision' => 0,
        'slug' => 'original-candle',
        'product_revision' => (int) $product->revision,
        'name' => 'Secret Agent Draft ZQX',
    ]);
    CommerceReads::drainRebuild($site->id);
    $projectionAfter = CommerceReads::publicProjection($site);
    $pointerAfter = $projectionAfter['_pointer_version'];
    unset($projectionAfter['_pointer_version']);

    expect($result->ok)->toBeTrue()
        ->and($product->fresh()->status)->toBe(ProductStatus::Draft)
        ->and(Product::query()->where('site_id', $site->id)->whereIn('id', array_keys($statusesBefore))->orderBy('id')->pluck('status', 'id')->all())->toBe($statusesBefore)
        ->and($projectionAfter)->toBe($projectionBefore)
        ->and($pointerAfter)->toBeGreaterThanOrEqual($pointerBefore)
        ->and(CommerceReads::commerceSideTableCounts())->toBe($tablesBefore)
        ->and(VariantStock::query()->count())->toBe($stockBefore)
        ->and($projectionAfter['products'])->not->toHaveKey($product->slug)
        ->and(app(ProductSearchService::class)->search($site->id, 'Secret Agent Draft ZQX', false, 10)->modelKeys())->not->toContain($product->id);
});

it('bumps shop_products.revision and shop_drafts.catalogue_revision', function () {
    [$actor, $site, $product] = updateDraftProductFixture();

    $result = CommerceReads::run($actor, $site, 'update_draft_product', [
        'catalogue_revision' => 0,
        'slug' => 'original-candle',
        'product_revision' => 0,
        'name' => 'Renamed Candle',
        'description' => 'Updated copy.',
        'tax_class_code' => 'standard',
    ]);

    $product->refresh();
    $catalogue = (int) ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision');
    $receipt = $result->toArray()['receipt'];

    expect($result->ok)->toBeTrue()
        ->and((int) $product->revision)->toBe(1)
        ->and($catalogue)->toBe(1)
        ->and($receipt['new_revision'])->toBe(1)
        ->and($result->data['revision'])->toBe(1)
        ->and($product->name)->toBe('Renamed Candle')
        ->and($product->description)->toBe('Updated copy.')
        ->and($product->tax_class_id)->toBe(TaxClass::query()->where('code', 'standard')->value('id'))
        ->and(collect($receipt['changed'])->firstWhere('path', 'name'))->toMatchArray([
            'scope' => 'product',
            'product_slug' => 'original-candle',
            'path' => 'name',
            'before' => 'Original Candle',
            'after' => 'Renamed Candle',
        ])
        ->and($receipt['effective']['name'])->toBe('Renamed Candle')
        ->and($receipt['publishable'])->toBeFalse()
        ->and($result->state->pageId)->toBeNull()
        ->and($result->state->draftRevisionId)->toBeNull()
        ->and($result->state->structureEpoch)->toBeNull();
});

it('records subject_type product and subject_ref slug on a successful update_draft_product audit row', function () {
    [$actor, $site, $product] = updateDraftProductFixture();

    $result = CommerceReads::run($actor, $site, 'update_draft_product', [
        'catalogue_revision' => 0,
        'slug' => 'original-candle',
        'product_revision' => (int) $product->revision,
        'name' => 'Renamed For Audit',
    ]);
    $row = EditorOperationLog::query()->where('site_id', $site->id)->where('operation', 'update_draft_product')->latest('id')->first();

    expect($result->ok)->toBeTrue()
        ->and($row->result_code)->toBe('ok')
        ->and($row->subject_type)->toBe('product')
        ->and($row->subject_ref)->toBe('original-candle');
});

it('dispatches exactly one RebuildShopSnapshot after commit and none when the write fails', function () {
    [$actor, $site, $product] = updateDraftProductFixture();
    Bus::fake();

    $failed = CommerceReads::run($actor, $site, 'update_draft_product', [
        'catalogue_revision' => 0,
        'slug' => 'original-candle',
        'product_revision' => 0,
        'variants' => [['sku' => 'BAD', 'price_pence' => 0]],
    ]);
    Bus::assertNothingDispatched();

    $ok = CommerceReads::run($actor, $site, 'update_draft_product', [
        'catalogue_revision' => 0,
        'slug' => 'original-candle',
        'product_revision' => 0,
        'name' => 'After Fail',
    ]);

    expect($failed->ok)->toBeFalse()
        ->and($ok->ok)->toBeTrue();
    Bus::assertDispatchedTimes(RebuildShopSnapshot::class, 1);
});

it('syncs extra_category_ids with exactly one primary', function () {
    [$actor, $site, $product] = updateDraftProductFixture();
    $primary = Category::query()->where('site_id', $site->id)->first();
    $extra = Category::factory()->for($site)->create(['slug' => 'gift-boxes', 'name' => 'Gift boxes']);

    $result = CommerceReads::run($actor, $site, 'update_draft_product', [
        'catalogue_revision' => 0,
        'slug' => 'original-candle',
        'product_revision' => (int) $product->revision,
        'primary_category_id' => $primary->id,
        'extra_category_ids' => [$extra->id, $primary->id],
    ]);

    $attached = $product->fresh()->categories()->get()->keyBy('id');

    expect($result->ok)->toBeTrue()
        ->and($attached)->toHaveCount(2)
        ->and((bool) $attached[$primary->id]->pivot->is_primary)->toBeTrue()
        ->and((bool) $attached[$extra->id]->pivot->is_primary)->toBeFalse()
        ->and($attached->where('pivot.is_primary', true))->toHaveCount(1);
});

it('is a shop-addressed unwrapped write', function () {
    $operation = app(ShopUpdateDraftProductOperation::class);

    expect($operation->address())->toBe('shop')
        ->and($operation->readOnly())->toBeFalse()
        ->and($operation->wrapInAdminChange())->toBeFalse();
});

it('rejects category ids that belong to another site', function () {
    [$actor, $site, $product] = updateDraftProductFixture();
    $other = \App\Models\Site::factory()->create();
    $foreign = Category::factory()->for($other)->create(['slug' => 'foreign', 'name' => 'Foreign']);
    $primary = Category::query()->where('site_id', $site->id)->first();

    $result = CommerceReads::run($actor, $site, 'update_draft_product', [
        'catalogue_revision' => 0,
        'slug' => 'original-candle',
        'product_revision' => (int) $product->revision,
        'primary_category_id' => $primary->id,
        'extra_category_ids' => [$foreign->id],
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($result->error['message'] ?? '')->toContain('do not belong to this site');
    expect($product->fresh()->categories()->where('shop_categories.site_id', $other->id)->exists())->toBeFalse();
});

it('accepts weight_grams on an existing variant and keeps the optimistic lock', function () {
    [$actor, $site, $product, $variant] = updateDraftProductFixture();
    $schema = app(ShopUpdateDraftProductOperation::class)->inputSchema();

    expect($schema['properties']['variants']['items']['properties']['weight_grams'])->toMatchArray([
        'type' => ['integer', 'null'],
        'minimum' => 0,
        'maximum' => 100000,
    ]);

    $result = CommerceReads::run($actor, $site, 'update_draft_product', [
        'catalogue_revision' => 0,
        'slug' => 'original-candle',
        'product_revision' => (int) $product->revision,
        'variants' => [
            ['sku' => 'CNDL-DEF', 'label' => 'Default', 'price_pence' => 1299, 'weight_grams' => 275],
        ],
    ]);

    expect($result->ok)->toBeTrue()
        ->and($variant->fresh()->weight_grams)->toBe(275)
        ->and((int) $product->fresh()->revision)->toBe(1);
});

it('rejects update_draft_product weight_grams outside 0..100000', function (mixed $weight) {
    [$actor, $site, $product] = updateDraftProductFixture();

    $result = CommerceReads::run($actor, $site, 'update_draft_product', [
        'catalogue_revision' => 0,
        'slug' => 'original-candle',
        'product_revision' => (int) $product->revision,
        'variants' => [
            ['sku' => 'CNDL-DEF', 'price_pence' => 1299, 'weight_grams' => $weight],
        ],
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($product->fresh()->variants()->first()->weight_grams)->toBeNull();
})->with([
    'negative' => -1,
    'too heavy' => 100001,
]);

it('accepts known tags on update_draft_product and rejects unknown slugs listing valid ones', function () {
    [$actor, $site, $product] = updateDraftProductFixture();
    $site->update([
        'product_tags' => [
            ['slug' => 'seasonal', 'label' => 'Seasonal', 'show_as_badge' => true, 'tone' => 'warning'],
            ['slug' => 'gift', 'label' => 'Gift', 'show_as_badge' => false, 'tone' => 'neutral'],
        ],
    ]);

    $ok = CommerceReads::run($actor, $site, 'update_draft_product', [
        'catalogue_revision' => 0,
        'slug' => 'original-candle',
        'product_revision' => (int) $product->revision,
        'tags' => ['seasonal'],
    ]);

    expect($ok->ok)->toBeTrue()
        ->and($product->fresh()->tags)->toBe(['seasonal']);

    $bad = CommerceReads::run($actor, $site, 'update_draft_product', [
        'catalogue_revision' => (int) ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision'),
        'slug' => 'original-candle',
        'product_revision' => (int) $product->fresh()->revision,
        'tags' => ['nope'],
    ]);

    expect($bad->ok)->toBeFalse()
        ->and($bad->error['code'])->toBe('validation')
        ->and($bad->error['message'])->toContain('nope')
        ->and($bad->error['message'])->toContain('seasonal')
        ->and($product->fresh()->tags)->toBe(['seasonal']);
});
