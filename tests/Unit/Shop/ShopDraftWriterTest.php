<?php

use App\Enums\Shop\ProductStatus;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\Category;
use App\Models\Shop\InventoryMovement;
use App\Models\Shop\Product;
use App\Models\Shop\ProductImage;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Models\User;
use App\Observers\Shop\CatalogObserver;
use App\Services\Shop\ShopDraftWriter;
use App\Services\Shop\StockService;
use App\Support\Shop\ShopSlug;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

it('records the first publication timestamp when the editor publishes a product', function () {
    $site = Site::factory()->create();
    $product = Product::factory()->for($site)->create();
    $publishedAt = now()->startOfSecond();
    $this->travelTo($publishedAt);

    $result = app(ShopDraftWriter::class)->setStatusFromEditor($site, $product, ProductStatus::Published);

    expect($result['product']->status)->toBe(ProductStatus::Published)
        ->and($result['product']->published_at?->toIso8601String())->toBe($publishedAt->toIso8601String());
});

it('dispatches exactly one RebuildShopSnapshot after commit for a product plus variant plus image write', function () {
    $site = Site::factory()->create();
    $category = Category::factory()->for($site)->create();
    Bus::fake();

    $result = app(ShopDraftWriter::class)->createDraft($site, [
        'name' => 'Hand-poured Candle',
        'slug' => 'hand-poured-candle',
        'description' => 'Soy wax.',
        'category_id' => $category->id,
        'variants' => [
            ['sku' => 'CNDL-DEF', 'label' => 'Default', 'price_cents' => 1299],
        ],
        'images' => [
            ['path' => 'shop/products/candle.png', 'sort_order' => 0, 'alt' => 'Candle'],
        ],
    ]);

    Bus::assertNotDispatched(RebuildShopSnapshot::class);

    ($result['deferred'])();

    Bus::assertDispatchedTimes(RebuildShopSnapshot::class, 1);
    Bus::assertDispatched(RebuildShopSnapshot::class, function (RebuildShopSnapshot $job) use ($site): bool {
        return $job->siteId === $site->id && $job->afterCommit === true;
    });

    $product = Product::query()->where('site_id', $site->id)->where('slug', 'hand-poured-candle')->first();
    expect($product)->not->toBeNull()
        ->and($product->variants)->toHaveCount(1)
        ->and($product->images)->toHaveCount(1)
        ->and(VariantStock::query()->where('variant_id', $product->variants->first()->id)->value('on_hand'))->toBe(0)
        ->and(InventoryMovement::query()->where('variant_id', $product->variants->first()->id)->count())->toBe(0)
        ->and($result['product']->is($product))->toBeTrue();
});

it('dispatches no rebuild when a write fails', function () {
    Bus::fake();
    $site = Site::factory()->create();

    expect(fn () => app(ShopDraftWriter::class)->write($site, [], null, function () use ($site): void {
        Product::factory()->for($site)->create(['name' => 'Rolled Back']);
        throw new RuntimeException('write failed');
    }))->toThrow(RuntimeException::class, 'write failed');

    Bus::assertNothingDispatched();
    expect(Product::query()->where('site_id', $site->id)->where('name', 'Rolled Back')->exists())->toBeFalse();
});

it('keeps CatalogObserver muted across nested write calls and dispatches one rebuild', function () {
    Bus::fake();
    $site = Site::factory()->create();

    $outer = app(ShopDraftWriter::class)->write($site, [], null, function () use ($site): void {
        $inner = app(ShopDraftWriter::class)->write($site, [], null, function () use ($site): void {
            Product::factory()->for($site)->create(['name' => 'Inner']);
        });
        expect(CatalogObserver::$muted)->toBeTrue();
        ($inner)();
        Product::factory()->for($site)->create(['name' => 'Outer']);
    });

    expect(CatalogObserver::$muted)->toBeFalse();
    Bus::assertNothingDispatched();
    ($outer)();
    Bus::assertDispatchedTimes(RebuildShopSnapshot::class, 1);
});

