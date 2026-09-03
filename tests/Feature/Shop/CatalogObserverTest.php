<?php

use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\Category;
use App\Models\Shop\FeaturedProduct;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Site;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => Queue::fake());

test('creating a product dispatches RebuildShopSnapshot for its site', function () {
    $site = Site::factory()->create();
    Product::factory()->for($site)->create();

    Queue::assertPushed(RebuildShopSnapshot::class, fn ($j) => $j->siteId === $site->id);
});

test('updating a product dispatches RebuildShopSnapshot', function () {
    $product = Product::factory()->create();
    Queue::fake();

    $product->update(['name' => 'New name']);

    Queue::assertPushed(RebuildShopSnapshot::class, fn ($j) => $j->siteId === $product->site_id);
});

test('deleting a product dispatches RebuildShopSnapshot', function () {
    $product = Product::factory()->create();
    $siteId = $product->site_id;
    Queue::fake();

    $product->delete();

    Queue::assertPushed(RebuildShopSnapshot::class, fn ($j) => $j->siteId === $siteId);
});

test('saving a variant dispatches for its product site', function () {
    $product = Product::factory()->create();
    Queue::fake();

    ProductVariant::factory()->for($product)->create();

    Queue::assertPushed(RebuildShopSnapshot::class, fn ($j) => $j->siteId === $product->site_id);
});

test('saving a category dispatches for its site', function () {
    $site = Site::factory()->create();
    Queue::fake();

    Category::factory()->for($site)->create();

    Queue::assertPushed(RebuildShopSnapshot::class, fn ($j) => $j->siteId === $site->id);
});

test('saving a featured entry dispatches for its site', function () {
    $product = Product::factory()->create();
    Queue::fake();

    FeaturedProduct::create(['site_id' => $product->site_id, 'product_id' => $product->id, 'sort_order' => 0]);

    Queue::assertPushed(RebuildShopSnapshot::class, fn ($j) => $j->siteId === $product->site_id);
});
