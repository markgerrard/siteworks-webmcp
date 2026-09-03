<?php

use App\Enums\SiteReviewStatus;
use App\Models\Site;
use App\Models\SiteReview;
use App\Services\Site\SiteHostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function nativeReviewSite(bool $platformFlag = true, bool $siteToggle = true): Site
{
    config(['site.native_reviews_enabled' => $platformFlag]);

    $site = Site::factory()->create([
        'preview_domain' => 'native-reviews-'.fake()->unique()->numberBetween(1, 999999),
        'native_reviews_enabled' => $siteToggle,
    ]);

    // Host→site resolution is CloudflareDomainService-backed and covered
    // by its own tests; here the resolver is stubbed so these tests pin
    // the controller's gates, validation, and storage behaviour.
    test()->mock(SiteHostResolver::class, fn ($mock) => $mock->shouldReceive('resolve')->andReturn($site));

    return $site;
}

function submitReview(Site $site, array $overrides = [])
{
    return test()->postJson('/reviews', array_merge([
        'author_name' => 'Alice',
        'rating' => 5,
        'text' => 'Wonderful loft conversion.',
        'website' => '',
    ], $overrides));
}

// ───── Submission endpoint ────────────────────────────────────────────

test('submission 404s when the platform flag is off', function () {
    $site = nativeReviewSite(platformFlag: false);

    submitReview($site)->assertNotFound();
    expect(SiteReview::count())->toBe(0);
});

test('submission 404s when the site toggle is off', function () {
    $site = nativeReviewSite(siteToggle: false);

    submitReview($site)->assertNotFound();
    expect(SiteReview::count())->toBe(0);
});

test('valid submission stores a pending review against the resolved site', function () {
    $site = nativeReviewSite();

    submitReview($site)->assertSuccessful();

    $review = SiteReview::sole();
    expect($review->site_id)->toBe($site->id)
        ->and($review->status)->toBe(SiteReviewStatus::Pending)
        ->and($review->author_name)->toBe('Alice')
        ->and($review->rating)->toBe(5);
});

test('honeypot submissions pretend success but store nothing', function () {
    $site = nativeReviewSite();

    submitReview($site, ['website' => 'https://spam.example'])->assertSuccessful();

    expect(SiteReview::count())->toBe(0);
});

test('invalid rating is rejected', function () {
    $site = nativeReviewSite();

    submitReview($site, ['rating' => 9])->assertUnprocessable();
    expect(SiteReview::count())->toBe(0);
});

// ───── native_reviews section ─────────────────────────────────────────

function renderNativeReviews(Site $site): string
{
    return view('site.sections.native_reviews', [
        'site' => $site,
        'section' => ['type' => 'native_reviews'],
        'pageId' => 1, 'sectionIndex' => 0, 'emitMarkers' => false,
        'profile' => [], 'theme' => [],
    ])->render();
}

test('section renders nothing when either gate is off', function () {
    $offPlatform = nativeReviewSite(platformFlag: false);
    expect(trim(renderNativeReviews($offPlatform)))->toBe('');

    $offSite = nativeReviewSite(siteToggle: false);
    expect(trim(renderNativeReviews($offSite)))->toBe('');
});

test('section shows only approved reviews, newest first, plus the form', function () {
    $site = nativeReviewSite();
    SiteReview::factory()->for($site)->create(['author_name' => 'Pending Pat']);
    $old = SiteReview::factory()->for($site)->approved()->create(['author_name' => 'Old Olive', 'created_at' => now()->subDays(9)]);
    $new = SiteReview::factory()->for($site)->approved()->create(['author_name' => 'New Nadia', 'created_at' => now()]);

    $html = renderNativeReviews($site);

    expect($html)->toContain('New Nadia')
        ->and($html)->toContain('Old Olive')
        ->and($html)->not->toContain('Pending Pat')
        ->and(strpos($html, 'New Nadia'))->toBeLessThan(strpos($html, 'Old Olive'))
        ->and($html)->toContain('Submit review');
});

// ───── Moderation command ─────────────────────────────────────────────

test('moderate --approve flips status and lists no longer include it', function () {
    $site = nativeReviewSite();
    $review = SiteReview::factory()->for($site)->create();

    $this->artisan('site-reviews:moderate', ['review' => $review->id, '--approve' => true])
        ->expectsOutputToContain('approved')
        ->assertSuccessful();

    expect($review->refresh()->status)->toBe(SiteReviewStatus::Approved);

    $this->artisan('site-reviews:moderate')
        ->expectsOutputToContain('No pending reviews.')
        ->assertSuccessful();
});

test('moderate requires exactly one of approve/reject', function () {
    $site = nativeReviewSite();
    $review = SiteReview::factory()->for($site)->create();

    $this->artisan('site-reviews:moderate', ['review' => $review->id])->assertFailed();
    $this->artisan('site-reviews:moderate', ['review' => $review->id, '--approve' => true, '--reject' => true])->assertFailed();
    expect($review->refresh()->status)->toBe(SiteReviewStatus::Pending);
});
