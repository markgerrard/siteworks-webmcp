<?php

use App\Enums\Shop\ProductReviewStatus;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\Product;
use App\Models\Shop\ProductReview;
use App\Models\Site;
use App\Services\Shop\SnapshotBuilder;
use Illuminate\Support\Facades\Queue;

test('snapshot product payload carries published rating avg to one decimal and count', function () {
    $site = Site::factory()->create();
    $product = Product::factory()->for($site)->published()->create(['slug' => 'victoria-sponge']);
    ProductReview::factory()->for($site)->for($product)->published()->count(2)->create(['rating' => 5]);
    ProductReview::factory()->for($site)->for($product)->published()->create(['rating' => 4]);
    ProductReview::factory()->for($site)->for($product)->create(['rating' => 1, 'status' => ProductReviewStatus::Pending]);
    ProductReview::factory()->for($site)->for($product)->hidden()->create(['rating' => 1]);

    $other = Product::factory()->for($site)->published()->create(['slug' => 'lemon-drizzle']);
    ProductReview::factory()->for($site)->for($other)->published()->create(['rating' => 2]);

    $foreign = Site::factory()->create();
    $foreignProduct = Product::factory()->for($foreign)->published()->create(['slug' => 'victoria-sponge']);
    ProductReview::factory()->for($foreign)->for($foreignProduct)->published()->create(['rating' => 1]);

    $json = app(SnapshotBuilder::class)->build($site->id);

    expect($json['products']['victoria-sponge']['rating'])->toBe(['avg' => 4.7, 'count' => 3])
        ->and($json['products']['lemon-drizzle']['rating'])->toBe(['avg' => 2.0, 'count' => 1]);
});

test('a product with no published reviews omits the rating key', function () {
    $site = Site::factory()->create();
    $product = Product::factory()->for($site)->published()->create(['slug' => 'plain']);
    ProductReview::factory()->for($site)->for($product)->create(['rating' => 5]);

    $json = app(SnapshotBuilder::class)->build($site->id);

    expect($json['products']['plain'])->not->toHaveKey('rating');
});
