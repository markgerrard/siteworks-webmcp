<?php

use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\ProductReview;
use App\Services\Shop\SnapshotBuilder;
use Tests\Support\ProductReviewsFixtures;

test('disabled stores emit no AggregateRating or Review objects', function () {
    $fixture = ProductReviewsFixtures::disabled();
    $site = $fixture['site'];
    $product = $fixture['products'][0];
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));

    $html = $this->get('http://'.$site->custom_domain.'/products/'.$product->slug)->assertOk()->getContent();
    preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches);
    $productLd = collect($matches[1])->map(fn (string $raw) => json_decode($raw, true))->firstWhere('@type', 'Product');

    expect($productLd)->not->toHaveKey('aggregateRating')
        ->and($productLd)->not->toHaveKey('review');
});

test('enabled stores with published reviews emit AggregateRating and up to five Review objects', function () {
    $fixture = ProductReviewsFixtures::bakery(['enabled' => true]);
    $site = $fixture['site'];
    $product = $fixture['products'][0];
    $site->update(['product_fact_groups' => null]);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));

    $html = $this->get('http://'.$site->custom_domain.'/products/'.$product->slug)->assertOk()->getContent();
    preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches);
    $productLd = collect($matches[1])->map(fn (string $raw) => json_decode($raw, true))->firstWhere('@type', 'Product');

    $count = ProductReview::query()->where('product_id', $product->id)->published()->count();
    expect($productLd['aggregateRating']['@type'])->toBe('AggregateRating')
        ->and((int) $productLd['aggregateRating']['reviewCount'])->toBe($count)
        ->and($productLd['aggregateRating'])->toHaveKey('ratingValue')
        ->and($productLd['review'])->toBeArray()
        ->and(count($productLd['review']))->toBeLessThanOrEqual(5)
        ->and($productLd['review'][0]['@type'])->toBe('Review');
});

test('JSON-LD encodes review copy rather than embedding raw markup', function () {
    $fixture = ProductReviewsFixtures::bakery(['enabled' => true]);
    $site = $fixture['site'];
    $product = $fixture['products'][0];
    ProductReview::query()->where('product_id', $product->id)->delete();
    ProductReview::factory()->for($site)->for($product)->published()->create([
        'title' => 'Nice <script>alert(1)</script>',
        'body' => 'Body & "quotes"',
        'author_name' => 'Ada',
        'rating' => 5,
    ]);
    $site->update(['product_fact_groups' => null]);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));

    $html = $this->get('http://'.$site->custom_domain.'/products/'.$product->slug)->assertOk()->getContent();
    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('\u003Cscript\u003E')
        ->and($html)->toContain('\u0026')
        ->and($html)->toContain('\u0022quotes\u0022');
});
