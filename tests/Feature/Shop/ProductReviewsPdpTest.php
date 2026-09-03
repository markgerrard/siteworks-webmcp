<?php

use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\ProductReview;
use App\Services\Shop\SnapshotBuilder;
use App\Support\Shop\ProductFacts;
use Tests\Support\ProductFactsFixtures;
use Tests\Support\ProductReviewsFixtures;

test('a disabled store omits the rating summary reviews section and reviews tab', function (string $vertical) {
    $fixture = ProductReviewsFixtures::make($vertical, ['enabled' => false]);
    $site = $fixture['site'];
    $product = $fixture['products'][0];
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));

    $html = $this->get('http://'.$site->custom_domain.'/products/'.$product->slug)->assertOk()->getContent();

    expect($html)->not->toContain('out of 5')
        ->and($html)->not->toContain('id="product-fact-tab-reviews"')
        ->and($html)->not->toContain('id="product-reviews"')
        ->and($html)->not->toContain('Write a review')
        ->and($html)->toContain($product->description);
})->with(ProductReviewsFixtures::verticalDataset());

test('without fact tabs the reviews list is a section below the description', function (string $vertical) {
    $fixture = ProductReviewsFixtures::make($vertical, ['enabled' => true, 'public_form' => false]);
    $site = $fixture['site'];
    $product = $fixture['products'][0];
    $site->update(['product_fact_groups' => null]);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));

    $html = $this->get('http://'.$site->custom_domain.'/products/'.$product->slug)->assertOk()->getContent();

    expect($html)->toContain('id="product-reviews"')
        ->and($html)->toContain('aria-label="')
        ->and($html)->toContain('out of 5')
        ->and($html)->not->toContain('role="tablist"')
        ->and($html)->not->toContain('Write a review')
        ->and($html)->toContain($product->description)
        ->and(strpos($html, $product->description))->toBeLessThan(strpos($html, 'id="product-reviews"'));
})->with(ProductReviewsFixtures::verticalDataset());

test('with T27 fact tabs Reviews is a built-in tab in the strip', function () {
    $fixture = ProductFactsFixtures::bakery();
    $site = $fixture['site'];
    $product = $fixture['products'][0];
    $site->update(['reviews_settings' => [
        'enabled' => true,
        'label' => 'Reviews',
        'public_form' => false,
        'moderate' => true,
        'show_on_cards' => true,
        'min_reviews_for_card' => 1,
    ]]);
    ProductReview::factory()->for($site)->for($product)->published()->create([
        'rating' => 5,
        'title' => 'Lovely sponge',
        'body' => 'Soft crumb.',
        'author_name' => 'Ada',
    ]);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));

    $html = $this->get('http://'.$site->custom_domain.'/products/'.$product->slug)->assertOk()->getContent();
    $tabs = ProductFacts::visibleTabs($site->product_fact_groups, $product->facts);

    expect($html)->toContain('role="tablist"')
        ->and($html)->toContain('id="product-fact-tab-reviews"')
        ->and($html)->toContain('>Reviews<')
        ->and($html)->toContain('Lovely sponge')
        ->and($html)->toContain('Soft crumb.')
        ->and($html)->toContain('Ada');
    foreach ($tabs as $tab) {
        expect(html_entity_decode($html, ENT_QUOTES))->toContain($tab['label']);
    }
});

test('the reviews list is newest first with ten per page and a show more link', function () {
    $fixture = ProductReviewsFixtures::bakery(['enabled' => true]);
    $site = $fixture['site'];
    $product = $fixture['products'][0];
    ProductReview::query()->where('product_id', $product->id)->delete();
    foreach (range(1, 11) as $i) {
        ProductReview::factory()->for($site)->for($product)->published()->create([
            'title' => 'Review n='.$i,
            'body' => 'Body '.$i,
            'author_name' => 'Author '.$i,
            'created_at' => now()->subMinutes(12 - $i),
        ]);
    }
    $site->update(['product_fact_groups' => null]);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));

    $first = $this->get('http://'.$site->custom_domain.'/products/'.$product->slug)->assertOk()->getContent();
    expect($first)->toContain('>Review n=11<')
        ->and($first)->toContain('>Review n=2<')
        ->and($first)->not->toContain('>Review n=1<')
        ->and($first)->toContain('Show more');

    $second = $this->get('http://'.$site->custom_domain.'/products/'.$product->slug.'?reviews_page=2')->assertOk()->getContent();
    expect($second)->toContain('>Review n=1<')
        ->and($second)->not->toContain('>Review n=11<');
});

test('review copy is escaped on the PDP', function () {
    $fixture = ProductReviewsFixtures::bakery(['enabled' => true]);
    $site = $fixture['site'];
    $product = $fixture['products'][0];
    ProductReview::query()->where('product_id', $product->id)->delete();
    ProductReview::factory()->for($site)->for($product)->published()->create([
        'title' => '<script>alert(1)</script>',
        'body' => '<img src=x onerror=alert(1)>',
        'author_name' => '<b>Ada</b>',
    ]);
    $site->update(['product_fact_groups' => null]);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));

    $html = $this->get('http://'.$site->custom_domain.'/products/'.$product->slug)->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->not->toContain('<img src=x')
        ->and($html)->not->toContain('<b>Ada</b>');
});
