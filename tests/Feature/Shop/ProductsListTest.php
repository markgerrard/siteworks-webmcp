<?php

use App\Enums\Shop\ProductStatus;
use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Shop\ProductImage;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Models\User;
use App\Support\ShopMoney;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * @return array{user: User, site: Site}
 */
function productsListSite(?User $user = null): array
{
    $user ??= User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id, 'shop_currency' => 'GBP']);
    test()->actingAs($user);

    return compact('user', 'site');
}

/**
 * The export route lives on the client portal, so its requests come from a client of the site's tenant.
 *
 * @return array{user: User, site: Site}
 */
function productsListClientSite(bool $shopEnabled = true): array
{
    $tenant = \App\Models\Client::factory()->create();
    $user = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $factory = $shopEnabled ? Site::factory() : Site::factory()->shopDisabled();
    $site = $factory->create(['client_id' => $tenant->id, 'shop_currency' => 'GBP']);

    return compact('user', 'site');
}

test('empty state invites adding a product or importing a csv', function () {
    ['site' => $site] = productsListSite();

    $html = Livewire::test('shop.products-list', ['siteId' => $site->id])->html();

    expect($html)->toContain('No products yet — add your first product or import a CSV.')
        ->and($html)->toContain('Coming soon')
        ->and($html)->toMatch('/disabled[^>]*>?\s*Import|Import[^>]*disabled/i');
});

test('lists products for site and filters out archived by default', function () {
    ['site' => $site] = productsListSite();

    Product::factory()->for($site)->create(['name' => 'Rose', 'status' => ProductStatus::Published]);
    Product::factory()->for($site)->create(['name' => 'Lily', 'status' => ProductStatus::Draft]);
    Product::factory()->for($site)->create(['name' => 'Old', 'status' => ProductStatus::Archived]);

    Livewire::test('shop.products-list', ['siteId' => $site->id])
        ->assertSee('Rose')
        ->assertSee('Lily')
        ->assertDontSee('Old');
});

test('tag filter keeps products that carry the selected vocabulary slug', function () {
    ['site' => $site] = productsListSite();
    $site->update([
        'product_tags' => [
            ['slug' => 'seasonal', 'label' => 'Seasonal', 'show_as_badge' => true, 'tone' => 'warning'],
        ],
    ]);
    Product::factory()->for($site)->create(['name' => 'Tagged Bunch', 'tags' => ['seasonal']]);
    Product::factory()->for($site)->create(['name' => 'Plain Bunch', 'tags' => []]);

    Livewire::test('shop.products-list', ['siteId' => $site->id])
        ->assertSee('Tagged Bunch')
        ->assertSee('Plain Bunch')
        ->set('tag', 'seasonal')
        ->assertSee('Tagged Bunch')
        ->assertDontSee('Plain Bunch');
});

test('search filters by product name', function () {
    ['site' => $site] = productsListSite();

    Product::factory()->for($site)->create(['name' => 'Red Rose']);
    Product::factory()->for($site)->create(['name' => 'White Lily']);

    Livewire::test('shop.products-list', ['siteId' => $site->id])
        ->set('search', 'rose')
        ->assertSee('Red Rose')
        ->assertDontSee('White Lily');
});

test('header shows add product, export csv, and a disabled import coming soon', function () {
    ['site' => $site] = productsListSite();

    $html = Livewire::test('shop.products-list', ['siteId' => $site->id])->html();

    expect($html)->toContain('Add product')
        ->and($html)->toContain('Export CSV')
        ->and($html)->toContain(route('client.portal.shop.products.export', $site))
        ->and($html)->toContain('Import')
        ->and($html)->toContain('Coming soon')
        ->and($html)->toMatch('/disabled[^>]*>?\s*Import|Import[^>]*disabled/i');
});

test('add product creates a draft via ShopDraftWriter and redirects to the editor', function () {
    ['site' => $site] = productsListSite();

    $component = Livewire::test('shop.products-list', ['siteId' => $site->id])
        ->call('addProduct');

    $product = Product::query()->where('site_id', $site->id)->first();
    expect($product)->not->toBeNull()
        ->and($product->status)->toBe(ProductStatus::Draft)
        ->and($product->slug)->toBe('untitled-product')
        ->and($product->variants)->not->toBeEmpty();

    $component->assertRedirect(route('client.portal.shop.products.edit', ['site' => $site->id, 'product' => $product->id]));
});

