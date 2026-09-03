<?php

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('reviews_summary renders heading, testimonial quotes, and rating summary', function () {
    $site = Site::factory()->create([
        'reviews_cache' => [
            'rating' => 4.9,
            'user_ratings_total' => 87,
            'url' => 'https://maps.google.com/?cid=1',
            'reviews' => [
                ['author_name' => 'Alice Smith', 'rating' => 5, 'text' => 'Amazing service.', 'relative_time_description' => '2 weeks ago'],
                ['author_name' => 'Bob',         'rating' => 5, 'text' => 'Highly recommended.', 'relative_time_description' => '1 month ago'],
            ],
        ],
    ]);

    $html = view('site.sections.reviews_summary', [
        'site' => $site,
        'section' => ['type' => 'reviews_summary', 'max_items' => 3],
        'pageId' => 1, 'sectionIndex' => 0, 'emitMarkers' => false,
        'profile' => [], 'theme' => [],
    ])->render();

    expect($html)->toContain('What Our Happy Customers Say');
    expect($html)->toContain('Amazing service.');
    expect($html)->toContain('Highly recommended.');
    expect($html)->toContain('>Alice<');
    expect($html)->toContain('>Bob<');
    expect($html)->toContain('4.9');
    expect($html)->toContain('87 Google Reviews');
    expect($html)->toContain('View All');
    expect($html)->toContain('https://maps.google.com/?cid=1');
    // Google G mark — instant third-party credibility badge.
    expect($html)->toContain('#4285F4');
});

test('reviews_summary supports a custom heading override', function () {
    $site = Site::factory()->create([
        'reviews_cache' => [
            'rating' => 5.0, 'user_ratings_total' => 12, 'url' => 'https://maps.google.com/?cid=2',
            'reviews' => [['author_name' => 'Pat', 'rating' => 5, 'text' => 'Great work.']],
        ],
    ]);

    $html = view('site.sections.reviews_summary', [
        'site' => $site,
        'section' => ['type' => 'reviews_summary', 'heading' => 'Real reviews from real customers'],
        'pageId' => 1, 'sectionIndex' => 0, 'emitMarkers' => false,
        'profile' => [], 'theme' => [],
    ])->render();

    expect($html)->toContain('Real reviews from real customers');
    expect($html)->not->toContain('What Our Happy Customers Say');
});

test('reviews_summary rounds large review counts down to "90+" style label', function () {
    $site = Site::factory()->create([
        'reviews_cache' => [
            'rating' => 4.8, 'user_ratings_total' => 97, 'url' => 'https://maps.google.com/?cid=3',
            'reviews' => [['author_name' => 'Sam', 'rating' => 5, 'text' => 'Top notch.']],
        ],
    ]);

    $html = view('site.sections.reviews_summary', [
        'site' => $site,
        'section' => ['type' => 'reviews_summary'],
        'pageId' => 1, 'sectionIndex' => 0, 'emitMarkers' => false,
        'profile' => [], 'theme' => [],
    ])->render();

    expect($html)->toContain('90+ Google Reviews');
    expect($html)->not->toContain('97 Google Reviews');
});

test('reviews_summary renders nothing when reviews_cache is empty', function () {
    $site = Site::factory()->create(['reviews_cache' => null]);
    $html = view('site.sections.reviews_summary', [
        'site' => $site,
        'section' => ['type' => 'reviews_summary'],
        'pageId' => 1, 'sectionIndex' => 0, 'emitMarkers' => false,
        'profile' => [], 'theme' => [],
    ])->render();
    expect(trim($html))->toBe('');
});
