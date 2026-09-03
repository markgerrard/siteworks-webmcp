<?php

use App\Models\Site;
use App\Services\Site\ArchetypeComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['site.reviews_auto_include_enabled' => true]);
});

function trustedSite(array $overrides = []): Site
{
    return Site::factory()->create(array_merge([
        'review_provider' => 'google',
        'review_place_id' => 'ChIJabc',
        'review_place_id_source' => 'manual_url',
        'reviews_cache' => ['rating' => 4.8, 'user_ratings_total' => 50, 'url' => 'x', 'reviews' => []],
    ], $overrides));
}

test('inserts reviews_summary after the last what-we-do anchor on home', function () {
    $site = trustedSite();
    $home = ['sections' => [['type' => 'hero'], ['type' => 'trust'], ['type' => 'services'], ['type' => 'process']]];
    $out = app(ArchetypeComposer::class)->injectReviewsSummaryOnHome($home, $site);

    // Anchors present: trust (i=1), services (i=2). Last wins → insert at 3.
    expect(array_column($out['sections'], 'type'))
        ->toBe(['hero', 'trust', 'services', 'reviews_summary', 'process']);
    expect($out['sections'][3]['display_style'])->toBe('carousel');
    expect($out['sections'][3]['max_items'])->toBe(20);
});

test('falls back to index 1 (after hero) when no anchor sections are present', function () {
    $site = trustedSite();
    $home = ['sections' => [['type' => 'hero'], ['type' => 'contact_form']]];
    $out = app(ArchetypeComposer::class)->injectReviewsSummaryOnHome($home, $site);

    expect(array_column($out['sections'], 'type'))
        ->toBe(['hero', 'reviews_summary', 'contact_form']);
});

test('skips when source is name_lookup (untrusted)', function () {
    $site = trustedSite(['review_place_id_source' => 'name_lookup']);
    $home = ['sections' => [['type' => 'hero']]];
    $out = app(ArchetypeComposer::class)->injectReviewsSummaryOnHome($home, $site);
    expect($out['sections'])->toHaveCount(1);
});

test('skips when rating below 4.0', function () {
    $site = trustedSite(['reviews_cache' => ['rating' => 3.5, 'user_ratings_total' => 50, 'url' => 'x', 'reviews' => []]]);
    $home = ['sections' => [['type' => 'hero']]];
    expect(app(ArchetypeComposer::class)->injectReviewsSummaryOnHome($home, $site)['sections'])->toHaveCount(1);
});

test('skips when count below 3', function () {
    $site = trustedSite(['reviews_cache' => ['rating' => 5.0, 'user_ratings_total' => 2, 'url' => 'x', 'reviews' => []]]);
    $home = ['sections' => [['type' => 'hero']]];
    expect(app(ArchetypeComposer::class)->injectReviewsSummaryOnHome($home, $site)['sections'])->toHaveCount(1);
});

test('skips when feature flag is off', function () {
    config(['site.reviews_auto_include_enabled' => false]);
    $site = trustedSite();
    $home = ['sections' => [['type' => 'hero']]];
    expect(app(ArchetypeComposer::class)->injectReviewsSummaryOnHome($home, $site)['sections'])->toHaveCount(1);
});

test('inserts reviews on about after intro section', function () {
    $site = trustedSite();
    $about = ['sections' => [['type' => 'intro'], ['type' => 'cta_band']]];
    $out = app(ArchetypeComposer::class)->injectReviewsOnAbout($about, $site);
    expect(collect($out['sections'])->pluck('type')->all())
        ->toBe(['intro', 'reviews_badge', 'reviews', 'cta_band']);
});

test('skips when source is scrape_link (deferred until fuzzy-match implemented)', function () {
    $site = trustedSite(['review_place_id_source' => 'scrape_link']);
    $home = ['sections' => [['type' => 'hero']]];
    $out = app(ArchetypeComposer::class)->injectReviewsSummaryOnHome($home, $site);
    expect($out['sections'])->toHaveCount(1);
});

test('accepts manual_id source (canonical Place ID pasted directly)', function () {
    $site = trustedSite(['review_place_id_source' => 'manual_id']);
    $home = ['sections' => [['type' => 'hero'], ['type' => 'services']]];
    $out = app(ArchetypeComposer::class)->injectReviewsSummaryOnHome($home, $site);
    expect($out['sections'][2]['type'])->toBe('reviews_summary');
});

test('double-call does not insert two reviews_summary sections', function () {
    $site = trustedSite();
    $home = ['sections' => [['type' => 'hero'], ['type' => 'services']]];
    $out1 = app(ArchetypeComposer::class)->injectReviewsSummaryOnHome($home, $site);
    $out2 = app(ArchetypeComposer::class)->injectReviewsSummaryOnHome($out1, $site);
    expect($out2['sections'])->toHaveCount(3);
});

test('double-call on about does not insert two reviews sections', function () {
    $site = trustedSite();
    $about = ['sections' => [['type' => 'intro'], ['type' => 'cta_band']]];
    $out1 = app(ArchetypeComposer::class)->injectReviewsOnAbout($about, $site);
    $out2 = app(ArchetypeComposer::class)->injectReviewsOnAbout($out1, $site);
    expect($out2['sections'])->toHaveCount(4); // intro, reviews_badge, reviews, cta_band
});

test('reviews_badge injects directly after the hero on home', function () {
    $site = trustedSite();
    $home = ['sections' => [['type' => 'hero'], ['type' => 'services'], ['type' => 'contact_form']]];
    $out = app(ArchetypeComposer::class)->injectReviewsBadgeOnHome($home, $site);

    expect(array_column($out['sections'], 'type'))
        ->toBe(['hero', 'reviews_badge', 'services', 'contact_form']);
});

