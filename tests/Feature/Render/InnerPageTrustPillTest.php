<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Two-slot reviews pattern on inner pages: the reviews_badge section is
 * mobile-only (md:hidden strip), and desktop is served by the hero's
 * inline trust pill — which was home-only. On inner pages the pill
 * renders exactly when the page's sections include a reviews_badge, so
 * the injected section acts as the per-page opt-in for BOTH viewports.
 */
function publishAboutPage(Site $site, array $sections): GeneratedPage
{
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'about']);
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

    return $page;
}

function reviewsSite(): Site
{
    return Site::factory()->create([
        'reviews_cache' => ['rating' => 5.0, 'user_ratings_total' => 24, 'url' => 'https://g.co/x', 'reviews' => []],
    ]);
}

it('renders the desktop trust pill in an inner-page hero when the page has a reviews_badge', function () {
    $site = reviewsSite();
    $page = publishAboutPage($site, [
        ['type' => 'hero', 'title' => 'About Us'],
        ['type' => 'reviews_badge', 'provider' => 'google'],
        ['type' => 'story', 'title' => 'Our Story', 'body' => 'Once upon a time.'],
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('md:inline-flex')   // desktop pill
        ->and($html)->toContain('md:hidden');    // mobile badge strip
});

it('renders no trust pill on inner pages without a reviews_badge section', function () {
    $site = reviewsSite();
    $page = publishAboutPage($site, [
        ['type' => 'hero', 'title' => 'About Us'],
        ['type' => 'story', 'title' => 'Our Story', 'body' => 'Once upon a time.'],
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->not->toContain('md:inline-flex');
});

/*
 * reviews_hero_badge_mode — per-site three-state for the header-area
 * trust elements (desktop hero pill + mobile badge strip):
 *   on        — everywhere the auto-include gate passes (default)
 *   home_only — home hero keeps them, inner pages hide them
 *   off       — hidden everywhere
 */

function publishHomePage(Site $site): GeneratedPage
{
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Welcome'], ['type' => 'reviews_badge', 'provider' => 'google']]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);
    $version = SiteVersion::create([
        'site_id' => $site->id, 'version' => 1,
        'composition' => [
            'nav' => ['items' => []], 'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'homepage_page_id' => $page->id,
        ],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    return $page;
}

it('mode off hides pill and badge everywhere', function () {
    $site = reviewsSite();
    $site->update(['reviews_hero_badge_mode' => \App\Enums\ReviewsHeroBadgeMode::Off]);
    $page = publishAboutPage($site, [
        ['type' => 'hero', 'title' => 'About Us'],
        ['type' => 'reviews_badge', 'provider' => 'google'],
        ['type' => 'story', 'title' => 'Our Story', 'body' => 'Once.'],
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->not->toContain('md:inline-flex')
        ->and($html)->not->toContain('Google Reviews');
});

it('mode off hides the home hero pill too', function () {
    $site = reviewsSite();
    $site->update(['reviews_hero_badge_mode' => \App\Enums\ReviewsHeroBadgeMode::Off]);
    $page = publishHomePage($site);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->not->toContain('md:inline-flex');
});

it('mode home_only keeps pill and strip on home', function () {
    $site = reviewsSite();
    $site->update(['reviews_hero_badge_mode' => \App\Enums\ReviewsHeroBadgeMode::HomeOnly]);
    $page = publishHomePage($site);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('md:inline-flex')
        ->and($html)->toContain('Google Reviews');
});

it('mode home_only hides pill and strip on inner pages', function () {
    $site = reviewsSite();
    $site->update(['reviews_hero_badge_mode' => \App\Enums\ReviewsHeroBadgeMode::HomeOnly]);
    $page = publishAboutPage($site, [
        ['type' => 'hero', 'title' => 'About Us'],
        ['type' => 'reviews_badge', 'provider' => 'google'],
        ['type' => 'story', 'title' => 'Our Story', 'body' => 'Once.'],
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->not->toContain('md:inline-flex')
        ->and($html)->not->toContain('Google Reviews');
});

it('mode on (default) renders pill and strip on badge-bearing inner pages', function () {
    $site = reviewsSite();
    $page = publishAboutPage($site, [
        ['type' => 'hero', 'title' => 'About Us'],
        ['type' => 'reviews_badge', 'provider' => 'google'],
        ['type' => 'story', 'title' => 'Our Story', 'body' => 'Once.'],
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('md:inline-flex')
        ->and($html)->toContain('Google Reviews');
});
