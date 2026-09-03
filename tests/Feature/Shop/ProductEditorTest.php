<?php

use App\Enums\Shop\OrderStatus;
use App\Enums\Shop\ProductStatus;
use App\Models\Client;
use App\Models\Shop\Category;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Shop\ProductImage;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ProductVariantImage;
use App\Models\Shop\ShopDraft;
use App\Models\Shop\TaxClass;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Models\SiteMedia;
use App\Models\User;
use Database\Seeders\Shop\TaxClassSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{user: User, site: Site, product: Product}
 */
function productEditorStaffProduct(array $productAttrs = []): array
{
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    test()->actingAs($user);
    $product = Product::factory()->for($site)->create($productAttrs);

    return compact('user', 'site', 'product');
}

/**
 * @return list<string>
 */
function productEditorLockStatements(callable $write): array
{
    $locked = [];
    DB::listen(function (QueryExecuted $query) use (&$locked): void {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'for update')) {
            $locked[] = $sql;
        }
    });
    $write();

    return $locked;
}

test('can edit basic product fields and flip is_ai_reviewed when touched', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);

    $product = Product::factory()->for($site)->create([
        'name' => 'Old Name',
        'is_ai_seeded' => true,
        'is_ai_reviewed' => false,
    ]);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->set('name', 'New Name')
        ->set('description', 'A lovely product')
        ->call('save');

    $product->refresh();
    expect($product->name)->toBe('New Name');
    expect($product->description)->toBe('A lovely product');
    expect($product->is_ai_reviewed)->toBeTrue();
    expect((int) $product->revision)->toBe(1)
        ->and((int) ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision'))->toBe(1);
});

test('save locks shop_products then shop_drafts and bumps both revision counters', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $product = Product::factory()->for($site)->create(['name' => 'Lock Me']);

    $locked = productEditorLockStatements(function () use ($site, $product): void {
        Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
            ->set('name', 'Locked Name')
            ->call('save');
    });

    $productLocks = array_values(array_filter($locked, fn (string $sql): bool => str_contains($sql, 'shop_products')));
    $draftLocks = array_values(array_filter($locked, fn (string $sql): bool => str_contains($sql, 'shop_drafts')));

    expect($product->fresh()->name)->toBe('Locked Name')
        ->and((int) $product->fresh()->revision)->toBe(1)
        ->and((int) ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision'))->toBe(1)
        ->and($productLocks)->not->toBeEmpty()
        ->and($draftLocks)->not->toBeEmpty()
        ->and(array_search($productLocks[0], $locked, true))->toBeLessThan(array_search($draftLocks[0], $locked, true));
});

test('publishing a seeded draft does not auto-flip is_ai_reviewed', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);

    $product = Product::factory()->for($site)->create([
        'is_ai_seeded' => true,
        'is_ai_reviewed' => false,
        'status' => ProductStatus::Draft,
    ]);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->call('publish');

    $product->refresh();
    expect($product->status)->toBe(ProductStatus::Published);
    expect($product->is_ai_reviewed)->toBeFalse();
    expect((int) $product->revision)->toBe(1)
        ->and((int) ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision'))->toBe(1);
});

test('publish locks shop_products then shop_drafts and bumps both revision counters', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $product = Product::factory()->for($site)->create(['status' => ProductStatus::Draft]);

    $locked = productEditorLockStatements(function () use ($site, $product): void {
        Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
            ->call('publish');
    });

    $productLocks = array_values(array_filter($locked, fn (string $sql): bool => str_contains($sql, 'shop_products')));
    $draftLocks = array_values(array_filter($locked, fn (string $sql): bool => str_contains($sql, 'shop_drafts')));

    expect($product->fresh()->status)->toBe(ProductStatus::Published)
        ->and((int) $product->fresh()->revision)->toBe(1)
        ->and((int) ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision'))->toBe(1)
        ->and($productLocks)->not->toBeEmpty()
        ->and($draftLocks)->not->toBeEmpty()
        ->and(array_search($productLocks[0], $locked, true))->toBeLessThan(array_search($draftLocks[0], $locked, true));
});

