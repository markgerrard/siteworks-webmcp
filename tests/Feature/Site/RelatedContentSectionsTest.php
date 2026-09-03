<?php

use App\Enums\PageKind;
use App\Enums\PageOrigin;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use App\Services\Site\PageService;
use App\Services\Site\SitePublishService;
use Illuminate\Support\Facades\Bus;

/**
 * @param  array<int, array{page: GeneratedPage, revision: PageRevision, pin?: bool}>  $pages
 */
function relatedContentPublish(Site $site, GeneratedPage $home, PageRevision $homeRev, array $pages): SiteVersion
{
    $pins = [['page_id' => $home->id, 'revision_id' => $homeRev->id]];

    foreach ($pages as $entry) {
        if (($entry['pin'] ?? true) === false) {
            continue;
        }

        $pins[] = [
            'page_id' => $entry['page']->id,
            'revision_id' => $entry['revision']->id,
        ];
    }

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'],
            'homepage_page_id' => $home->id,
        ],
        'page_revisions' => $pins,
        'published_at' => now(),
    ]);

    SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);

    return $version;
}

/**
 * @param  array<string, mixed>  $attrs
 * @param  array<int, array<string, mixed>>  $sections
 * @param  array<string, mixed>  $params
 * @return array{page: GeneratedPage, revision: PageRevision}
 */
function relatedContentMakePage(Site $site, array $attrs, array $sections, array $params = []): array
{
    $page = GeneratedPage::factory()->for($site)->create(array_merge([
        'status' => PageStatus::Published,
        'origin' => PageOrigin::Managed,
        'content_data' => $params === [] ? [] : ['params' => $params],
    ], $attrs));

    $revision = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => [
            'params' => $params,
            'sections' => $sections,
        ],
    ]);
    $page->update(['published_revision_id' => $revision->id]);

    return ['page' => $page->fresh(), 'revision' => $revision];
}

test('related_guides on a service page lists pinned guide-kind pages only', function () {
    $site = Site::factory()->create(['business_name' => 'Acme', 'theme' => 'trades-bold']);
    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
        'nav_label' => 'Home',
        'status' => PageStatus::Published,
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Welcome']]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);

    $service = relatedContentMakePage($site, [
        'page_type' => 'roofing-wigan',
        'nav_label' => 'Roofing in Wigan',
        'kind' => PageKind::Service,
        'origin' => PageOrigin::Pipeline,
    ], [
        ['type' => 'hero', 'title' => 'Roofing'],
        ['type' => 'related_guides'],
    ]);

    $pinnedGuide = relatedContentMakePage($site, [
        'page_type' => 'roof-repair-guide',
        'nav_label' => 'Roof Repair Guide',
        'kind' => PageKind::Guide,
    ], [
        ['type' => 'hero', 'title' => 'Roof repairs'],
    ]);

    $unpinnedGuide = relatedContentMakePage($site, [
        'page_type' => 'ghost-planning-guide',
        'nav_label' => 'Ghost Planning Guide',
        'kind' => PageKind::Guide,
    ], [
        ['type' => 'hero', 'title' => 'Ghost planning'],
    ]);

    $pinnedService = relatedContentMakePage($site, [
        'page_type' => 'guttering-wigan',
        'nav_label' => 'Guttering in Wigan',
        'kind' => PageKind::Service,
        'origin' => PageOrigin::Pipeline,
    ], [
        ['type' => 'hero', 'title' => 'Guttering'],
    ]);

    relatedContentPublish($site, $home, $homeRev, [
        [...$service, 'pin' => true],
        [...$pinnedGuide, 'pin' => true],
        [...$unpinnedGuide, 'pin' => false],
        [...$pinnedService, 'pin' => true],
    ]);

    $html = app(PageRenderer::class)->render($site, $service['page']->id, mode: 'public');

    expect($html)->toContain('data-related-guides')
        ->and($html)->toContain('Roof Repair Guide')
        ->and($html)->toContain('href="/roof-repair-guide"')
        ->and($html)->not->toContain('Ghost Planning Guide')
        ->and($html)->not->toContain('href="/ghost-planning-guide"')
        ->and($html)->not->toContain('Guttering in Wigan');
});

