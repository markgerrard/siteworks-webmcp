<?php

use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\HeroVersionService;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;

uses(RefreshDatabase::class);

test('hero_versions slot check accepts band', function () {
    $site = Site::factory()->create();

    $hv = HeroVersion::create([
        'site_id' => $site->id,
        'page_type' => 'about',
        'slot' => 'band',
        'url' => 'https://example.test/band.jpg',
        'watermark_url' => null,
        'prompt' => 'band prompt',
        'model' => 'demo',
        'placement' => [],
        'is_active' => true,
    ]);

    expect($hv->fresh()->slot)->toBe('band');
});

test('activate with slot=band creates a band-slot row independently of hero and intro', function () {
    $site = Site::factory()->create();
    $service = app(HeroVersionService::class);

    $service->activate($site->id, 'about', [
        'url' => 'https://example.test/hero.jpg',
        'prompt' => 'hero',
        'model' => 'demo',
        'placement' => [],
    ], 'hero');

    $service->activate($site->id, 'about', [
        'url' => 'https://example.test/intro.jpg',
        'prompt' => 'intro',
        'model' => 'demo',
        'placement' => [],
    ], 'intro');

    $band = $service->activate($site->id, 'about', [
        'url' => 'https://example.test/band.jpg',
        'prompt' => 'band',
        'model' => 'demo',
        'placement' => [],
    ], 'band');

    expect($band->slot)->toBe('band');
    expect($band->is_active)->toBeTrue();

    expect(HeroVersion::where('site_id', $site->id)->where('page_type', 'about')->where('slot', 'hero')->where('is_active', true)->count())->toBe(1);
    expect(HeroVersion::where('site_id', $site->id)->where('page_type', 'about')->where('slot', 'intro')->where('is_active', true)->count())->toBe(1);
    expect(HeroVersion::where('site_id', $site->id)->where('page_type', 'about')->where('slot', 'band')->where('is_active', true)->count())->toBe(1);
});

test('scopeBandSlot filters by slot correctly', function () {
    $site = Site::factory()->create();
    $service = app(HeroVersionService::class);

    $service->activate($site->id, 'about', [
        'url' => 'https://example.test/hero.jpg',
        'prompt' => 'h',
        'model' => 'g',
        'placement' => [],
    ], 'hero');

    $service->activate($site->id, 'about', [
        'url' => 'https://example.test/intro.jpg',
        'prompt' => 'i',
        'model' => 'g',
        'placement' => [],
    ], 'intro');

    $service->activate($site->id, 'about', [
        'url' => 'https://example.test/band.jpg',
        'prompt' => 'b',
        'model' => 'g',
        'placement' => [],
    ], 'band');

    $bandRows = HeroVersion::where('site_id', $site->id)->where('page_type', 'about')->bandSlot()->get();

    expect($bandRows)->toHaveCount(1);
    expect($bandRows->first()->slot)->toBe('band');
});

test('activate second band deactivates the first band but leaves hero and intro active', function () {
    $site = Site::factory()->create();
    $service = app(HeroVersionService::class);

    $service->activate($site->id, 'about', [
        'url' => 'https://example.test/hero.jpg',
        'prompt' => 'h',
        'model' => 'g',
        'placement' => [],
    ], 'hero');

    $service->activate($site->id, 'about', [
        'url' => 'https://example.test/intro.jpg',
        'prompt' => 'i',
        'model' => 'g',
        'placement' => [],
    ], 'intro');

    $band1 = $service->activate($site->id, 'about', [
        'url' => 'https://example.test/band1.jpg',
        'prompt' => 'b1',
        'model' => 'g',
        'placement' => [],
    ], 'band');

    $band2 = $service->activate($site->id, 'about', [
        'url' => 'https://example.test/band2.jpg',
        'prompt' => 'b2',
        'model' => 'g',
        'placement' => [],
    ], 'band');

    expect($band1->fresh()->is_active)->toBeFalse();
    expect($band2->fresh()->is_active)->toBeTrue();
    expect(HeroVersion::where('site_id', $site->id)->where('page_type', 'about')->where('slot', 'hero')->where('is_active', true)->count())->toBe(1);
    expect(HeroVersion::where('site_id', $site->id)->where('page_type', 'about')->where('slot', 'intro')->where('is_active', true)->count())->toBe(1);
});

