<?php

use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\Product;
use App\Models\Site;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('reconcile dispatches RebuildShopSnapshot for every site with products', function () {
    Queue::fake();

    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();
    Product::factory()->for($siteA)->create();
    Product::factory()->for($siteB)->create();

    // Reset the fake so observer-triggered dispatches don't pollute the assertion.
    Queue::fake();

    $this->artisan('shop:reconcile')->assertSuccessful();

    Queue::assertPushed(RebuildShopSnapshot::class, fn ($j) => $j->siteId === $siteA->id);
    Queue::assertPushed(RebuildShopSnapshot::class, fn ($j) => $j->siteId === $siteB->id);
});