test('export csv streams the site products with the required headers and rows', function () {
    ['user' => $user, 'site' => $site] = productsListClientSite();
    $category = Category::factory()->for($site)->create(['name' => 'Bouquets']);
    $product = Product::factory()->for($site)->create([
        'name' => 'Scarlet Rose',
        'slug' => 'scarlet-rose',
        'status' => ProductStatus::Published,
    ]);
    $product->categories()->attach($category->id, ['is_primary' => true]);
    $variant = ProductVariant::factory()->for($product)->create([
        'sku' => 'ROSE-STEM',
        'label' => 'Stem',
        'price_cents' => 1250,
    ]);
    VariantStock::query()->create(['variant_id' => $variant->id, 'on_hand' => 7, 'updated_at' => now()]);

    $response = $this->actingAs($user)->get(route('client.portal.shop.products.export', $site));

    $response->assertSuccessful();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $csv = $response->streamedContent();
    $rows = array_map(str_getcsv(...), array_values(array_filter(explode("\n", trim($csv)))));

    expect($rows[0])->toBe(['name', 'slug', 'sku', 'variant label', 'price', 'on hand', 'status', 'categories'])
        ->and($rows)->toHaveCount(2)
        ->and($rows[1][0])->toBe('Scarlet Rose')
        ->and($rows[1][1])->toBe('scarlet-rose')
        ->and($rows[1][2])->toBe('ROSE-STEM')
        ->and($rows[1][3])->toBe('Stem')
        ->and($rows[1][4])->toBe('12.50')
        ->and($rows[1][5])->toBe('7')
        ->and($rows[1][6])->toBe('published')
        ->and($rows[1][7])->toBe('Bouquets');
});

/**
 * @return list<list<?string>>
 */
function productsListCsvRows(string $csv): array
{
    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $csv);
    rewind($handle);

    $rows = [];
    while (($row = fgetcsv($handle, escape: '')) !== false) {
        $rows[] = $row;
    }
    fclose($handle);

    return $rows;
}

test('export csv prefixes formula-like cells so a hyperlink name is not a formula', function () {
    ['user' => $user, 'site' => $site] = productsListClientSite();
    $product = Product::factory()->for($site)->create([
        'name' => '=HYPERLINK("x")',
        'slug' => 'formula-rose',
        'status' => ProductStatus::Published,
    ]);
    ProductVariant::factory()->for($product)->create([
        'sku' => 'ROSE-1',
        'label' => 'Stem',
        'price_cents' => 1000,
    ]);

    $csv = $this->actingAs($user)
        ->get(route('client.portal.shop.products.export', $site))
        ->assertSuccessful()
        ->streamedContent();

    expect($csv)->not->toStartWith("\u{FEFF}");

    $rows = productsListCsvRows($csv);
    expect($rows)->toHaveCount(2)
        ->and($rows[1][0])->toBe("'=HYPERLINK(\"x\")");
});

test('export csv round-trips commas, quotes, and newlines in a cell', function () {
    ['user' => $user, 'site' => $site] = productsListClientSite();
    $product = Product::factory()->for($site)->create([
        'name' => "Rose, \"red\"\nbloom",
        'slug' => 'comma-rose',
        'status' => ProductStatus::Published,
    ]);
    ProductVariant::factory()->for($product)->create([
        'sku' => 'ROSE, "Q"',
        'label' => "Stem\nlong",
        'price_cents' => 1000,
    ]);

    $csv = $this->actingAs($user)
        ->get(route('client.portal.shop.products.export', $site))
        ->assertSuccessful()
        ->streamedContent();

    $rows = productsListCsvRows($csv);
    expect($rows)->toHaveCount(2)
        ->and($rows[1][0])->toBe("Rose, \"red\"\nbloom")
        ->and($rows[1][2])->toBe('ROSE, "Q"')
        ->and($rows[1][3])->toBe("Stem\nlong");
});