test('the product editor treats slug as immutable', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $product = Product::factory()->for($site)->create(['slug' => 'kept-slug', 'name' => 'Old']);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->set('name', 'Renamed')
        ->call('save');

    expect($product->fresh()->slug)->toBe('kept-slug')
        ->and($product->fresh()->name)->toBe('Renamed');
});

test('can add variant via editor', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);

    $product = Product::factory()->for($site)->create();

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->set('newVariantSku', 'ROSE-SM')
        ->set('newVariantLabel', 'Small')
        ->set('newVariantPriceCents', 2500)
        ->call('addVariant');

    expect(ProductVariant::where('product_id', $product->id)->count())->toBe(1);
});

test('can attach primary category', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);

    $product = Product::factory()->for($site)->create();
    $cat = Category::factory()->for($site)->create();

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->set('primaryCategoryId', $cat->id)
        ->call('save');

    expect($product->fresh()->primaryCategory()->first()->id)->toBe($cat->id);
});

test('the editor shell links back to the agents products list with a live name, status badge and Save', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct([
        'name' => 'Scarlet Rose',
        'status' => ProductStatus::Draft,
    ]);

    $html = Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])->html();

    expect($html)->toContain(route('sites.shop.products', $site))
        ->and($html)->toContain('Products')
        ->and($html)->toContain('Scarlet Rose')
        ->and($html)->toContain('Save')
        ->and($html)->toContain('data-flux-badge')
        ->and($html)->toContain('border-b border-zinc-200')
        ->and($html)->not->toContain('View on storefront');
});

test('an empty name falls back to Untitled product in the sticky header', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct(['name' => 'Named']);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->set('name', '')
        ->assertSee('Untitled product');
});

test('a published product shows View on storefront for the public PDP on a new tab', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct([
        'name' => 'Scarlet Rose',
        'slug' => 'scarlet-rose',
        'status' => ProductStatus::Published,
    ]);

    $html = Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])->html();

    expect($html)->toContain('View on storefront')
        ->and($html)->toContain('/products/scarlet-rose')
        ->and($html)->toContain('target="_blank"');
});

test('editing a field shows an unsaved-changes bar and Discard restores the saved name', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct(['name' => 'Old Name']);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->set('name', 'New Name')
        ->assertSee('Unsaved changes')
        ->assertSee('Discard')
        ->call('discard')
        ->assertSet('name', 'Old Name')
        ->assertDontSee('Unsaved changes');
});

test('the client portal editor back-link uses the portal products list route', function () {
    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);
    $product = Product::factory()->for($site)->published()->create(['name' => 'Portal Candle']);

    $html = Livewire::actingAs($client)
        ->test('shop.product-editor', [
            'siteId' => $site->id,
            'productId' => $product->id,
            'listRoute' => 'client.portal.shop.products',
        ])
        ->html();

    expect($html)->toContain(route('client.portal.shop.products', $site))
        ->and($html)->not->toContain(route('sites.shop.products', $site));
});

test('the description field is an eight-row textarea with a character count', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct([
        'description' => 'Hello roses',
    ]);

    $html = Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])->html();

    expect($html)->toContain('rows="8"')
        ->and($html)->toContain((string) strlen('Hello roses'));
});

test('save persists extra categories as non-primary pivot rows', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct();
    $primary = Category::factory()->for($site)->create(['name' => 'Bouquets']);
    $extra = Category::factory()->for($site)->create(['name' => 'Seasonal']);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->set('primaryCategoryId', $primary->id)
        ->set('extraCategoryIds', [$extra->id])
        ->call('save');

    $fresh = $product->fresh()->categories()->orderBy('shop_categories.id')->get();
    expect($fresh)->toHaveCount(2)
        ->and((int) $fresh->firstWhere('id', $primary->id)->pivot->is_primary)->toBe(1)
        ->and((int) $fresh->firstWhere('id', $extra->id)->pivot->is_primary)->toBe(0);
});