test('activate still rejects unknown slots', function () {
    $site = Site::factory()->create();

    app(HeroVersionService::class)->activate($site->id, 'about', [
        'url' => 'https://example.test/x.jpg',
        'prompt' => 'x',
        'model' => 'g',
        'placement' => [],
    ], 'banner');
})->throws(\InvalidArgumentException::class);

function setupSiteForBandRenderTest(string $pageType = 'about'): array
{
    $site = Site::factory()->create(['business_name' => 'Band Test Co', 'theme' => 'trades-bold']);

    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => $pageType,
        'hero_source' => 'dedicated',
    ]);

    $revision = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Welcome', 'subtitle' => 'Great service'],
        ]],
    ]);
    $page->update(['published_revision_id' => $revision->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'],
            'homepage_page_id' => $page->id,
        ],
        'page_revisions' => [
            ['page_id' => $page->id, 'revision_id' => $revision->id],
        ],
        'published_at' => now(),
    ]);

    SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);

    return [$site, $page];
}

test('PageRenderer passes active band versions as bandImages keyed by page_type', function () {
    [$site, $page] = setupSiteForBandRenderTest('about');

    app(HeroVersionService::class)->activate($site->id, 'about', [
        'url' => 'https://cdn.test/band.jpg',
        'watermark_url' => 'https://cdn.test/band-wm.jpg',
        'prompt' => 'band',
        'model' => 'demo',
        'placement' => [],
    ], 'band');

    $captured = null;
    View::composer('site.page', function ($view) use (&$captured) {
        $captured = $view->getData();
    });

    app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($captured)->toBeArray()
        ->and($captured['bandImages'] ?? null)->toBe([
            'about' => [
                'url' => 'https://cdn.test/band.jpg',
                'watermark_url' => 'https://cdn.test/band-wm.jpg',
            ],
        ]);
});

test('PageRenderer passes empty bandImages when no band rows exist', function () {
    [$site, $page] = setupSiteForBandRenderTest('about');

    $captured = null;
    View::composer('site.page', function ($view) use (&$captured) {
        $captured = $view->getData();
    });

    app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($captured)->toBeArray()
        ->and($captured['bandImages'] ?? 'missing')->toBe([]);
});

test('PageRenderer resolves band images for a service page_type', function () {
    [$site, $page] = setupSiteForBandRenderTest('roof-repairs');

    app(HeroVersionService::class)->activate($site->id, 'roof-repairs', [
        'url' => 'https://cdn.test/service-band.jpg',
        'watermark_url' => null,
        'prompt' => 'band',
        'model' => 'demo',
        'placement' => [],
    ], 'band');

    $captured = null;
    View::composer('site.page', function ($view) use (&$captured) {
        $captured = $view->getData();
    });

    app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($captured['bandImages'] ?? null)->toBe([
        'roof-repairs' => [
            'url' => 'https://cdn.test/service-band.jpg',
            'watermark_url' => null,
        ],
    ]);
});

test('page include path passes bandImageUrl to sections alongside hero and intro', function () {
    [$site, $page] = setupSiteForBandRenderTest('about');

    app(HeroVersionService::class)->activate($site->id, 'about', [
        'url' => 'https://cdn.test/band.jpg',
        'watermark_url' => 'https://cdn.test/band-wm.jpg',
        'prompt' => 'band',
        'model' => 'demo',
        'placement' => [],
    ], 'band');

    $sectionData = null;
    View::composer('site.sections.hero', function ($view) use (&$sectionData) {
        $sectionData = $view->getData();
    });

    app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($sectionData)->toBeArray()
        ->and($sectionData['bandImageUrl'] ?? null)->toBe([
            'url' => 'https://cdn.test/band.jpg',
            'watermark_url' => 'https://cdn.test/band-wm.jpg',
        ])
        ->and($sectionData)->toHaveKeys(['heroImageUrl', 'introImageUrl', 'bandImageUrl']);
});