test('export csv 404s when the shop flag is off', function () {
    ['user' => $user, 'site' => $site] = productsListClientSite(shopEnabled: false);

    $this->actingAs($user)
        ->get(route('client.portal.shop.products.export', $site))
        ->assertNotFound();
});

test('saved-view tabs show counts and filter the list', function () {
    ['site' => $site] = productsListSite();

    $published = Product::factory()->for($site)->create(['name' => 'Shown Rose', 'status' => ProductStatus::Published]);
    Product::factory()->for($site)->create(['name' => 'Draft Lily', 'status' => ProductStatus::Draft]);
    Product::factory()->for($site)->create(['name' => 'Old Archived', 'status' => ProductStatus::Archived]);
    $oos = Product::factory()->for($site)->create(['name' => 'Empty Vase', 'status' => ProductStatus::Published]);
    $mto = Product::factory()->for($site)->create(['name' => 'Bespoke Cake', 'status' => ProductStatus::Draft, 'price_from' => true]);

    $publishedVariant = ProductVariant::factory()->for($published)->create(['sku' => 'ROSE-OK', 'price_cents' => 1000]);
    VariantStock::query()->create(['variant_id' => $publishedVariant->id, 'on_hand' => 4, 'updated_at' => now()]);
    $oosVariant = ProductVariant::factory()->for($oos)->create(['sku' => 'VASE-0', 'price_cents' => 500]);
    VariantStock::query()->create(['variant_id' => $oosVariant->id, 'on_hand' => 0, 'updated_at' => now()]);
    ProductVariant::factory()->for($mto)->create(['sku' => 'CAKE-1', 'price_cents' => 4000]);

    $component = Livewire::test('shop.products-list', ['siteId' => $site->id]);
    $html = $component->html();

    expect($html)->toContain('All')
        ->and($html)->toContain('Published')
        ->and($html)->toContain('Draft')
        ->and($html)->toContain('Out of stock')
        ->and($html)->toContain('Made to order')
        ->and($html)->not->toContain('All statuses')
        ->and($html)->toContain('All 4')
        ->and($html)->toContain('Published 2')
        ->and($html)->toContain('Draft 2')
        ->and($html)->toContain('Out of stock 1')
        ->and($html)->toContain('Made to order 1');

    $component->assertSee('Shown Rose')
        ->assertSee('Draft Lily')
        ->assertSee('Empty Vase')
        ->assertSee('Bespoke Cake')
        ->assertDontSee('Old Archived');

    $component->set('view', 'published')
        ->assertSee('Shown Rose')
        ->assertSee('Empty Vase')
        ->assertDontSee('Draft Lily')
        ->assertDontSee('Bespoke Cake');

    $component->set('view', 'draft')
        ->assertSee('Draft Lily')
        ->assertSee('Bespoke Cake')
        ->assertDontSee('Shown Rose');

    $component->set('view', 'out_of_stock')
        ->assertSee('Empty Vase')
        ->assertDontSee('Shown Rose')
        ->assertDontSee('Bespoke Cake');

    $component->set('view', 'made_to_order')
        ->assertSee('Bespoke Cake')
        ->assertDontSee('Shown Rose')
        ->assertDontSee('Empty Vase');
});

test('search filters by sku as well as name', function () {
    ['site' => $site] = productsListSite();

    $rose = Product::factory()->for($site)->create(['name' => 'Red Rose']);
    $lily = Product::factory()->for($site)->create(['name' => 'White Lily']);
    ProductVariant::factory()->for($rose)->create(['sku' => 'ROSE-STEM']);
    ProductVariant::factory()->for($lily)->create(['sku' => 'LILY-1']);

    Livewire::test('shop.products-list', ['siteId' => $site->id])
        ->set('search', 'rose-stem')
        ->assertSee('Red Rose')
        ->assertDontSee('White Lily');
});