test('the categories card explains that primary drives the storefront URL', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct();

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->assertSee('Primary decides the storefront URL and breadcrumb.');
});

test('selecting media attaches a product image and Set as primary pins it', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct();
    $first = SiteMedia::factory()->for($site)->create(['s3_key' => 'shop/first.jpg']);
    $second = SiteMedia::factory()->for($site)->create(['s3_key' => 'shop/second.jpg']);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->call('onMediaSelected', $first->id, 'productImageMediaId')
        ->call('onMediaSelected', $second->id, 'productImageMediaId');

    $images = $product->fresh()->images()->orderBy('sort_order')->get();
    expect($images)->toHaveCount(2)
        ->and($images[0]->path)->toBe('shop/first.jpg')
        ->and($product->fresh()->primary_image_id)->toBe($images[0]->id);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->call('setPrimaryImage', $images[1]->id);

    expect((int) $product->fresh()->primary_image_id)->toBe($images[1]->id);
});

test('product images can be reordered with up and down', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct();
    $a = ProductImage::query()->create(['product_id' => $product->id, 'path' => 'a.jpg', 'sort_order' => 0]);
    $b = ProductImage::query()->create(['product_id' => $product->id, 'path' => 'b.jpg', 'sort_order' => 1]);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->call('moveImageDown', $a->id);

    expect($product->fresh()->images()->orderBy('sort_order')->pluck('path')->all())->toBe(['b.jpg', 'a.jpg']);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->call('moveImageUp', $a->id);

    expect($product->fresh()->images()->orderBy('sort_order')->pluck('path')->all())->toBe(['a.jpg', 'b.jpg']);
});

test('the variants table uses an extensible column list including on hand', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct();
    $small = ProductVariant::factory()->for($product)->create(['label' => 'Small', 'sku' => 'ROSE-SM', 'price_cents' => 1200]);
    ProductVariant::factory()->for($product)->create(['label' => 'Large', 'sku' => 'ROSE-LG', 'price_cents' => 1800]);
    VariantStock::query()->create(['variant_id' => $small->id, 'on_hand' => 7, 'updated_at' => now()]);

    $html = Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])->html();

    expect($html)->toContain('Label')
        ->and($html)->toContain('SKU')
        ->and($html)->toContain('Price')
        ->and($html)->toContain('Weight (g)')
        ->and($html)->toContain('On hand')
        ->and($html)->toContain('Image')
        ->and($html)->toContain('ROSE-SM')
        ->and($html)->toContain('7');
});

test('the editor eager-loads sibling variants for labels without N+1', function () {
    $variantSelects = function (int $count): int {
        ['site' => $site, 'product' => $product] = productEditorStaffProduct();
        ProductVariant::factory()
            ->for($product)
            ->count($count)
            ->sequence(fn ($sequence) => [
                'sku' => 'SKU-'.$sequence->index,
                'label' => 'Size '.$sequence->index,
                'price_cents' => 1000,
            ])
            ->create();

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])->html();

        return collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'shop_product_variants'))
            ->count();
    };

    $two = $variantSelects(2);
    $six = $variantSelects(6);

    expect($six)->toBe($two);
});

test('a single Default variant hides the label column', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct();
    ProductVariant::factory()->for($product)->create(['label' => 'Default', 'sku' => 'DEF-1', 'price_cents' => 500]);

    $html = Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])->html();

    expect($html)->toContain('DEF-1')
        ->and($html)->toContain('data-variant-column="sku"')
        ->and($html)->not->toContain('data-variant-column="label"');
});