test('related_guides silently skips a published but unpinned guide', function () {
    $site = Site::factory()->create(['business_name' => 'Acme', 'theme' => 'trades-bold']);
    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
        'nav_label' => 'Home',
        'status' => PageStatus::Published,
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Welcome']]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);

    $service = relatedContentMakePage($site, [
        'page_type' => 'loft-conversions',
        'nav_label' => 'Loft Conversions',
        'kind' => PageKind::Service,
        'origin' => PageOrigin::Pipeline,
    ], [
        ['type' => 'hero', 'title' => 'Lofts'],
        ['type' => 'related_guides'],
    ]);

    $unpinnedGuide = relatedContentMakePage($site, [
        'page_type' => 'unpinned-loft-guide',
        'nav_label' => 'Unpinned Loft Guide',
        'kind' => PageKind::Guide,
    ], [
        ['type' => 'hero', 'title' => 'Unpinned loft'],
    ]);

    relatedContentPublish($site, $home, $homeRev, [
        [...$service, 'pin' => true],
        [...$unpinnedGuide, 'pin' => false],
    ]);

    $html = app(PageRenderer::class)->render($site, $service['page']->id, mode: 'public');

    expect($html)->not->toContain('Unpinned Loft Guide')
        ->and($html)->not->toContain('href="/unpinned-loft-guide"')
        ->and($html)->not->toContain('data-related-guides');
});

test('related_services silently skips a published but unpinned service', function () {
    $site = Site::factory()->create(['business_name' => 'Acme', 'theme' => 'trades-bold']);
    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
        'nav_label' => 'Home',
        'status' => PageStatus::Published,
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Welcome']]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);

    $guide = relatedContentMakePage($site, [
        'page_type' => 'planning-permission-guide',
        'nav_label' => 'Planning Permission Guide',
        'kind' => PageKind::Guide,
    ], [
        ['type' => 'hero', 'title' => 'Planning'],
        ['type' => 'related_services'],
    ]);

    $unpinnedService = relatedContentMakePage($site, [
        'page_type' => 'unpinned-extension-service',
        'nav_label' => 'Unpinned Extension Service',
        'kind' => PageKind::Service,
        'origin' => PageOrigin::Pipeline,
    ], [
        ['type' => 'hero', 'title' => 'Extensions'],
    ]);

    relatedContentPublish($site, $home, $homeRev, [
        [...$guide, 'pin' => true],
        [...$unpinnedService, 'pin' => false],
    ]);

    $html = app(PageRenderer::class)->render($site, $guide['page']->id, mode: 'public');

    expect($html)->not->toContain('Unpinned Extension Service')
        ->and($html)->not->toContain('href="/unpinned-extension-service"')
        ->and($html)->not->toContain('data-related-services');
});

test('related_guides respects the cap of 8', function () {
    $site = Site::factory()->create(['business_name' => 'Acme', 'theme' => 'trades-bold']);
    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
        'nav_label' => 'Home',
        'status' => PageStatus::Published,
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Welcome']]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);

    $service = relatedContentMakePage($site, [
        'page_type' => 'kitchen-fitting',
        'nav_label' => 'Kitchen Fitting',
        'kind' => PageKind::Service,
        'origin' => PageOrigin::Pipeline,
    ], [
        ['type' => 'hero', 'title' => 'Kitchens'],
        ['type' => 'related_guides'],
    ]);

    $guides = [];
    foreach (range(1, 9) as $n) {
        $guides[] = relatedContentMakePage($site, [
            'page_type' => sprintf('kitchen-guide-%02d', $n),
            'nav_label' => sprintf('Kitchen Guide %02d', $n),
            'kind' => PageKind::Guide,
        ], [
            ['type' => 'hero', 'title' => sprintf('Kitchen guide %02d', $n)],
        ]);
    }

    relatedContentPublish($site, $home, $homeRev, [
        [...$service, 'pin' => true],
        ...array_map(fn (array $g) => [...$g, 'pin' => true], $guides),
    ]);

    $html = app(PageRenderer::class)->render($site, $service['page']->id, mode: 'public');

    expect($html)->toContain('data-related-guides');

    foreach (range(1, 8) as $n) {
        expect($html)->toContain(sprintf('Kitchen Guide %02d', $n))
            ->and($html)->toContain(sprintf('href="/kitchen-guide-%02d"', $n));
    }

    expect($html)->not->toContain('Kitchen Guide 09')
        ->and($html)->not->toContain('href="/kitchen-guide-09"');
});

