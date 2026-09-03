<?php

use App\Enums\Shop\ProductReviewSource;
use App\Enums\Shop\ProductReviewStatus;
use App\Models\Shop\Product;
use App\Models\Shop\ProductReview;
use App\Models\Site;
use App\Support\Shop\ProductReviewSettings;
use Illuminate\Validation\ValidationException;

test('new sites default to reviews disabled with the public form off', function () {
    $site = Site::factory()->create();
    $settings = ProductReviewSettings::fromSite($site);

    expect($site->reviews_settings)->toBeNull()
        ->and($settings->enabled)->toBeFalse()
        ->and($settings->label)->toBe('Reviews')
        ->and($settings->publicForm)->toBeFalse()
        ->and($settings->moderate)->toBeTrue()
        ->and($settings->showOnCards)->toBeTrue()
        ->and($settings->minReviewsForCard)->toBe(1);
});

test('a published review round-trips with the reserved invite column and hidden hashes', function () {
    $site = Site::factory()->create();
    $product = Product::factory()->for($site)->published()->create();

    $review = ProductReview::factory()->for($site)->for($product)->published()->shopper()->create([
        'rating' => 4,
        'title' => 'Great sponge',
        'body' => 'Soft crumb, proper jam.',
        'author_name' => 'Ada',
        'author_email_hash' => hash('sha256', 'ada@example.com'),
        'ip_hash' => hash('sha256', '203.0.113.9'),
        'invite_token_hash' => null,
    ]);

    $fresh = $review->fresh();

    expect($fresh->site_id)->toBe($site->id)
        ->and($fresh->product_id)->toBe($product->id)
        ->and($fresh->rating)->toBe(4)
        ->and($fresh->title)->toBe('Great sponge')
        ->and($fresh->body)->toBe('Soft crumb, proper jam.')
        ->and($fresh->author_name)->toBe('Ada')
        ->and($fresh->status)->toBe(ProductReviewStatus::Published)
        ->and($fresh->source)->toBe(ProductReviewSource::Shopper)
        ->and($fresh->invite_token_hash)->toBeNull()
        ->and($fresh->author_email_hash)->toBe(hash('sha256', 'ada@example.com'))
        ->and($fresh->toArray())->not->toHaveKey('author_email_hash')
        ->and($fresh->toArray())->not->toHaveKey('ip_hash')
        ->and($fresh->toArray())->not->toHaveKey('invite_token_hash');
});

test('source invited and the invite token hash are reserved for a later track', function () {
    $site = Site::factory()->create();
    $product = Product::factory()->for($site)->create();

    $review = ProductReview::factory()->for($site)->for($product)->invited()->create([
        'invite_token_hash' => hash('sha256', 'one-time-token'),
    ]);

    expect($review->fresh()->source)->toBe(ProductReviewSource::Invited)
        ->and($review->fresh()->invite_token_hash)->toBe(hash('sha256', 'one-time-token'));
});

test('validation rejects rating bounds title body and author length', function (array $attrs, string $field) {
    $site = Site::factory()->create();
    $product = Product::factory()->for($site)->create();

    try {
        ProductReview::validatedCreate([
            'site_id' => $site->id,
            'product_id' => $product->id,
            'rating' => 5,
            'title' => 'Nice',
            'body' => 'A solid buy.',
            'author_name' => 'Ada',
            'status' => ProductReviewStatus::Pending->value,
            'source' => ProductReviewSource::Shopper->value,
            ...$attrs,
        ]);
        expect(false)->toBeTrue();
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }
})->with([
    'rating zero' => [['rating' => 0], 'rating'],
    'rating six' => [['rating' => 6], 'rating'],
    'title too long' => [['title' => str_repeat('T', 81)], 'title'],
    'body too long' => [['body' => str_repeat('B', 2001)], 'body'],
    'author too long' => [['author_name' => str_repeat('A', 61)], 'author_name'],
]);

test('a review cannot be stored against another site\'s product', function () {
    $site = Site::factory()->create();
    $other = Site::factory()->create();
    $foreign = Product::factory()->for($other)->create();

    expect(fn () => ProductReview::validatedCreate([
        'site_id' => $site->id,
        'product_id' => $foreign->id,
        'rating' => 5,
        'title' => 'Nope',
        'body' => 'Wrong catalogue.',
        'author_name' => 'Ada',
        'status' => ProductReviewStatus::Pending->value,
        'source' => ProductReviewSource::Shopper->value,
    ]))->toThrow(ValidationException::class);
});

test('reviews_settings rejects an empty label and a min count below one', function () {
    expect(fn () => ProductReviewSettings::validate([
        'enabled' => true,
        'label' => '',
        'public_form' => false,
        'moderate' => true,
        'show_on_cards' => true,
        'min_reviews_for_card' => 0,
    ]))->toThrow(ValidationException::class);
});