test('a stale variant row save keeps the typed values and shows the conflict inline', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct();
    $variant = ProductVariant::factory()->for($product)->create([
        'label' => 'Small',
        'sku' => 'ROSE-SM',
        'price_cents' => 1200,
    ]);
    VariantStock::query()->create(['variant_id' => $variant->id, 'on_hand' => 2, 'updated_at' => now()]);

    $component = Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->call('startEditingVariant', $variant->id)
        ->set('editingVariantLabel', 'Typed Large')
        ->set('editingVariantSku', 'ROSE-LG')
        ->set('editingVariantPriceCents', 4000)
        ->set('editingVariantOnHand', 9)
        ->set('editingVariantWeightGrams', 350);

    $product->update([
        'revision' => (int) $product->revision + 1,
    ]);

    $component->call('saveVariantRow')
        ->assertHasErrors('revision')
        ->assertSee('This product was changed elsewhere — reload to see the latest.')
        ->assertSet('editingVariantId', $variant->id)
        ->assertSet('editingVariantLabel', 'Typed Large')
        ->assertSet('editingVariantSku', 'ROSE-LG')
        ->assertSet('editingVariantPriceCents', 4000)
        ->assertSet('editingVariantOnHand', 9)
        ->assertSet('editingVariantWeightGrams', 350);

    expect($variant->fresh()->label)->toBe('Small')
        ->and($variant->fresh()->sku)->toBe('ROSE-SM')
        ->and((int) $variant->fresh()->price_cents)->toBe(1200);
});

test('inline variant edit saves the row including on-hand', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct();
    $variant = ProductVariant::factory()->for($product)->create([
        'label' => 'Small',
        'sku' => 'ROSE-SM',
        'price_cents' => 1200,
    ]);
    VariantStock::query()->create(['variant_id' => $variant->id, 'on_hand' => 2, 'updated_at' => now()]);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->call('startEditingVariant', $variant->id)
        ->set('editingVariantLabel', 'Large')
        ->set('editingVariantSku', 'ROSE-LG')
        ->set('editingVariantPriceCents', 4000)
        ->set('editingVariantOnHand', 9)
        ->set('editingVariantWeightGrams', 350)
        ->call('saveVariantRow');

    $fresh = $variant->fresh();
    expect($fresh->label)->toBe('Large')
        ->and($fresh->sku)->toBe('ROSE-LG')
        ->and((int) $fresh->price_cents)->toBe(4000)
        ->and($fresh->weight_grams)->toBe(350)
        ->and((int) VariantStock::query()->where('variant_id', $variant->id)->value('on_hand'))->toBe(9);
});

test('add variant opens a row then creates the variant with stock', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct();

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->call('startAddingVariant')
        ->set('newVariantSku', 'ROSE-SM')
        ->set('newVariantLabel', 'Small')
        ->set('newVariantPriceCents', 2500)
        ->call('addVariant');

    $created = ProductVariant::query()->where('product_id', $product->id)->sole();
    expect($created->sku)->toBe('ROSE-SM')
        ->and($created->label)->toBe('Small')
        ->and((int) $created->price_cents)->toBe(2500)
        ->and(VariantStock::query()->where('variant_id', $created->id)->exists())->toBeTrue();
});

test('deleting a variant is blocked when it is the last one', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct();
    $variant = ProductVariant::factory()->for($product)->create();

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->call('deleteVariant', $variant->id);

    expect(ProductVariant::query()->where('product_id', $product->id)->count())->toBe(1);
});

test('the last-variant delete check runs after the catalogue row lock', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct();
    ProductVariant::factory()->for($product)->create(['sku' => 'KEEP']);
    $drop = ProductVariant::factory()->for($product)->create(['sku' => 'DROP']);

    $component = Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id]);

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $component->call('deleteVariant', $drop->id);

    $lockIndex = null;
    $remainingCountIndex = null;
    foreach ($queries as $index => $sql) {
        if ($lockIndex === null && str_contains($sql, 'shop_products') && str_contains($sql, 'for update')) {
            $lockIndex = $index;
        }
        if (
            $remainingCountIndex === null
            && str_contains($sql, 'shop_product_variants')
            && str_contains($sql, 'count(')
        ) {
            $remainingCountIndex = $index;
        }
    }

    expect($lockIndex)->not->toBeNull()
        ->and($remainingCountIndex)->not->toBeNull()
        ->and($remainingCountIndex)->toBeGreaterThan($lockIndex)
        ->and(ProductVariant::query()->where('product_id', $product->id)->count())->toBe(1);
});

