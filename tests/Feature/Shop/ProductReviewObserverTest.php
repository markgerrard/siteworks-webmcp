<?php

use App\Enums\Shop\ProductReviewStatus;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\Product;
use App\Models\Shop\ProductReview;
use App\Models\Site;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

test('creating a review dispatches RebuildShopSnapshot for its site', function () {
    $site = Site::factory()->create();
    $product = Product::factory()->for($site)->create();
    Queue::fake();

    ProductReview::factory()->for($site)->for($product)->published()->create();

    Queue::assertPushed(RebuildShopSnapshot::class, fn ($job) => $job->siteId === $site->id);
});

test('changing review status dispatches RebuildShopSnapshot', function () {
    $site = Site::factory()->create();
    $product = Product::factory()->for($site)->create();
    $review = ProductReview::factory()->for($site)->for($product)->create();
    Queue::fake();

    $review->update(['status' => ProductReviewStatus::Published]);

    Queue::assertPushed(RebuildShopSnapshot::class, fn ($job) => $job->siteId === $site->id);
});

test('deleting a review dispatches RebuildShopSnapshot', function () {
    $site = Site::factory()->create();
    $product = Product::factory()->for($site)->create();
    $review = ProductReview::factory()->for($site)->for($product)->published()->create();
    Queue::fake();

    $review->delete();

    Queue::assertPushed(RebuildShopSnapshot::class, fn ($job) => $job->siteId === $site->id);
});
