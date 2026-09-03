<?php

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function renderBadge(Site $site): string
{
    return view('site.sections.reviews_badge', [
        'site' => $site,
        'section' => ['type' => 'reviews_badge'],
        'pageId' => 1, 'sectionIndex' => 0, 'emitMarkers' => false,
        'profile' => [], 'theme' => [],
    ])->render();
}

test('reviews_badge renders rating, count, Google G mark, and link', function () {
    $site = Site::factory()->create([
        'reviews_cache' => [
            'rating' => 4.9,
            'user_ratings_total' => 87,
            'url' => 'https://maps.google.com/?cid=14040000',
            'reviews' => [],
        ],
    ]);

    $html = renderBadge($site);

    expect($html)->toContain('4.9 Rating');
    expect($html)->toContain('87 Google Reviews');
    expect($html)->not->toContain('Read on Google');
    // Google G is identified by its signature blue path fill.
    expect($html)->toContain('#4285F4');
});

test('reviews_badge rounds large counts to a "90+" style label', function () {
    $site = Site::factory()->create([
        'reviews_cache' => ['rating' => 5.0, 'user_ratings_total' => 124, 'url' => '#', 'reviews' => []],
    ]);

    expect(renderBadge($site))->toContain('120+ Google Reviews');
});

test('reviews_badge renders nothing when reviews_cache is empty or zero count', function () {
    $emptySite = Site::factory()->create(['reviews_cache' => null]);
    expect(trim(renderBadge($emptySite)))->toBe('');

    $zeroSite = Site::factory()->create([
        'reviews_cache' => ['rating' => 0, 'user_ratings_total' => 0, 'url' => '', 'reviews' => []],
    ]);
    expect(trim(renderBadge($zeroSite)))->toBe('');
});