test('deleting a variant succeeds when another remains', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct();
    $keep = ProductVariant::factory()->for($product)->create(['sku' => 'KEEP']);
    $drop = ProductVariant::factory()->for($product)->create(['sku' => 'DROP']);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->call('deleteVariant', $drop->id);

    expect(ProductVariant::query()->where('product_id', $product->id)->pluck('sku')->all())->toBe(['KEEP']);
    expect(ProductVariant::query()->find($keep->id))->not->toBeNull();
});

test('a variant image can be attached from the media picker', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct();
    $variant = ProductVariant::factory()->for($product)->create();
    $media = SiteMedia::factory()->for($site)->create(['s3_key' => 'shop/variant.jpg']);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->call('startEditingVariant', $variant->id)
        ->call('onMediaSelected', $media->id, 'variantImageMediaId');

    expect(ProductVariantImage::query()->where('variant_id', $variant->id)->value('path'))->toBe('shop/variant.jpg');
});

test('saving a changed status select publishes the product', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct([
        'status' => ProductStatus::Draft,
    ]);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->set('status', 'published')
        ->call('save');

    expect($product->fresh()->status)->toBe(ProductStatus::Published);
});

test('the storefront card saves price_from, tax class and an explicit slug without renaming changing the slug', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct([
        'name' => 'Old Name',
        'slug' => 'kept-slug',
        'price_from' => false,
    ]);
    $this->seed(TaxClassSeeder::class);
    $taxClassId = (int) TaxClass::query()->where('code', 'reduced')->value('id');

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->set('name', 'New Name')
        ->set('priceFrom', true)
        ->set('taxClassId', $taxClassId)
        ->call('save');

    $fresh = $product->fresh();
    expect($fresh->name)->toBe('New Name')
        ->and($fresh->slug)->toBe('kept-slug')
        ->and($fresh->price_from)->toBeTrue()
        ->and((int) $fresh->tax_class_id)->toBe($taxClassId);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->set('slug', 'new-url')
        ->call('save');

    expect($product->fresh()->slug)->toBe('new-url');
});

test('the editor rejects a slug that is not kebab-case', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct([
        'slug' => 'kept-slug',
    ]);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->set('slug', 'My Product!!')
        ->call('save')
        ->assertHasErrors('slug');

    expect($product->fresh()->slug)->toBe('kept-slug');
});

test('the editor accepts a kebab-case slug', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct([
        'slug' => 'kept-slug',
    ]);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->set('slug', 'scarlet-rose')
        ->call('save')
        ->assertHasNoErrors('slug');

    expect($product->fresh()->slug)->toBe('scarlet-rose');
});

test('the storefront card warns that changing the slug changes the URL', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct();

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->assertSee('Changing it changes the URL')
        ->assertSee("Show as 'from' price");
});

test('the sales card shows no sales yet when the product has no paid orders', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct();

    $html = Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])->html();

    expect($html)->toContain('No sales yet')
        ->and($html)->toContain('View orders')
        ->and($html)->toContain('product='.$product->id);
});

