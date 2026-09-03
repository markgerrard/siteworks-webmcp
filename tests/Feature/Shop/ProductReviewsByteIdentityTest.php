<?php

use App\Jobs\Shop\RebuildShopSnapshot;
use App\Services\Shop\SnapshotBuilder;
use Tests\Support\ProductFactsFixtures;
use Tests\Support\ProductReviewsFixtures;

test('a disabled reviews store keeps card and PDP bytes free of rating markup', function () {
    $fixture = ProductReviewsFixtures::disabled();
    $site = $fixture['site'];
    $product = $fixture['products'][0];
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));
    $json = app(SnapshotBuilder::class)->build($site->id);
    $row = $json['products'][$product->slug];

    expect($row)->toHaveKey('rating');

    $card = view('shop.partials.product-card', ['site' => $site, 'product' => $row])->render();
    $withoutRating = $row;
    unset($withoutRating['rating']);
    $cardOff = view('shop.partials.product-card', ['site' => $site, 'product' => $withoutRating])->render();

    expect($card)->toBe($cardOff)
        ->and($card)->not->toContain('out of 5')
        ->and($card)->not->toContain('product-reviews');

    $pdp = $this->get('http://'.$site->custom_domain.'/products/'.$product->slug)->assertOk()->getContent();
    expect($pdp)->not->toContain('id="product-reviews"')
        ->not->toContain('id="product-fact-tab-reviews"')
        ->not->toContain('Write a review')
        ->toContain("</div>\n                <div class=\"mb-6\">".e($product->description)."</div>\n");
});

test('a default site with no reviews_settings matches the zero-group PDP description bytes', function () {
    $fixture = ProductFactsFixtures::empty();
    $site = $fixture['site'];
    $product = $fixture['products'][0];
    expect($site->reviews_settings)->toBeNull();
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));

    $pdp = $this->get('http://'.$site->custom_domain.'/products/'.$product->slug)->assertOk()->getContent();
    expect($pdp)->toContain("</div>\n                <div class=\"mb-6\">".e($product->description)."</div>\n")
        ->and($pdp)->not->toContain('id="product-reviews"');
});
