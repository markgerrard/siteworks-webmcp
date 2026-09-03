<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Local mirror of HomeLayoutToggleTest's makeHomePageForLayoutTest helper.
 *
 * @return array{0: Site, 1: GeneratedPage}
 */
function makeHomePageForReviewsProviderTest(array $sections, string $pageType = 'home'): array
{
    $site = Site::factory()->create(['business_name' => 'Acme Plumbing', 'theme' => 'trades-bold']);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => $pageType]);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => $sections],
    ]);
    $page->update(['published_revision_id' => $rev->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'homepage_page_id' => $page->id,
        ],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    return [$site, $page];
}

function checkatradeReviewsCache(): array
{
    return [
        'provider' => 'checkatrade',
        'rating' => 9.9,
        'rating_scale' => 10,
        'user_ratings_total' => 6,
        'url' => 'https://www.checkatrade.com/trades/edenrenovations',
        'reviews' => [
            [
                'author_name' => 'Jo B',
                'rating' => 10,
                'text' => 'Superb Checkatrade work, tidy and quick.',
                'relative_time_description' => 'a month ago',
            ],
            [
                'author_name' => 'Sam K',
                'rating' => 9,
                'text' => 'Great communication throughout the job.',
                'relative_time_description' => '2 months ago',
            ],
        ],
    ];
}

function googleReviewsCache(): array
{
    return [
        'rating' => 4.8,
        'user_ratings_total' => 27,
        'url' => 'https://maps.example/acme',
        'reviews' => [
            [
                'author_name' => 'Jo B',
                'rating' => 5,
                'text' => 'Superb work, tidy and quick.',
                'relative_time_description' => 'a month ago',
            ],
            [
                'author_name' => 'Sam K',
                'rating' => 5,
                'text' => 'Great communication throughout.',
                'relative_time_description' => '2 months ago',
            ],
        ],
    ];
}