test('the sales card counts paid orders that contain the product', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct();
    $variant = ProductVariant::factory()->for($product)->create();
    $order = Order::create([
        'site_id' => $site->id,
        'number' => 'P-SALES-1',
        'email' => 'buyer@example.com',
        'name' => 'Buyer',
        'status' => OrderStatus::Paid->value,
        'refund_status' => 'none',
        'subtotal_cents' => 1000,
        'shipping_cents' => 0,
        'tax_cents' => 0,
        'shipping_tax_cents' => 0,
        'total_cents' => 1000,
        'tax_country_code' => 'GB',
        'shipping_address_json' => [],
        'shipping_method_label' => 'Std',
        'placed_at' => now(),
    ]);
    \DB::table('shop_order_items')->insert([
        'order_id' => $order->id,
        'variant_id' => $variant->id,
        'product_id' => $product->id,
        'product_name_snapshot' => $product->name,
        'sku_snapshot' => $variant->sku,
        'qty' => 1,
        'unit_price_cents' => 1000,
        'tax_class_code' => 'standard',
        'tax_rate_percent' => 20,
        'tax_amount_cents' => 167,
        'line_total_cents' => 1000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->assertSee('1 paid order')
        ->assertDontSee('No sales yet');
});

test('the AI provenance card is shown for seeded products and the Reviewed toggle persists', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct([
        'is_ai_seeded' => true,
        'is_ai_reviewed' => false,
        'ai_seed_source' => 'agent_tool',
        'ai_model_version' => 'demo-test',
        'ai_seeded_at' => now(),
    ]);

    $html = Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])->html();
    expect($html)->toContain('agent_tool')
        ->and($html)->toContain('demo-test')
        ->and($html)->toContain('Reviewed');

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->set('isAiReviewed', true)
        ->call('save');

    expect($product->fresh()->is_ai_reviewed)->toBeTrue();
});

test('the client portal editor hides tax class, AI provenance and delete-variant', function () {
    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);
    $product = Product::factory()->for($site)->published()->create([
        'is_ai_seeded' => true,
        'is_ai_reviewed' => false,
        'ai_seed_source' => 'agent_tool',
        'ai_model_version' => 'demo-test',
    ]);
    ProductVariant::factory()->for($product)->create(['sku' => 'KEEP']);
    ProductVariant::factory()->for($product)->create(['sku' => 'DROP']);

    $html = Livewire::actingAs($client)
        ->test('shop.product-editor', [
            'siteId' => $site->id,
            'productId' => $product->id,
            'listRoute' => 'client.portal.shop.products',
            'ordersRoute' => 'client.portal.orders',
        ])
        ->html();

    expect($html)->not->toContain('Tax class')
        ->and($html)->not->toContain('AI provenance')
        ->and($html)->not->toContain('Delete this variant');
});

test('a client cannot persist tax class, mark AI reviewed, or delete a variant', function () {
    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);
    $this->seed(TaxClassSeeder::class);
    $taxClassId = (int) TaxClass::query()->where('code', 'standard')->value('id');
    $product = Product::factory()->for($site)->published()->create([
        'is_ai_seeded' => true,
        'is_ai_reviewed' => false,
        'tax_class_id' => null,
    ]);
    $keep = ProductVariant::factory()->for($product)->create(['sku' => 'KEEP']);
    $drop = ProductVariant::factory()->for($product)->create(['sku' => 'DROP']);

    Livewire::actingAs($client)
        ->test('shop.product-editor', [
            'siteId' => $site->id,
            'productId' => $product->id,
            'listRoute' => 'client.portal.shop.products',
        ])
        ->set('taxClassId', $taxClassId)
        ->set('isAiReviewed', true)
        ->call('save')
        ->call('deleteVariant', $drop->id);

    $fresh = $product->fresh();
    expect($fresh->tax_class_id)->toBeNull()
        ->and($fresh->is_ai_reviewed)->toBeFalse()
        ->and(ProductVariant::query()->where('product_id', $product->id)->pluck('sku')->all())
        ->toContain('KEEP')
        ->toContain('DROP');
    expect(ProductVariant::query()->find($keep->id))->not->toBeNull();
});