test('reviews_badge respects the same auto-include gate as reviews_summary', function () {
    $site = trustedSite(['review_place_id_source' => 'name_lookup']);
    $home = ['sections' => [['type' => 'hero']]];
    expect(app(ArchetypeComposer::class)->injectReviewsBadgeOnHome($home, $site)['sections'])
        ->toHaveCount(1);
});

test('reviews_badge double-call does not insert twice', function () {
    $site = trustedSite();
    $home = ['sections' => [['type' => 'hero'], ['type' => 'services']]];
    $out1 = app(ArchetypeComposer::class)->injectReviewsBadgeOnHome($home, $site);
    $out2 = app(ArchetypeComposer::class)->injectReviewsBadgeOnHome($out1, $site);
    expect($out2['sections'])->toHaveCount(3);
});

test('badge + summary together place trust strip up top, full block lower down', function () {
    $site = trustedSite();
    $composer = app(ArchetypeComposer::class);
    $home = ['sections' => [['type' => 'hero'], ['type' => 'services'], ['type' => 'why_choose_us'], ['type' => 'process']]];

    $home = $composer->injectReviewsBadgeOnHome($home, $site);
    $home = $composer->injectReviewsSummaryOnHome($home, $site);

    expect(array_column($home['sections'], 'type'))->toBe([
        'hero', 'reviews_badge', 'services', 'why_choose_us', 'reviews_summary', 'process',
    ]);
});

/*
 * About-page placement: reviews are social proof and belong AFTER the
 * page's content, just before the closing CTA — not as a full-width slab
 * between the hero and the story. The old rule ("after intro, else index
 * 1") degraded to hero-then-reviews on pages without an intro section
 */

test('about reviews insert after content, before the trailing CTA', function () {
    $site = trustedSite();
    $about = ['sections' => [
        ['type' => 'hero'], ['type' => 'story'], ['type' => 'values'], ['type' => 'cta_band'],
    ]];

    $out = app(ArchetypeComposer::class)->injectReviewsOnAbout($about, $site);

    expect(collect($out['sections'])->pluck('type')->all())
        ->toBe(['hero', 'reviews_badge', 'story', 'values', 'reviews', 'cta_band']);
});

test('about reviews append at the end when there is no trailing CTA', function () {
    $site = trustedSite();
    $about = ['sections' => [['type' => 'hero'], ['type' => 'story']]];

    $out = app(ArchetypeComposer::class)->injectReviewsOnAbout($about, $site);

    expect(collect($out['sections'])->pluck('type')->all())
        ->toBe(['hero', 'reviews_badge', 'story', 'reviews']);
});

test('about also gets a thin reviews_badge directly under the hero', function () {
    $site = trustedSite();
    $about = ['sections' => [
        ['type' => 'hero'], ['type' => 'story'], ['type' => 'values'], ['type' => 'cta_band'],
    ]];

    $out = app(ArchetypeComposer::class)->injectReviewsOnAbout($about, $site);

    expect(collect($out['sections'])->pluck('type')->all())
        ->toBe(['hero', 'reviews_badge', 'story', 'values', 'reviews', 'cta_band']);
});

test('double-call on about does not duplicate the reviews_badge', function () {
    $site = trustedSite();
    $about = ['sections' => [['type' => 'hero'], ['type' => 'story']]];

    $composer = app(ArchetypeComposer::class);
    $out = $composer->injectReviewsOnAbout($composer->injectReviewsOnAbout($about, $site), $site);

    expect(collect($out['sections'])->pluck('type')->filter(fn ($t) => $t === 'reviews_badge'))->toHaveCount(1)
        ->and(collect($out['sections'])->pluck('type')->filter(fn ($t) => $t === 'reviews'))->toHaveCount(1);
});

test('failed gate adds neither badge nor carousel on about', function () {
    $site = trustedSite(['reviews_cache' => ['rating' => 3.2, 'user_ratings_total' => 50, 'url' => 'x', 'reviews' => []]]);
    $about = ['sections' => [['type' => 'hero'], ['type' => 'story']]];

    $out = app(ArchetypeComposer::class)->injectReviewsOnAbout($about, $site);

    expect(collect($out['sections'])->pluck('type')->all())->toBe(['hero', 'story']);
});

test('setHeroBadgeMode persists the chosen mode and rejects unknown values', function () {
    $site = trustedSite(['created_by_user_id' => \App\Models\User::factory()->staff(\App\Enums\AgentRole::Admin)->create()->id]);
    // fresh(): the in-memory factory model doesn't carry DB column defaults.
    expect($site->fresh()->reviews_hero_badge_mode)->toBe(\App\Enums\ReviewsHeroBadgeMode::On);

    $lw = \Livewire\Livewire::actingAs(\App\Models\User::find($site->created_by_user_id))
        ->test('google-reviews-panel', ['siteId' => $site->id]);

    $lw->call('setHeroBadgeMode', 'home_only');
    expect($site->fresh()->reviews_hero_badge_mode)->toBe(\App\Enums\ReviewsHeroBadgeMode::HomeOnly);

    $lw->call('setHeroBadgeMode', 'off');
    expect($site->fresh()->reviews_hero_badge_mode)->toBe(\App\Enums\ReviewsHeroBadgeMode::Off);

    $lw->call('setHeroBadgeMode', 'sideways');
    expect($site->fresh()->reviews_hero_badge_mode)->toBe(\App\Enums\ReviewsHeroBadgeMode::Off);
});