test('column headers sort by name price stock and updated with aria-sort', function () {
    ['site' => $site] = productsListSite();

    $alpha = Product::factory()->for($site)->create(['name' => 'Alpha Bloom', 'updated_at' => now()->subDay()]);
    $zeta = Product::factory()->for($site)->create(['name' => 'Zeta Bloom', 'updated_at' => now()]);
    $alphaVar = ProductVariant::factory()->for($alpha)->create(['sku' => 'A-1', 'price_cents' => 500]);
    $zetaVar = ProductVariant::factory()->for($zeta)->create(['sku' => 'Z-1', 'price_cents' => 2500]);
    VariantStock::query()->create(['variant_id' => $alphaVar->id, 'on_hand' => 9, 'updated_at' => now()]);
    VariantStock::query()->create(['variant_id' => $zetaVar->id, 'on_hand' => 1, 'updated_at' => now()]);

    $component = Livewire::test('shop.products-list', ['siteId' => $site->id]);
    $component->assertSeeInOrder(['Zeta Bloom', 'Alpha Bloom']);

    expect($component->html())->toMatch('/aria-sort="(none|ascending|descending)"/');

    $component->call('sort', 'name')->assertSeeInOrder(['Alpha Bloom', 'Zeta Bloom']);
    $component->call('sort', 'price')->assertSeeInOrder(['Alpha Bloom', 'Zeta Bloom']);
    $component->call('sort', 'stock')->assertSeeInOrder(['Zeta Bloom', 'Alpha Bloom']);
    $component->call('sort', 'updated')->assertSeeInOrder(['Zeta Bloom', 'Alpha Bloom']);

    $html = $component->html();
    expect($html)->toContain('aria-sort="descending"');
});

test('rows show thumbnail, variant summary, sku, price, on hand, status, and a row menu', function () {
    ['site' => $site] = productsListSite();
    $site->update(['preview_domain' => 'rose-shop', 'preview_brand' => 'a']);

    $multi = Product::factory()->for($site)->create([
        'name' => 'Garden Rose',
        'slug' => 'garden-rose',
        'status' => ProductStatus::Published,
        'price_from' => true,
    ]);
    ProductImage::query()->create([
        'product_id' => $multi->id,
        'path' => 'shop/products/garden-rose.png',
        'sort_order' => 0,
        'alt' => 'Garden rose',
    ]);
    $stem = ProductVariant::factory()->for($multi)->create(['sku' => 'ROSE-STEM', 'label' => 'Stem', 'price_cents' => 1250]);
    $bunch = ProductVariant::factory()->for($multi)->create(['sku' => 'ROSE-BUNCH', 'label' => 'Bunch', 'price_cents' => 4500]);
    VariantStock::query()->create(['variant_id' => $stem->id, 'on_hand' => 3, 'updated_at' => now()]);
    VariantStock::query()->create(['variant_id' => $bunch->id, 'on_hand' => 2, 'updated_at' => now()]);

    $single = Product::factory()->for($site)->create(['name' => 'Solo Lily', 'status' => ProductStatus::Draft]);
    ProductVariant::factory()->for($single)->create(['sku' => 'LILY-1', 'label' => 'Default', 'price_cents' => 800]);

    $html = Livewire::test('shop.products-list', ['siteId' => $site->id])->html();

    expect($html)->toContain('Garden rose')
        ->and($html)->toContain('shop/products/garden-rose.png')
        ->and($html)->toContain('Garden Rose')
        ->and($html)->toContain('2 variants')
        ->and($html)->toContain('ROSE-STEM')
        ->and($html)->toContain(ShopMoney::display(1250, 'GBP', true))
        ->and($html)->toContain('5')
        ->and($html)->toContain('Solo Lily')
        ->and($html)->toContain('LILY-1')
        ->and($html)->toContain('—')
        ->and($html)->toContain('shop/products/'.$multi->id.'/edit')
        ->and($html)->toContain('View on storefront')
        ->and($html)->toContain('/products/garden-rose')
        ->and($html)->toContain('Unpublish')
        ->and($html)->toContain('Publish')
        ->and($html)->toContain('Delete')
        ->and($html)->toMatch('/wire:confirm="[^"]*Garden Rose/');
});