test('staff still see tax class, AI provenance and delete-variant', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct([
        'is_ai_seeded' => true,
        'is_ai_reviewed' => false,
        'ai_seed_source' => 'agent_tool',
    ]);
    ProductVariant::factory()->for($product)->create(['sku' => 'A']);
    ProductVariant::factory()->for($product)->create(['sku' => 'B']);

    $html = Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])->html();

    expect($html)->toContain('Tax class')
        ->and($html)->toContain('AI provenance')
        ->and($html)->toContain('Reviewed')
        ->and($html)->toContain('Delete this variant');
});

test('a stale save shows the revision conflict error and does not overwrite', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct(['name' => 'Original']);

    $component = Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->set('name', 'From this tab');

    $product->update([
        'name' => 'From elsewhere',
        'revision' => (int) $product->revision + 1,
    ]);

    $component->call('save')
        ->assertHasErrors('revision')
        ->assertSee('This product was changed elsewhere — reload to see the latest.');

    expect($product->fresh()->name)->toBe('From elsewhere');
});

test('adding a variant keeps the dirty flag when the title still differs from the persisted product', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct(['name' => 'Original']);

    $component = Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->set('name', 'Edited Title')
        ->set('newVariantSku', 'ROSE-SM')
        ->set('newVariantPriceCents', 2500)
        ->call('addVariant');

    expect($component->get('hasUnsavedChanges'))->toBeTrue()
        ->and($component->get('name'))->toBe('Edited Title')
        ->and($product->fresh()->name)->toBe('Original');

    $component->assertSee('Unsaved changes')
        ->assertSeeHtml('data-has-unsaved-changes="1"');
});

test('a stale publish is refused and does not overwrite status', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct([
        'name' => 'Original',
        'status' => ProductStatus::Draft,
    ]);

    $component = Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id]);

    $product->update([
        'name' => 'From elsewhere',
        'revision' => (int) $product->revision + 1,
    ]);

    $component->call('publish')
        ->assertHasErrors('revision')
        ->assertSee('This product was changed elsewhere — reload to see the latest.');

    expect($product->fresh()->status)->toBe(ProductStatus::Draft)
        ->and($product->fresh()->name)->toBe('From elsewhere')
        ->and((int) $component->get('revision'))->toBe(0);
});

test('a client can publish and unpublish their own product via the Status select and Save', function () {
    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);
    $product = Product::factory()->for($site)->create(['name' => 'Client Cake', 'status' => ProductStatus::Draft]);

    $component = Livewire::actingAs($client)
        ->test('shop.product-editor', [
            'siteId' => $site->id,
            'productId' => $product->id,
            'listRoute' => 'client.portal.shop.products',
        ]);

    expect($component->html())->toContain('wire:model="status"');

    $component->set('status', ProductStatus::Published->value)
        ->call('save')
        ->assertHasNoErrors();

    expect($product->fresh()->status)->toBe(ProductStatus::Published);

    $component = Livewire::actingAs($client)
        ->test('shop.product-editor', [
            'siteId' => $site->id,
            'productId' => $product->id,
            'listRoute' => 'client.portal.shop.products',
        ])
        ->set('status', ProductStatus::Draft->value)
        ->call('save')
        ->assertHasNoErrors();

    expect($product->fresh()->status)->toBe(ProductStatus::Draft);
});

test('a client publish via the legacy Livewire actions is also allowed and revision-locked', function () {
    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);
    $product = Product::factory()->for($site)->create(['status' => ProductStatus::Draft]);

    Livewire::actingAs($client)
        ->test('shop.product-editor', [
            'siteId' => $site->id,
            'productId' => $product->id,
            'listRoute' => 'client.portal.shop.products',
        ])
        ->call('publish')
        ->assertHasNoErrors();

    expect($product->fresh()->status)->toBe(ProductStatus::Published);
});

