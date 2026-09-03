<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\SiteReview;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;

function trustJsonLdPage(string $pageType, array $sections): array
{
    $site = Site::factory()->create([
        'business_name' => 'Trusted & Safe </script><script>alert(1)</script>',
        'custom_domain' => 'trust-json-ld.example',
        'custom_domain_status' => 'active',
    ]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => $pageType]);
    $revision = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => [
            'meta' => ['seo' => ['meta_description' => 'Customer confidence']],
            'sections' => $sections,
        ],
    ]);
    $page->update(['published_revision_id' => $revision->id]);
    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'],
            'homepage_page_id' => $pageType === 'home' ? $page->id : null,
        ],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $revision->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);

    return [$site->fresh(), $page->fresh()];
}

it('merges aggregate rating into one local business node on a qualifying home trust section', function () {
    [$site, $page] = trustJsonLdPage('home', [[
        'type' => 'trust_strip',
        'sources' => 'site',
        'layout' => 'strip',
        'heading' => 'Customer confidence',
        'min_reviews' => 3,
    ]]);
    SiteReview::factory()->approved()->count(3)->for($site)->sequence(
        ['rating' => 5],
        ['rating' => 4],
        ['rating' => 4],
    )->create();

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect(substr_count($html, '"@type":"LocalBusiness"'))->toBe(1)
        ->and(substr_count($html, '"@type":"AggregateRating"'))->toBe(1)
        ->and($html)->toContain('"ratingValue":4.3')
        ->and($html)->toContain('"reviewCount":3')
        ->and($html)->not->toContain('</script><script>alert(1)</script>');
});

it('emits no organization node without a trust section or below its threshold', function (array $sections, int $reviews) {
    [$site, $page] = trustJsonLdPage('home', $sections);
    SiteReview::factory()->approved()->count($reviews)->for($site)->create();

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->not->toContain('"@type":"LocalBusiness"')
        ->and($html)->not->toContain('"@type":"AggregateRating"');
})->with([
    'section absent' => [[], 3],
    'below threshold' => [[['type' => 'trust_strip', 'sources' => 'site', 'min_reviews' => 3]], 2],
]);

it('does not emit the organization node for a non-home trust section', function () {
    [$site, $page] = trustJsonLdPage('about', [[
        'type' => 'trust_strip',
        'sources' => 'site',
        'min_reviews' => 3,
    ]]);
    SiteReview::factory()->approved()->count(3)->for($site)->create();

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->not->toContain('"@type":"LocalBusiness"');
});