test('row publish unpublish and delete use ShopDraftWriter status changes', function () {
    ['site' => $site] = productsListSite();
    $product = Product::factory()->for($site)->create(['name' => 'Toggle Me', 'status' => ProductStatus::Draft]);

    Livewire::test('shop.products-list', ['siteId' => $site->id])
        ->call('publishProduct', $product->id);

    expect($product->fresh()->status)->toBe(ProductStatus::Published);

    Livewire::test('shop.products-list', ['siteId' => $site->id])
        ->call('unpublishProduct', $product->id);

    expect($product->fresh()->status)->toBe(ProductStatus::Draft);

    Livewire::test('shop.products-list', ['siteId' => $site->id])
        ->call('deleteProduct', $product->id);

    expect($product->fresh()->status)->toBe(ProductStatus::Archived);
});

test('bulk bar publishes unpublishes and deletes selected products via ShopDraftWriter', function () {
    ['site' => $site] = productsListSite();
    $one = Product::factory()->for($site)->create(['name' => 'Bulk One', 'status' => ProductStatus::Draft]);
    $two = Product::factory()->for($site)->create(['name' => 'Bulk Two', 'status' => ProductStatus::Draft]);

    $component = Livewire::test('shop.products-list', ['siteId' => $site->id])
        ->set('selectedIds', [(string) $one->id, (string) $two->id]);

    expect($component->html())->toContain('2 selected')
        ->and($component->html())->toContain('Publish')
        ->and($component->html())->toContain('Unpublish')
        ->and($component->html())->toMatch('/wire:confirm="[^"]*2 product/');

    $component->call('bulkPublish');
    expect($one->fresh()->status)->toBe(ProductStatus::Published)
        ->and($two->fresh()->status)->toBe(ProductStatus::Published);

    Livewire::test('shop.products-list', ['siteId' => $site->id])
        ->set('selectedIds', [(string) $one->id, (string) $two->id])
        ->call('bulkUnpublish');
    expect($one->fresh()->status)->toBe(ProductStatus::Draft)
        ->and($two->fresh()->status)->toBe(ProductStatus::Draft);

    Livewire::test('shop.products-list', ['siteId' => $site->id])
        ->set('selectedIds', [(string) $one->id, (string) $two->id])
        ->call('bulkDelete');
    expect($one->fresh()->status)->toBe(ProductStatus::Archived)
        ->and($two->fresh()->status)->toBe(ProductStatus::Archived);
});