it('leaves the collector un-muted after a throw inside the transaction', function () {
    Bus::fake();
    $site = Site::factory()->create();

    try {
        app(ShopDraftWriter::class)->write($site, [], null, function () use ($site): void {
            Product::factory()->for($site)->create(['name' => 'Poison']);
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    expect(CatalogObserver::$muted)->toBeFalse();

    Product::factory()->for($site)->create(['name' => 'After Unmute']);

    Bus::assertDispatched(RebuildShopSnapshot::class, fn (RebuildShopSnapshot $job): bool => $job->siteId === $site->id);
});

it('still lets a human product-editor save dispatch its own rebuild', function () {
    Bus::fake();
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);

    $result = app(ShopDraftWriter::class)->createDraft($site, [
        'name' => 'Draft Candle',
        'slug' => 'draft-candle',
        'variants' => [
            ['sku' => 'CNDL-1', 'price_cents' => 500],
        ],
    ]);
    ($result['deferred'])();

    Bus::assertDispatchedTimes(RebuildShopSnapshot::class, 1);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $result['product']->id])
        ->set('name', 'Human Name')
        ->call('save');

    Bus::assertDispatchedTimes(RebuildShopSnapshot::class, 2);
    expect($result['product']->fresh()->name)->toBe('Human Name');
});