it('renders checkatrade attribution, scale, and provider marker on reviews_summary', function () {
    [$site, $page] = makeHomePageForReviewsProviderTest([
        [
            'type' => 'reviews_summary',
            'heading' => 'What clients say',
            'provider' => 'checkatrade',
            'max_items' => 4,
        ],
    ]);
    $site->update(['reviews_cache' => checkatradeReviewsCache()]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('data-reviews-provider="checkatrade"')
        ->and($html)->toContain('Checkatrade')
        ->and($html)->toContain('9.9/10')
        ->and($html)->not->toContain('Google')
        ->and($html)->toContain('https://www.checkatrade.com/trades/edenrenovations')
        ->and($html)->not->toContain('#4285F4');
});

it('default google reviews_summary keeps Google branding and omits provider marker', function () {
    [$site, $page] = makeHomePageForReviewsProviderTest([
        ['type' => 'reviews_summary', 'heading' => 'What clients say', 'max_items' => 4],
    ]);
    $site->update(['reviews_cache' => googleReviewsCache()]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->not->toContain('data-reviews-provider=')
        ->and($html)->toContain('Google')
        ->and($html)->toContain('4.8')
        ->and($html)->not->toContain('4.8/10')
        ->and($html)->not->toContain('Checkatrade')
        ->and($html)->toContain('#4285F4');
});

it('grid variant and checkatrade provider compose together', function () {
    [$site, $page] = makeHomePageForReviewsProviderTest([
        [
            'type' => 'reviews_summary',
            'heading' => 'What clients say',
            'variant' => 'grid',
            'provider' => 'checkatrade',
            'max_items' => 4,
        ],
    ]);
    $site->update(['reviews_cache' => checkatradeReviewsCache()]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('data-reviews-variant="grid"')
        ->and($html)->toContain('data-reviews-provider="checkatrade"')
        ->and($html)->toContain('Checkatrade')
        ->and($html)->toContain('9.9/10')
        ->and($html)->not->toContain('Google')
        ->and($html)->not->toContain('reviews-carousel-');
});

it('hero trust pill is Checkatrade-aware from reviews_cache provider', function () {
    [$site, $page] = makeHomePageForReviewsProviderTest([
        ['type' => 'hero', 'title' => 'Welcome to Acme'],
    ]);
    $site->update(['reviews_cache' => checkatradeReviewsCache()]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    // Checkatrade pills carry the wordmark alone — no cached count, no
    // "reviews" filler (the cached sample undersells the live profile).
    expect($html)->toContain('aria-label="Checkatrade"')
        ->and($html)->toContain('9.9/10')
        ->and($html)->not->toContain('Checkatrade reviews')
        ->and($html)->not->toContain('Google Reviews')
        ->and($html)->not->toContain('on Google')
        ->and($html)->not->toContain('#4285F4');
});

it('hero trust pill keeps Google branding when cache has no provider key', function () {
    [$site, $page] = makeHomePageForReviewsProviderTest([
        ['type' => 'hero', 'title' => 'Welcome to Acme'],
    ]);
    $site->update(['reviews_cache' => googleReviewsCache()]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('Google Reviews')
        ->and($html)->toContain('4.8')
        ->and($html)->not->toContain('4.8/10')
        ->and($html)->not->toContain('Checkatrade')
        ->and($html)->toContain('#4285F4');
});

it('parallax reviews_summary attribution row inherits white from hasParallax fork', function () {
    [$site, $page] = makeHomePageForReviewsProviderTest([
        [
            'type' => 'reviews_summary',
            'heading' => 'What our customers say',
            'provider' => 'checkatrade',
            'background_image' => 'https://cdn.example.com/parallax-bg.jpg',
            'max_items' => 4,
        ],
    ]);
    $site->update(['reviews_cache' => checkatradeReviewsCache()]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    // Attribution footer must carry the fork's white + lift shadow so the
    // Checkatrade currentColor shield and divider dots are visible on the wash.
    expect($html)->toMatch(
        '/mt-10 flex flex-wrap items-center justify-center gap-x-3 gap-y-2 text-sm"[^>]*style="color: #ffffff; text-shadow: 0 2px 8px rgba\(0,0,0,0\.5\), 0 1px 3px rgba\(0,0,0,0\.4\);"/'
    )
        ->and($html)->toContain('stroke="currentColor"')
        ->and($html)->toContain('data-reviews-provider="checkatrade"')
        ->and($html)->toContain('9.9/10');
});

it('light-path reviews_summary attribution keeps token colours (no parallax white)', function () {
    [$site, $page] = makeHomePageForReviewsProviderTest([
        [
            'type' => 'reviews_summary',
            'heading' => 'What clients say',
            'provider' => 'checkatrade',
            'max_items' => 4,
        ],
    ]);
    $site->update(['reviews_cache' => checkatradeReviewsCache()]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toMatch(
        '/mt-10 flex flex-wrap items-center justify-center gap-x-3 gap-y-2 text-sm"[^>]*style="color: var\(--color-text\); text-shadow: none;"/'
    )
        ->and($html)->toContain('color: var(--brand-primary)')
        ->and($html)->not->toContain('parallax-bg.jpg')
        ->and($html)->toContain('Checkatrade')
        ->and($html)->toContain('9.9/10');
});

it('parallax grid variant attribution row also uses white fork colours', function () {
    [$site, $page] = makeHomePageForReviewsProviderTest([
        [
            'type' => 'reviews_summary',
            'heading' => 'What clients say',
            'variant' => 'grid',
            'provider' => 'checkatrade',
            'background_image' => 'https://cdn.example.com/parallax-bg.jpg',
            'max_items' => 4,
        ],
    ]);
    $site->update(['reviews_cache' => checkatradeReviewsCache()]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('data-reviews-variant="grid"')
        ->and($html)->toMatch(
            '/mt-10 flex flex-wrap items-center justify-center gap-x-3 gap-y-2 text-sm"[^>]*style="color: #ffffff; text-shadow: 0 2px 8px rgba\(0,0,0,0\.5\), 0 1px 3px rgba\(0,0,0,0\.4\);"/'
        );
});

it('reviews_summary brands from cache provider over google-stamped section', function () {
    // Facet C: ArchetypeComposer stamps section.provider=google; cache is
    // authoritative so Checkatrade reviews_cache wins on every surface.
    [$site, $page] = makeHomePageForReviewsProviderTest([
        [
            'type' => 'reviews_summary',
            'heading' => 'What clients say',
            'provider' => 'google',
            'max_items' => 4,
        ],
    ]);
    $site->update(['reviews_cache' => checkatradeReviewsCache()]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('data-reviews-provider="checkatrade"')
        ->and($html)->toContain('Checkatrade')
        ->and($html)->toContain('9.9/10')
        ->and($html)->not->toContain('Google')
        ->and($html)->not->toContain('#4285F4');
});

it('reviews section brands from cache provider over google-stamped section', function () {
    [$site, $page] = makeHomePageForReviewsProviderTest([
        [
            'type' => 'reviews',
            'show_rating' => true,
            'provider' => 'google',
            'max_items' => 5,
        ],
    ]);
    $site->update(['reviews_cache' => checkatradeReviewsCache()]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('data-reviews-provider="checkatrade"')
        ->and($html)->toContain('Checkatrade')
        ->and($html)->toContain('9.9/10')
        ->and($html)->not->toContain('Google')
        ->and($html)->not->toContain('#4285F4');
});

it('reviews_badge brands from cache provider over google-stamped section', function () {
    [$site, $page] = makeHomePageForReviewsProviderTest([
        [
            'type' => 'hero',
            'title' => 'Welcome to Acme',
        ],
        [
            'type' => 'reviews_badge',
            'provider' => 'google',
        ],
    ]);
    $site->update(['reviews_cache' => checkatradeReviewsCache()]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('data-reviews-provider="checkatrade"')
        ->and($html)->toContain('Checkatrade')
        ->and($html)->toContain('9.9/10')
        ->and($html)->not->toContain('Google Reviews')
        ->and($html)->not->toContain('#4285F4');
});

it('google cache with unset section provider keeps Google branding', function () {
    [$site, $page] = makeHomePageForReviewsProviderTest([
        ['type' => 'reviews_summary', 'heading' => 'What clients say', 'max_items' => 4],
    ]);
    $site->update(['reviews_cache' => googleReviewsCache()]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->not->toContain('data-reviews-provider=')
        ->and($html)->toContain('Google')
        ->and($html)->toContain('4.8')
        ->and($html)->not->toContain('4.8/10')
        ->and($html)->not->toContain('Checkatrade')
        ->and($html)->toContain('#4285F4');
});

it('checkatrade wordmark survives the hide-count toggle in reviews_summary', function () {
    [$site, $page] = makeHomePageForReviewsProviderTest([
        ['type' => 'reviews_summary', 'heading' => 'What clients say'],
    ]);
    $site->update([
        'reviews_cache' => checkatradeReviewsCache(),
        'reviews_show_count_in_summary' => false,
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    // The wordmark is provider attribution, not a count — hiding the count
    // must not silently drop the brand mark.
    expect($html)->toContain('aria-label="Checkatrade"');
});
