<?php

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('reviews section renders all reviews as cards by default carousel', function () {
    $site = Site::factory()->create([
        'reviews_cache' => [
            'rating' => 4.9, 'user_ratings_total' => 87,
            'url' => 'https://maps.google.com/?cid=1',
            'reviews' => [
                ['author_name' => 'Alice', 'rating' => 5, 'text' => str_repeat('great ', 100), 'relative_time_description' => '2 weeks ago', 'profile_photo_url' => ''],
                ['author_name' => 'Bob',   'rating' => 4, 'text' => 'short', 'relative_time_description' => '1 month ago', 'profile_photo_url' => ''],
            ],
        ],
    ]);

    $html = view('site.sections.reviews', [
        'site' => $site,
        'section' => ['type' => 'reviews', 'display_style' => 'carousel', 'max_items' => 5,
            'show_rating' => true, 'show_review_count' => true, 'show_attribution' => true],
        'pageId' => 1, 'sectionIndex' => 0, 'emitMarkers' => false,
        'profile' => [], 'theme' => [],
    ])->render();

    expect($html)->toContain('Alice');
    expect($html)->toContain('Bob');
    expect($html)->toContain('Read all reviews on Google');
});

test('reviews section renders nothing when cache empty', function () {
    $site = Site::factory()->create(['reviews_cache' => null]);
    $html = view('site.sections.reviews', [
        'site' => $site,
        'section' => ['type' => 'reviews'],
        'pageId' => 1, 'sectionIndex' => 0, 'emitMarkers' => false,
        'profile' => [], 'theme' => [],
    ])->render();
    expect(trim($html))->toBe('');
});

test('grid display_style emits grid utility classes', function () {
    $site = Site::factory()->create([
        'reviews_cache' => ['rating' => 4.5, 'user_ratings_total' => 10,
            'url' => 'https://x', 'reviews' => [
                ['author_name' => 'A', 'rating' => 5, 'text' => 't', 'relative_time_description' => 'now', 'profile_photo_url' => ''],
            ]],
    ]);
    $html = view('site.sections.reviews', [
        'site' => $site,
        'section' => ['type' => 'reviews', 'display_style' => 'grid', 'max_items' => 5,
            'show_rating' => true, 'show_review_count' => true, 'show_attribution' => true],
        'pageId' => 1, 'sectionIndex' => 0, 'emitMarkers' => false,
        'profile' => [], 'theme' => [],
    ])->render();
    expect($html)->toContain('md:grid-cols-2');
});