test('related_services lists pinned services that share service or area params', function () {
    $site = Site::factory()->create(['business_name' => 'Acme', 'theme' => 'trades-bold']);
    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
        'nav_label' => 'Home',
        'status' => PageStatus::Published,
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Welcome']]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);

    $guide = relatedContentMakePage($site, [
        'page_type' => 'loft-conversion-guide',
        'nav_label' => 'Loft Conversion Guide',
        'kind' => PageKind::Guide,
    ], [
        ['type' => 'hero', 'title' => 'Lofts'],
        ['type' => 'related_services', 'params' => ['service' => 'loft-conversion', 'area' => 'wigan']],
    ], ['service' => 'loft-conversion', 'area' => 'wigan']);

    $matching = relatedContentMakePage($site, [
        'page_type' => 'loft-conversions-wigan',
        'nav_label' => 'Loft Conversions Wigan',
        'kind' => PageKind::Service,
        'origin' => PageOrigin::Managed,
    ], [
        ['type' => 'hero', 'title' => 'Lofts in Wigan'],
    ], ['service' => 'loft-conversion', 'area' => 'wigan']);

    $otherService = relatedContentMakePage($site, [
        'page_type' => 'plumbing-bolton',
        'nav_label' => 'Plumbing Bolton',
        'kind' => PageKind::Service,
        'origin' => PageOrigin::Managed,
    ], [
        ['type' => 'hero', 'title' => 'Plumbing'],
    ], ['service' => 'plumbing', 'area' => 'bolton']);

    relatedContentPublish($site, $home, $homeRev, [
        [...$guide, 'pin' => true],
        [...$matching, 'pin' => true],
        [...$otherService, 'pin' => true],
    ]);

    $html = app(PageRenderer::class)->render($site, $guide['page']->id, mode: 'public');

    expect($html)->toContain('data-related-services')
        ->and($html)->toContain('Loft Conversions Wigan')
        ->and($html)->toContain('href="/loft-conversions-wigan"')
        ->and($html)->not->toContain('Plumbing Bolton')
        ->and($html)->not->toContain('href="/plumbing-bolton"');
});

test('public related-strip grouping stays on the pinned revision after an unpublished draft param edit', function () {
    Bus::fake([\App\Jobs\GenerateBrandImagesJob::class]);

    $site = Site::factory()->create(['business_name' => 'Acme', 'theme' => 'trades-bold']);
    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
        'nav_label' => 'Home',
        'status' => PageStatus::Published,
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Welcome']]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);

    $guide = relatedContentMakePage($site, [
        'page_type' => 'loft-conversion-guide',
        'nav_label' => 'Loft Conversion Guide',
        'kind' => PageKind::Guide,
    ], [
        ['type' => 'hero', 'title' => 'Lofts'],
        ['type' => 'related_services', 'params' => ['service' => 'loft-conversion', 'area' => 'wigan']],
    ], ['service' => 'loft-conversion', 'area' => 'wigan']);

    $matching = relatedContentMakePage($site, [
        'page_type' => 'loft-conversions-wigan',
        'nav_label' => 'Loft Conversions Wigan',
        'kind' => PageKind::Service,
        'origin' => PageOrigin::Managed,
    ], [
        ['type' => 'hero', 'title' => 'Lofts in Wigan'],
    ], ['service' => 'loft-conversion', 'area' => 'wigan']);

    $otherService = relatedContentMakePage($site, [
        'page_type' => 'plumbing-bolton',
        'nav_label' => 'Plumbing Bolton',
        'kind' => PageKind::Service,
        'origin' => PageOrigin::Managed,
    ], [
        ['type' => 'hero', 'title' => 'Plumbing'],
    ], ['service' => 'plumbing', 'area' => 'bolton']);

    SiteDraft::create([
        'site_id' => $site->id,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'homepage_page_id' => $home->id,
        ],
        'updated_at' => now(),
    ]);

    app(SitePublishService::class)->publishSite($site);

    $renderer = app(PageRenderer::class);
    $publishedHtml = $renderer->render($site, $guide['page']->id, mode: 'public');

    expect($publishedHtml)->toContain('data-related-services')
        ->and($publishedHtml)->toContain('Loft Conversions Wigan')
        ->and($publishedHtml)->not->toContain('Plumbing Bolton');

    // Draft params would drop this page out of the loft/wigan group if public
    // strips read generated_pages.content_data (the draft mirror).
    app(PageService::class)->replaceContent($matching['page']->fresh(), [
        'params' => ['service' => 'plumbing', 'area' => 'bolton'],
        'sections' => [
            ['type' => 'hero', 'title' => 'Lofts in Wigan'],
        ],
    ]);

    $draftHtml = $renderer->render($site, $guide['page']->id, mode: 'public');

    expect($draftHtml)->toContain('data-related-services')
        ->and($draftHtml)->toContain('Loft Conversions Wigan')
        ->and($draftHtml)->not->toContain('Plumbing Bolton');

    app(SitePublishService::class)->publishSinglePage($site, $matching['page']->fresh());

    $republishedHtml = $renderer->render($site, $guide['page']->id, mode: 'public');

    expect($republishedHtml)->not->toContain('Loft Conversions Wigan')
        ->and($republishedHtml)->not->toContain('data-related-services');
});