test('the products list issues a constant number of queries for one or fifty products', function () {
    $countQueries = function (int $n): int {
        ['site' => $site] = productsListSite();

        foreach (range(1, $n) as $i) {
            $product = Product::factory()->for($site)->create(['name' => 'Query '.$i]);
            $variant = ProductVariant::factory()->for($product)->create([
                'sku' => 'SKU-'.$i,
                'label' => 'Stem',
                'price_cents' => 1000,
            ]);
            VariantStock::query()->create(['variant_id' => $variant->id, 'on_hand' => 2, 'updated_at' => now()]);
            ProductImage::query()->create([
                'product_id' => $product->id,
                'path' => 'shop/products/query-'.$i.'.png',
                'sort_order' => 0,
                'alt' => 'Query '.$i,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test('shop.products-list', ['siteId' => $site->id])->html();

        return count(DB::getQueryLog());
    };

    $one = $countQueries(1);
    $fifty = $countQueries(50);

    expect($fifty)->toBe($one)
        ->and($one)->toBeGreaterThan(0);
});

test('paginates fifty products and shows a range footer', function () {
    ['site' => $site] = productsListSite();
    foreach (range(1, 51) as $n) {
        Product::factory()->for($site)->create([
            'name' => sprintf('Paged %02d', $n),
            'updated_at' => now()->subMinutes(51 - $n),
        ]);
    }

    $page1 = Livewire::test('shop.products-list', ['siteId' => $site->id]);
    expect($page1->html())->toContain('1–50 of 51');
    $page1->assertSee('Paged 51')->assertDontSee('Paged 01');

    Livewire::test('shop.products-list', ['siteId' => $site->id])
        ->set('paginators.page', 2)
        ->assertSee('Paged 01')
        ->assertDontSee('Paged 51');
});

test('clients can export but not delete products', function () {
    $tenant = \App\Models\Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);
    $product = Product::factory()->for($site)->published()->create(['name' => 'Client Rose']);

    $html = Livewire::actingAs($client)
        ->test('shop.products-list', [
            'siteId' => $site->id,
            'exportRoute' => 'client.portal.shop.products.export',
        ])
        ->html();

    expect($html)->toContain('Export CSV')
        ->and($html)->not->toContain('Delete')
        ->and($html)->toContain('Add product');

    Livewire::actingAs($client)
        ->test('shop.products-list', ['siteId' => $site->id])
        ->call('deleteProduct', $product->id)
        ->assertForbidden();

    $this->actingAs($client)
        ->get(route('client.portal.shop.products.export', $site))
        ->assertSuccessful();
});

test('enter on a focused row opens the product editor', function () {
    ['site' => $site] = productsListSite();
    $product = Product::factory()->for($site)->create(['name' => 'Keyboard Rose']);

    $component = Livewire::test('shop.products-list', ['siteId' => $site->id]);
    expect($component->html())->toMatch('/tabindex="0"/');

    $component->call('openProduct', $product->id)
        ->assertRedirect(route('client.portal.shop.products.edit', ['site' => $site->id, 'product' => $product->id]));
});

test('ticking a single row is live so the bulk bar appears without select-all', function () {
    ['site' => $site] = productsListSite();
    $product = Product::factory()->for($site)->create(['name' => 'Tick Me']);

    $html = Livewire::test('shop.products-list', ['siteId' => $site->id])->html();
    preg_match_all('/wire:model[^\s="]*="selectedIds"/', $html, $m);

    expect($m[0])->not->toBeEmpty()
        ->and(array_unique($m[0]))->toBe(['wire:model.live="selectedIds"']);

    $component = Livewire::test('shop.products-list', ['siteId' => $site->id])
        ->set('selectedIds', [(string) $product->id]);

    expect($component->html())->toContain('1 selected');
});

test('select all checks every product on the current page', function () {
    ['site' => $site] = productsListSite();
    $products = collect();
    foreach (range(1, 51) as $n) {
        $products->push(Product::factory()->for($site)->create([
            'name' => sprintf('Select %02d', $n),
            'updated_at' => now()->subMinutes(51 - $n),
        ]));
    }

    $component = Livewire::test('shop.products-list', ['siteId' => $site->id])
        ->call('toggleSelectAll');

    $ids = array_map('intval', $component->get('selectedIds'));
    $offPageId = $products->firstWhere('name', 'Select 01')->id;
    $pageIds = $products->reject(fn (Product $product): bool => $product->id === $offPageId)->pluck('id')->all();

    expect($ids)->toHaveCount(50)
        ->and($ids)->toEqualCanonicalizing($pageIds)
        ->and($ids)->not->toContain($offPageId);
});

test('bulk actions refuse a product id from another site and change nothing', function () {
    ['site' => $site] = productsListSite();
    $own = Product::factory()->for($site)->create(['name' => 'Own Rose', 'status' => ProductStatus::Draft]);
    $foreign = Product::factory()->for(Site::factory()->create())->create([
        'name' => 'Foreign Lily',
        'status' => ProductStatus::Draft,
    ]);

    Livewire::test('shop.products-list', ['siteId' => $site->id])
        ->set('selectedIds', [(string) $own->id, (string) $foreign->id])
        ->call('bulkPublish')
        ->assertNotFound();

    expect($own->fresh()->status)->toBe(ProductStatus::Draft)
        ->and($foreign->fresh()->status)->toBe(ProductStatus::Draft);
});

test('an import warning shows a needs-review badge whose title carries the notes', function () {
    ['site' => $site] = productsListSite();

    Product::factory()->for($site)->create(['name' => 'Warned Bunch', 'review_notes' => ['missing_description', 'duplicate_category']]);
    Product::factory()->for($site)->create(['name' => 'Clean Bunch', 'review_notes' => null]);

    $html = Livewire::test('shop.products-list', ['siteId' => $site->id])->html();

    expect($html)->toContain('Needs review')
        ->and(substr_count($html, 'Needs review'))->toBe(1)
        ->and($html)->toContain('title="No description; Duplicate category skipped"');
});

test('a catalogue-changed event re-renders the list with the new product and counts', function () {
    ['site' => $site] = productsListSite();
    Product::factory()->for($site)->create(['name' => 'First Bunch', 'status' => ProductStatus::Draft]);

    $component = Livewire::test('shop.products-list', ['siteId' => $site->id])
        ->assertSee('First Bunch')
        ->assertSee('Draft 1')
        ->assertDontSee('Imported Bunch');

    Product::factory()->for($site)->create(['name' => 'Imported Bunch', 'status' => ProductStatus::Draft]);

    $component->dispatch('shop-catalogue-changed')
        ->assertSee('Imported Bunch')
        ->assertSee('Draft 2');
});

test('the filter-drafts event switches the list to the draft view', function () {
    ['site' => $site] = productsListSite();
    Product::factory()->for($site)->create(['name' => 'Live Bunch', 'status' => ProductStatus::Published]);
    Product::factory()->for($site)->create(['name' => 'Draft Bunch', 'status' => ProductStatus::Draft]);

    Livewire::test('shop.products-list', ['siteId' => $site->id])
        ->assertSee('Live Bunch')
        ->dispatch('shop-filter-drafts')
        ->assertSet('view', 'draft')
        ->assertSee('Draft Bunch')
        ->assertDontSee('Live Bunch');
});

test('bulk publish skips an unpriced import draft with a message and publishes the rest', function () {
    ['site' => $site] = productsListSite();
    $priced = Product::factory()->for($site)->create(['name' => 'Priced Bunch', 'status' => ProductStatus::Draft]);
    $unpriced = Product::factory()->for($site)->create([
        'name' => 'Unpriced Bunch',
        'status' => ProductStatus::Draft,
        'review_notes' => ['price_missing'],
    ]);
    ProductVariant::factory()->for($unpriced)->create(['sku' => 'UNPRICED-1', 'price_cents' => 0]);

    $component = Livewire::test('shop.products-list', ['siteId' => $site->id])
        ->set('selectedIds', [(string) $priced->id, (string) $unpriced->id])
        ->call('bulkPublish');

    expect($priced->fresh()->status)->toBe(ProductStatus::Published)
        ->and($unpriced->fresh()->status)->toBe(ProductStatus::Draft)
        ->and($unpriced->fresh()->review_notes)->toBe(['price_missing']);

    $html = $component->html();
    expect($html)->toContain('Set a price before publishing')
        ->and($html)->toContain('data-publish-blocked="'.$unpriced->id.'"')
        ->and($html)->toContain('1 product was not published')
        ->and($html)->toContain('Needs review');
});

test('a row publish refuses an unpriced import draft until a price is saved', function () {
    ['site' => $site] = productsListSite();
    $product = Product::factory()->for($site)->create([
        'name' => 'Ask Us Bunch',
        'status' => ProductStatus::Draft,
        'review_notes' => ['missing_description', 'price_missing'],
    ]);
    ProductVariant::factory()->for($product)->create(['sku' => 'ASK-1', 'price_cents' => 0]);

    $component = Livewire::test('shop.products-list', ['siteId' => $site->id])
        ->call('publishProduct', $product->id);

    expect($product->fresh()->status)->toBe(ProductStatus::Draft)
        ->and($component->html())->toContain('Set a price before publishing');

    $written = app(\App\Services\Shop\ShopDraftWriter::class)->updateDraft($site, $product, [
        'variants' => [['sku' => 'ASK-1', 'price_cents' => 1250]],
    ]);
    ($written['deferred'])();

    expect($product->fresh()->review_notes)->toBe(['missing_description']);

    $component = Livewire::test('shop.products-list', ['siteId' => $site->id])
        ->call('publishProduct', $product->id);

    expect($product->fresh()->status)->toBe(ProductStatus::Published)
        ->and($component->html())->not->toContain('Set a price before publishing');
});

test('a zero price entered on purpose is not the unpriced-import note and still publishes', function () {
    ['site' => $site] = productsListSite();
    $product = Product::factory()->for($site)->create(['name' => 'Free Sample', 'status' => ProductStatus::Draft, 'review_notes' => null]);
    ProductVariant::factory()->for($product)->create(['sku' => 'FREE-1', 'price_cents' => 0]);

    Livewire::test('shop.products-list', ['siteId' => $site->id])->call('publishProduct', $product->id);

    expect($product->fresh()->status)->toBe(ProductStatus::Published);
});

test('a publish from the list announces the catalogue change on the same event an agent write does', function () {
    ['site' => $site] = productsListSite();
    $draft = Product::factory()->for($site)->create(['name' => 'Quiet Bunch', 'status' => ProductStatus::Draft]);
    $live = Product::factory()->for($site)->create(['name' => 'Loud Bunch', 'status' => ProductStatus::Published]);
    $unpriced = Product::factory()->for($site)->create(['name' => 'Ask Bunch', 'status' => ProductStatus::Draft, 'review_notes' => ['price_missing']]);
    ProductVariant::factory()->for($unpriced)->create(['sku' => 'ASK-1', 'price_cents' => 0]);

    Livewire::test('shop.products-list', ['siteId' => $site->id])
        ->call('publishProduct', $draft->id)
        ->assertDispatched('shop-catalogue-changed')
        ->call('unpublishProduct', $live->id)
        ->assertDispatched('shop-catalogue-changed')
        ->set('selectedIds', [(string) $live->id])
        ->call('bulkPublish')
        ->assertDispatched('shop-catalogue-changed')
        ->set('selectedIds', [(string) $unpriced->id])
        ->call('bulkPublish')
        ->assertNotDispatched('shop-catalogue-changed');
});

test('a catalogue-changed event drops a refused row message once its price is set, and keeps it until then', function () {
    ['site' => $site] = productsListSite();
    $unpriced = Product::factory()->for($site)->create(['name' => 'Ask Bunch', 'status' => ProductStatus::Draft, 'review_notes' => ['price_missing']]);
    ProductVariant::factory()->for($unpriced)->create(['sku' => 'ASK-1', 'price_cents' => 0]);

    $component = Livewire::test('shop.products-list', ['siteId' => $site->id])
        ->set('selectedIds', [(string) $unpriced->id])
        ->call('bulkPublish')
        ->assertSee('Set a price before publishing')
        ->dispatch('shop-catalogue-changed')
        ->assertSee('Set a price before publishing');

    $unpriced->variants()->update(['price_cents' => 1200]);
    $unpriced->forceFill(['review_notes' => []])->save();

    $component->dispatch('shop-catalogue-changed')
        ->assertDontSee('Set a price before publishing')
        ->assertSet('publishBlocked', []);
});

test('on a quote shop the tabs count a product with none on hand as made to order, never out of stock', function () {
    ['site' => $site] = productsListSite();
    $site->forceFill(['shop_mode' => 'quote'])->save();

    $stocked = Product::factory()->for($site)->create(['name' => 'Stocked Rose', 'status' => ProductStatus::Published]);
    $none = Product::factory()->for($site)->create(['name' => 'Flyer Loaf', 'status' => ProductStatus::Published]);
    $priceFrom = Product::factory()->for($site)->create(['name' => 'Bespoke Cake', 'status' => ProductStatus::Draft, 'price_from' => true]);

    $stockedVariant = ProductVariant::factory()->for($stocked)->create(['sku' => 'ROSE-OK', 'price_cents' => 1000]);
    VariantStock::query()->create(['variant_id' => $stockedVariant->id, 'on_hand' => 4, 'updated_at' => now()]);
    $noneVariant = ProductVariant::factory()->for($none)->create(['sku' => 'LOAF-0', 'price_cents' => 650]);
    VariantStock::query()->create(['variant_id' => $noneVariant->id, 'on_hand' => 0, 'updated_at' => now()]);
    ProductVariant::factory()->for($priceFrom)->create(['sku' => 'CAKE-1', 'price_cents' => 4000]);

    $component = Livewire::test('shop.products-list', ['siteId' => $site->id]);
    $html = $component->html();

    expect($html)->toContain('Out of stock 0')
        ->and($html)->toContain('Made to order 2');

    $component->set('view', 'made_to_order')
        ->assertSee('Flyer Loaf')
        ->assertSee('Bespoke Cake')
        ->assertDontSee('Stocked Rose')
        ->set('view', 'out_of_stock')
        ->assertDontSee('Flyer Loaf');
});