test('a stale tab cannot attach media and a subsequent save is still refused', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct(['name' => 'Original']);
    $media = SiteMedia::factory()->for($site)->create(['s3_key' => 'shop/stale-attach.jpg']);

    $component = Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->set('name', 'From this tab');

    $product->update([
        'name' => 'From elsewhere',
        'revision' => (int) $product->revision + 1,
    ]);

    $component->call('onMediaSelected', $media->id, 'productImageMediaId')
        ->assertHasErrors('revision')
        ->assertSee('This product was changed elsewhere — reload to see the latest.');

    expect($product->fresh()->images()->count())->toBe(0)
        ->and((int) $component->get('revision'))->toBe(0);

    $component->call('save')
        ->assertHasErrors('revision')
        ->assertSee('This product was changed elsewhere — reload to see the latest.');

    expect($product->fresh()->name)->toBe('From elsewhere');
});

test('an unseeded product does not show the AI provenance card', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct([
        'is_ai_seeded' => false,
        'ai_seed_source' => null,
    ]);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->assertDontSee('agent_tool')
        ->assertDontSee('Reviewed');
});

test('can save a variant weight in grams', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);

    $product = Product::factory()->for($site)->create();
    $variant = ProductVariant::factory()->for($product)->create([
        'sku' => 'ROSE-SM',
        'price_cents' => 2500,
        'weight_grams' => null,
    ]);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->assertSee('Weight (g)')
        ->set('variantWeights.'.$variant->id, 350)
        ->call('save');

    expect($variant->fresh()->weight_grams)->toBe(350)
        ->and((int) $product->fresh()->revision)->toBe(1)
        ->and((int) ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision'))->toBe(1);
});

test('can add a variant with a weight', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);

    $product = Product::factory()->for($site)->create();

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->set('newVariantSku', 'ROSE-LG')
        ->set('newVariantLabel', 'Large')
        ->set('newVariantPriceCents', 3500)
        ->set('newVariantWeightGrams', 800)
        ->call('addVariant');

    $created = ProductVariant::where('product_id', $product->id)->where('sku', 'ROSE-LG')->first();
    expect($created)->not->toBeNull()
        ->and($created->weight_grams)->toBe(800)
        ->and(VariantStock::query()->where('variant_id', $created->id)->exists())->toBeTrue()
        ->and((int) $product->fresh()->revision)->toBe(1)
        ->and((int) ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision'))->toBe(1);
});

test('the editor rejects a fractional variant weight instead of coercing it', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct();
    $variant = ProductVariant::factory()->for($product)->create([
        'sku' => 'ROSE-SM',
        'price_cents' => 2500,
        'weight_grams' => 100,
    ]);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->set('variantWeights.'.$variant->id, '12.5')
        ->call('save')
        ->assertHasErrors(['variantWeights.'.$variant->id]);

    expect($variant->fresh()->weight_grams)->toBe(100);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->set('newVariantSku', 'ROSE-LG')
        ->set('newVariantPriceCents', 3500)
        ->set('newVariantWeightGrams', '12.5')
        ->call('addVariant')
        ->assertHasErrors('newVariantWeightGrams');

    expect(ProductVariant::query()->where('product_id', $product->id)->where('sku', 'ROSE-LG')->exists())->toBeFalse();
});

test('the editor rejects a non-numeric variant weight instead of coercing it to zero', function () {
    ['site' => $site, 'product' => $product] = productEditorStaffProduct();
    $variant = ProductVariant::factory()->for($product)->create([
        'sku' => 'ROSE-SM',
        'price_cents' => 2500,
        'weight_grams' => 100,
    ]);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->set('variantWeights.'.$variant->id, 'abc')
        ->call('save')
        ->assertHasErrors(['variantWeights.'.$variant->id]);

    expect($variant->fresh()->weight_grams)->toBe(100);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->set('newVariantSku', 'ROSE-LG')
        ->set('newVariantPriceCents', 3500)
        ->set('newVariantWeightGrams', 'abc')
        ->call('addVariant')
        ->assertHasErrors('newVariantWeightGrams');

    expect(ProductVariant::query()->where('product_id', $product->id)->where('sku', 'ROSE-LG')->exists())->toBeFalse()
        ->and($variant->fresh()->weight_grams)->toBe(100);
});