it('bumps shop_drafts.catalogue_revision and persists tax class and agent_tool seed flags on create', function () {
    $site = Site::factory()->create();
    $category = Category::factory()->for($site)->create();
    $this->seed(\Database\Seeders\Shop\TaxClassSeeder::class);
    $taxClassId = (int) \App\Models\Shop\TaxClass::query()->where('code', 'standard')->value('id');
    Bus::fake();

    $result = app(ShopDraftWriter::class)->createDraft($site, [
        'name' => 'Seeded Candle',
        'slug' => 'seeded-candle',
        'category_id' => $category->id,
        'tax_class_id' => $taxClassId,
        'is_ai_seeded' => true,
        'is_ai_reviewed' => false,
        'ai_seed_source' => 'agent_tool',
        'variants' => [
            ['sku' => 'SEED-1', 'price_cents' => 999],
        ],
    ]);
    ($result['deferred'])();

    $product = $result['product']->fresh();
    expect((int) \App\Models\Shop\ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision'))->toBe(1)
        ->and($product->tax_class_id)->toBe($taxClassId)
        ->and($product->status->value)->toBe('draft')
        ->and($product->is_ai_seeded)->toBeTrue()
        ->and($product->is_ai_reviewed)->toBeFalse()
        ->and($product->ai_seed_source)->toBe('agent_tool');
});

it('updates a draft without deleting omitted variants and initialises stock for a new sku', function () {
    $site = Site::factory()->create();
    $created = app(ShopDraftWriter::class)->createDraft($site, [
        'name' => 'Keep Me',
        'slug' => 'keep-me',
        'variants' => [
            ['sku' => 'KEEP-1', 'price_cents' => 500],
        ],
    ]);
    ($created['deferred'])();
    $product = $created['product'];

    $updated = app(ShopDraftWriter::class)->updateDraft($site, $product, [
        'name' => 'Kept',
        'variants' => [
            ['sku' => 'NEW-1', 'label' => 'New', 'price_cents' => 700],
        ],
    ]);
    ($updated['deferred'])();

    $product->refresh();
    $new = $product->variants()->where('sku', 'NEW-1')->first();
    expect($product->name)->toBe('Kept')
        ->and($product->variants()->pluck('sku')->sort()->values()->all())->toBe(['KEEP-1', 'NEW-1'])
        ->and((int) $product->revision)->toBe(2)
        ->and((int) \App\Models\Shop\ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision'))->toBe(2)
        ->and((int) VariantStock::query()->where('variant_id', $new->id)->value('on_hand'))->toBe(0)
        ->and(InventoryMovement::query()->where('variant_id', $new->id)->count())->toBe(0);
});

it('attaches a product image using the media s3_key path and bumps catalogue_revision', function () {
    $site = Site::factory()->create();
    $created = app(ShopDraftWriter::class)->createDraft($site, [
        'name' => 'With Image',
        'slug' => 'with-image',
        'variants' => [
            ['sku' => 'IMG-1', 'price_cents' => 400],
        ],
    ]);
    ($created['deferred'])();
    $before = (int) \App\Models\Shop\ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision');

    $attached = app(ShopDraftWriter::class)->attachImage($site, $created['product'], [
        'path' => 'shop/products/with-image.png',
        'sort_order' => 0,
        'alt' => 'Candle',
    ]);
    ($attached['deferred'])();

    expect($created['product']->fresh()->images)->toHaveCount(1)
        ->and($created['product']->fresh()->images->first()->path)->toBe('shop/products/with-image.png')
        ->and((int) \App\Models\Shop\ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision'))->toBe($before + 1);
});

it('reports unknown tag slugs as a tags field error rather than UnknownProductTagsException', function () {
    $site = Site::factory()->create([
        'product_tags' => [
            ['slug' => 'seasonal', 'label' => 'Seasonal', 'show_as_badge' => true, 'tone' => 'warning'],
        ],
    ]);
    $product = Product::factory()->for($site)->create(['name' => 'Old', 'tags' => ['seasonal']]);

    try {
        app(ShopDraftWriter::class)->saveFromEditor($site, $product, [
            'name' => 'New',
            'description' => $product->description,
            'tax_class_id' => $product->tax_class_id,
            'tags' => ['gift'],
            'revision' => (int) $product->revision,
        ]);
        expect(false)->toBeTrue();
    } catch (\Illuminate\Validation\ValidationException $exception) {
        expect($exception->errors())->toHaveKey('tags')
            ->and(collect($exception->errors()['tags'])->implode(' '))->toContain('gift')
            ->and(collect($exception->errors()['tags'])->implode(' '))->toContain('seasonal');
    } catch (\App\Exceptions\Shop\UnknownProductTagsException) {
        expect(false)->toBeTrue('unknown slugs must not abort the write as UnknownProductTagsException');
    }
});

it('initialises variant stock at on_hand 0 with no inventory movement, idempotently', function () {
    $variant = ProductVariant::factory()->create();
    $stock = app(StockService::class);

    $stock->initialiseVariant($variant->id);
    $stock->initialiseVariant($variant->id);

    expect(VariantStock::query()->where('variant_id', $variant->id)->count())->toBe(1)
        ->and((int) VariantStock::query()->where('variant_id', $variant->id)->value('on_hand'))->toBe(0)
        ->and(InventoryMovement::query()->where('variant_id', $variant->id)->count())->toBe(0);
});

it('allocates a product slug from the name inside the site lock', function () {
    $site = Site::factory()->create();

    $result = app(ShopDraftWriter::class)->createDraft($site, [
        'name' => 'Race Cake',
        'variants' => [
            ['sku' => 'RACE-1', 'price_cents' => 100],
        ],
    ]);

    expect($result['product']->slug)->toBe('race-cake')
        ->and($result['product']->name)->toBe('Race Cake');
});

it('retries slug allocation with a suffix when the clean slug is taken between compute and insert', function () {
    $site = Site::factory()->create();
    ShopSlug::$afterAllocate = function (int $siteId, string $slug) use ($site): void {
        Product::factory()->for($site)->create([
            'slug' => $slug,
            'name' => 'Collider',
        ]);
    };

    $result = app(ShopDraftWriter::class)->createDraft($site, [
        'name' => 'Race Cake',
        'variants' => [
            ['sku' => 'RACE-1', 'price_cents' => 100],
        ],
    ]);

    expect($result['product']->slug)->toBe('race-cake-2')
        ->and(Product::query()->where('site_id', $site->id)->where('slug', 'race-cake')->value('name'))->toBe('Collider');
});
