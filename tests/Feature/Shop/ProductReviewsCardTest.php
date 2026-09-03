<?php

use App\Support\Shop\ProductReviews;
use Tests\Support\ProductReviewsFixtures;

test('cards omit rating markup when reviews are disabled', function (string $vertical) {
    $fixture = ProductReviewsFixtures::make($vertical, ['enabled' => false, 'show_on_cards' => true, 'min_reviews_for_card' => 1]);
    $site = $fixture['site'];
    $product = $fixture['products'][0];
    $payload = ProductReviewsFixtures::cardProduct($product, ['rating' => ['avg' => 4.6, 'count' => 8]]);

    $html = view('shop.partials.product-card', ['product' => $payload, 'site' => $site])->render();
    $off = view('shop.partials.product-card', [
        'product' => ProductReviewsFixtures::cardProduct($product),
        'site' => $site,
    ])->render();

    expect(ProductReviews::showOnCard($site, $payload))->toBeFalse()
        ->and($html)->not->toContain('out of 5')
        ->and($html)->not->toContain('aria-label')
        ->and($html)->toBe($off);
})->with(ProductReviewsFixtures::verticalDataset());

test('cards show stars and count when enabled and the min count is met', function (string $vertical) {
    $fixture = ProductReviewsFixtures::make($vertical, ['enabled' => true, 'show_on_cards' => true, 'min_reviews_for_card' => 1]);
    $site = $fixture['site'];
    $product = $fixture['products'][0];
    $payload = ProductReviewsFixtures::cardProduct($product, ['rating' => ['avg' => 4.6, 'count' => 12]]);

    $html = view('shop.partials.product-card', ['product' => $payload, 'site' => $site])->render();

    expect($html)->toContain('aria-label="4.6 out of 5, 12 reviews"')
        ->and($html)->toContain('var(--color-accent)')
        ->and($html)->toContain('<svg');
})->with(ProductReviewsFixtures::verticalDataset());

test('cards hide stars when show_on_cards is off or the min count is unmet', function (string $vertical) {
    $fixture = ProductReviewsFixtures::make($vertical, [
        'enabled' => true,
        'show_on_cards' => true,
        'min_reviews_for_card' => 5,
    ]);
    $site = $fixture['site'];
    $product = $fixture['products'][0];
    $below = ProductReviewsFixtures::cardProduct($product, ['rating' => ['avg' => 5.0, 'count' => 4]]);
    $hidden = ProductReviewsFixtures::make($vertical === 'bakery' ? 'florist' : 'bakery', [
        'enabled' => true,
        'show_on_cards' => false,
        'min_reviews_for_card' => 1,
    ]);
    $hiddenPayload = ProductReviewsFixtures::cardProduct($hidden['products'][0], ['rating' => ['avg' => 4.8, 'count' => 9]]);

    $belowHtml = view('shop.partials.product-card', ['product' => $below, 'site' => $site])->render();
    $hiddenHtml = view('shop.partials.product-card', ['product' => $hiddenPayload, 'site' => $hidden['site']])->render();

    expect($belowHtml)->not->toContain('out of 5')
        ->and($hiddenHtml)->not->toContain('out of 5');
})->with(ProductReviewsFixtures::verticalDataset());
