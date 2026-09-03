<?php

use App\Models\Shop\Product;
use App\Models\Shop\ProductReview;
use App\Models\Site;
use App\Models\SiteReview;
use Tests\Support\ProductReviewsFixtures;

function renderTrustStrip(Site $site, array $section): string
{
    return view('site.sections.trust_strip', [
        'section' => array_merge([
            'type' => 'trust_strip',
            'heading' => 'What customers say',
            'reviews_label' => 'reviews',
            'sources' => 'both',
            'layout' => 'strip',
            'min_reviews' => 3,
        ], $section),
        'sectionIndex' => 0,
        'pageId' => 1,
        'emitMarkers' => false,
        'site' => $site,
    ])->render();
}

function seedTrustStripMatrixSite(): Site
{
    $site = Site::factory()->create();
    $product = Product::factory()->published()->for($site)->create([
        'name' => 'Linked item',
        'slug' => 'linked-item',
    ]);

    SiteReview::factory()->approved()->count(2)->for($site)->sequence(
        ['rating' => 5, 'text' => 'Site quote one', 'created_at' => now()->subHours(2)],
        ['rating' => 4, 'text' => 'Site quote two', 'created_at' => now()->subHour()],
    )->create();
    ProductReview::factory()->published()->count(2)->for($site)->for($product)->sequence(
        ['rating' => 3, 'body' => 'Product quote one', 'created_at' => now()->subMinutes(2)],
        ['rating' => 2, 'body' => 'Product quote two', 'created_at' => now()->subMinute()],
    )->create();

    return $site;
}

dataset('trust strip sources', [
    'site' => ['site', 'Site quote one', 'Product quote one', 2],
    'product' => ['product', 'Product quote one', 'Site quote one', 2],
    'both' => ['both', 'Product quote one', null, 4],
]);

dataset('trust strip layouts', [
    'strip' => ['strip', false],
    'carousel' => ['carousel', true],
]);

dataset('trust strip thresholds', [
    'at threshold' => [true, 2],
    'below threshold' => [false, 5],
]);

dataset('trust strip external badge', [
    'without external badge' => [false],
    'with external badge' => [true],
]);

it('renders the sources layout threshold and external matrix', function (
    string $sources,
    string $includedQuote,
    ?string $excludedQuote,
    int $count,
    string $layout,
    bool $carousel,
    bool $visible,
    int $minimum,
    bool $withExternal,
) {
    $site = seedTrustStripMatrixSite();
    $external = $withExternal ? [
        'label' => 'Independent score',
        'url' => 'https://reviews.example.test/profile',
        'rating' => 4.7,
        'count' => 81,
    ] : null;

    $html = renderTrustStrip($site, compact('sources', 'layout') + [
        'min_reviews' => $minimum,
        'external' => $external,
        'reviews_label' => 'recommendations',
    ]);

    if (! $visible) {
        expect(trim($html))->toBe('');

        return;
    }

    expect($html)
        ->toContain('data-trust-strip')
        ->toContain($includedQuote)
        ->toContain($count.' recommendations')
        ->toContain('out of 5, '.$count.' recommendations')
        ->when($excludedQuote !== null, fn ($expectation) => $expectation->not->toContain($excludedQuote))
        ->when($carousel, fn ($expectation) => $expectation
            ->toContain('scroll-snap-type: x mandatory')
            ->toContain('aria-label="Previous recommendations"'))
        ->when(! $carousel, fn ($expectation) => $expectation->not->toContain('scroll-snap-type: x mandatory'))
        ->when($withExternal, fn ($expectation) => $expectation
            ->toContain('href="https://reviews.example.test/profile"')
            ->toContain('Independent score')
            ->toContain('4.7'))
        ->when(! $withExternal, fn ($expectation) => $expectation->not->toContain('Independent score'));
})->with('trust strip sources')
    ->with('trust strip layouts')
    ->with('trust strip thresholds')
    ->with('trust strip external badge');

it('renders the bakery fixture with four site testimonials and product reviews in both layouts', function () {
    $fixture = ProductReviewsFixtures::bakery();
    SiteReview::factory()->approved()->count(4)->for($fixture['site'])->sequence(
        ['text' => 'Fresh every morning'],
        ['text' => 'A dependable favourite'],
        ['text' => 'Thoughtfully made'],
        ['text' => 'Always welcoming'],
    )->create();

    $strip = renderTrustStrip($fixture['site'], ['layout' => 'strip', 'sources' => 'site']);
    $carousel = renderTrustStrip($fixture['site'], ['layout' => 'carousel', 'sources' => 'both']);

    expect($strip)
        ->toContain('Always welcoming')
        ->toContain('4 reviews')
        ->not->toContain('scroll-snap-type: x mandatory')
        ->and($carousel)
        ->toContain('scroll-snap-type: x mandatory')
        ->toContain('Published review');
});

it('renders the florist fixture from product reviews only with muted product links', function () {
    $fixture = ProductReviewsFixtures::florist();

    $html = renderTrustStrip($fixture['site'], ['sources' => 'product', 'layout' => 'carousel']);

    expect($html)
        ->toContain('Published review')
        ->toContain('/products/')
        ->toContain('Hand-tied bouquet')
        ->not->toContain('data-trust-sources="site"');
});

it('renders nothing for the below-threshold fixture', function () {
    $site = Site::factory()->create();
    SiteReview::factory()->approved()->count(2)->for($site)->create();

    expect(trim(renderTrustStrip($site, ['sources' => 'site', 'min_reviews' => 3])))->toBe('');
});

it('uses the same star svg path as product review cards', function () {
    $site = seedTrustStripMatrixSite();
    $html = renderTrustStrip($site, []);
    $productStars = view('shop.partials.product-rating-stars', ['avg' => 4.5, 'count' => 4])->render();
    preg_match('/d="([^"]+)"/', $productStars, $matches);

    expect($matches[1] ?? null)->not->toBeNull()
        ->and($html)->toContain($matches[1]);
});
