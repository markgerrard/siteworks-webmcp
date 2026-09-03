<?php

use App\Enums\Shop\ProductReviewStatus;
use App\Enums\SiteReviewStatus;
use App\Models\Shop\Product;
use App\Models\Shop\ProductReview;
use App\Models\Client;
use App\Models\Site;
use App\Models\SiteReview;
use App\Models\User;
use App\Services\Site\TrustSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('aggregates approved site and published product reviews by source', function () {
    $site = Site::factory()->create();
    $product = Product::factory()->for($site)->create(['name' => 'Signature item', 'slug' => 'signature-item']);

    SiteReview::factory()->approved()->for($site)->create(['rating' => 5, 'created_at' => now()->subDays(3)]);
    SiteReview::factory()->approved()->for($site)->create(['rating' => 4, 'created_at' => now()->subDays(2)]);
    SiteReview::factory()->for($site)->create(['rating' => 1, 'status' => SiteReviewStatus::Pending]);
    ProductReview::factory()->published()->for($site)->for($product)->create(['rating' => 3, 'created_at' => now()->subDay()]);
    ProductReview::factory()->for($site)->for($product)->create(['rating' => 1, 'status' => ProductReviewStatus::Hidden]);

    $summary = app(TrustSummary::class);

    expect($summary->for($site, 'site'))
        ->average->toBe(4.5)
        ->count->toBe(2)
        ->and($summary->for($site, 'product'))
        ->average->toBe(3.0)
        ->count->toBe(1)
        ->and($summary->for($site, 'both'))
        ->average->toBe(4.0)
        ->count->toBe(3)
        ->and($summary->for($site, 'both')['reviews'][0])
        ->toMatchArray([
            'source' => 'product',
            'product_name' => 'Signature item',
            'product_url' => '/products/signature-item',
        ]);
});

it('busts every source cache when a site review is saved or deleted', function () {
    $site = Site::factory()->create();
    $summary = app(TrustSummary::class);

    foreach (['site', 'product', 'both'] as $source) {
        Cache::put(TrustSummary::cacheKey($site->id, $source), ['stale' => true]);
    }

    $review = SiteReview::factory()->approved()->for($site)->create();

    foreach (['site', 'product', 'both'] as $source) {
        expect(Cache::has(TrustSummary::cacheKey($site->id, $source)))->toBeFalse();
        Cache::put(TrustSummary::cacheKey($site->id, $source), ['stale' => true]);
    }

    $review->delete();

    foreach (['site', 'product', 'both'] as $source) {
        expect(Cache::has(TrustSummary::cacheKey($site->id, $source)))->toBeFalse();
    }
});

it('reuses the per-site source cache until explicitly forgotten', function () {
    $site = Site::factory()->create();
    SiteReview::factory()->approved()->for($site)->create(['rating' => 5]);
    $summary = app(TrustSummary::class);

    expect($summary->for($site, 'site'))->count->toBe(1);

    SiteReview::withoutEvents(
        fn () => SiteReview::factory()->approved()->for($site)->create(['rating' => 1]),
    );

    expect($summary->for($site, 'site'))->count->toBe(1);

    $summary->forget($site->id);

    expect($summary->for($site, 'site'))
        ->count->toBe(2)
        ->average->toBe(3.0);
});

it('busts every source cache when product review status changes even with the shop disabled', function () {
    $site = Site::factory()->create(['shop_enabled' => false]);
    $product = Product::factory()->for($site)->create();
    $review = ProductReview::factory()->for($site)->for($product)->create();

    foreach (['site', 'product', 'both'] as $source) {
        Cache::put(TrustSummary::cacheKey($site->id, $source), ['stale' => true]);
    }

    $review->update(['status' => ProductReviewStatus::Published]);

    foreach (['site', 'product', 'both'] as $source) {
        expect(Cache::has(TrustSummary::cacheKey($site->id, $source)))->toBeFalse();
    }
});

// F3 regression seams: the two non-event invalidation paths are
// load-bearing single lines. Wrong implementation these must fail against:
// removing the explicit forget beside the mass update / inside the job.
it('busts the aggregate when client moderation mass-updates a review status', function () {
    $client = Client::factory()->create();
    $site = Site::factory()->create(['client_id' => $client->id]);
    $user = User::factory()->create(['client_id' => $client->id, 'role' => null]);
    $review = SiteReview::factory()->for($site)->create(['status' => SiteReviewStatus::Pending]);

    foreach (['site', 'product', 'both'] as $source) {
        Cache::put(TrustSummary::cacheKey($site->id, $source), ['stale' => true]);
    }

    Livewire::actingAs($user)
        ->test('client.review-moderation', ['siteId' => $site->id])
        ->call('approve', $review->id);

    foreach (['site', 'product', 'both'] as $source) {
        expect(Cache::has(TrustSummary::cacheKey($site->id, $source)))->toBeFalse();
    }
});

it('busts the aggregate when the shop snapshot rebuild job runs', function () {
    $site = Site::factory()->create(['shop_enabled' => true]);

    foreach (['site', 'product', 'both'] as $source) {
        Cache::put(TrustSummary::cacheKey($site->id, $source), ['stale' => true]);
    }

    (new \App\Jobs\Shop\RebuildShopSnapshot($site->id))->handle(app(\App\Services\Shop\SnapshotBuilder::class));

    foreach (['site', 'product', 'both'] as $source) {
        expect(Cache::has(TrustSummary::cacheKey($site->id, $source)))->toBeFalse();
    }
});

