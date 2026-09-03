<?php

use App\Enums\Shop\ProductReviewSource;
use App\Enums\Shop\ProductReviewStatus;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\Product;
use App\Models\Shop\ProductReview;
use App\Models\Site\SiteDraft;
use App\Services\Site\Editor\Operations\SeedProductReviewsOperation;
use Illuminate\Support\Facades\Bus;
use Tests\Support\CommerceReads;

beforeEach(function () {
    CommerceReads::enableFlags();
    CommerceReads::exposeOnSandbox('seed_product_reviews');
});

test('seed_product_reviews creates published seed reviews scoped to the site', function () {
    [$actor, $site] = CommerceReads::shopSite();
    $product = Product::factory()->for($site)->published()->create(['slug' => 'victoria-sponge']);
    $foreign = Product::factory()->published()->create(['slug' => 'victoria-sponge']);
    Bus::fake([RebuildShopSnapshot::class]);

    $result = CommerceReads::run($actor, $site, 'seed_product_reviews', [
        'composition_revision' => (int) (SiteDraft::query()->where('site_id', $site->id)->value('admin_revision') ?? 0),
        'reviews' => [
            [
                'product_slug' => 'victoria-sponge',
                'rating' => 5,
                'title' => 'Lovely',
                'body' => 'Soft crumb.',
                'author_name' => 'Ada',
            ],
            [
                'product_slug' => 'victoria-sponge',
                'rating' => 4,
                'title' => 'Nice',
                'body' => 'Good jam.',
                'author_name' => 'Sam',
            ],
        ],
    ]);

    expect($result->ok)->toBeTrue()
        ->and($result->data['created'])->toBe(2);

    $rows = ProductReview::query()->where('site_id', $site->id)->where('source', ProductReviewSource::Seed)->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn (ProductReview $review): bool => $review->status === ProductReviewStatus::Published && $review->product_id === $product->id))->toBeTrue()
        ->and(ProductReview::query()->where('site_id', $foreign->site_id)->where('source', ProductReviewSource::Seed)->count())->toBe(0);

    Bus::assertDispatchedTimes(RebuildShopSnapshot::class, 1);
});

test('a foreign product slug is not found and nothing is written', function () {
    [$actor, $site] = CommerceReads::shopSite();
    Product::factory()->published()->create(['slug' => 'foreign-cake']);

    $result = CommerceReads::run($actor, $site, 'seed_product_reviews', [
        'composition_revision' => (int) (SiteDraft::query()->where('site_id', $site->id)->value('admin_revision') ?? 0),
        'reviews' => [[
            'product_slug' => 'foreign-cake',
            'rating' => 5,
            'title' => 'Nope',
            'body' => 'Wrong catalogue.',
            'author_name' => 'Ada',
        ]],
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('not_found')
        ->and(ProductReview::query()->where('site_id', $site->id)->exists())->toBeFalse();
});

test('out of bounds ratings are rejected with a validation error', function () {
    [$actor, $site] = CommerceReads::shopSite();
    Product::factory()->for($site)->published()->create(['slug' => 'victoria-sponge']);

    $result = CommerceReads::run($actor, $site, 'seed_product_reviews', [
        'composition_revision' => (int) (SiteDraft::query()->where('site_id', $site->id)->value('admin_revision') ?? 0),
        'reviews' => [[
            'product_slug' => 'victoria-sponge',
            'rating' => 9,
            'title' => 'Nope',
            'body' => 'Too many stars.',
            'author_name' => 'Ada',
        ]],
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and(ProductReview::query()->where('site_id', $site->id)->exists())->toBeFalse();
});

test('the operation schema describes seed reviews', function () {
    $schema = app(SeedProductReviewsOperation::class)->inputSchema();

    expect($schema['required'])->toContain('reviews')
        ->and($schema['required'])->toContain('composition_revision')
        ->and($schema['properties']['reviews']['items']['properties'])->toHaveKeys(['product_slug', 'rating', 'title', 'body', 'author_name']);
});
