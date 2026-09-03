<?php

use App\Enums\LogoSize;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\LogoConcept;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/** Default (non-compact, non-large) class strings currently emitted by nav.blade.php. */
const LOGO_DEFAULT_HEADER_SCROLLED = 'h-[6.75rem] md:h-[7.875rem]';
const LOGO_DEFAULT_HEADER_UNSCROLLED = 'h-[7.5rem] md:h-[8.75rem]';
const LOGO_DEFAULT_IMG_SCROLLED = 'h-[3.95rem] max-w-[225px] md:h-[6.3rem] md:max-w-[410px]';
const LOGO_DEFAULT_IMG_UNSCROLLED = 'h-[4.375rem] max-w-[250px] md:h-28 md:max-w-[455px]';
const LOGO_DEFAULT_MIN_W = 'min-w-[140px] md:min-w-[220px]';

/** Compact (saas heuristic / explicit compact) class strings. */
const LOGO_COMPACT_HEADER_SCROLLED = 'h-[4.25rem] md:h-[4.75rem]';
const LOGO_COMPACT_HEADER_UNSCROLLED = 'h-[5rem] md:h-[5.75rem]';
const LOGO_COMPACT_IMG_SCROLLED = 'h-10 max-w-[170px] md:h-12 md:max-w-[240px]';
const LOGO_COMPACT_IMG_UNSCROLLED = 'h-12 max-w-[200px] md:h-14 md:max-w-[280px]';
const LOGO_COMPACT_MIN_W = 'min-w-[100px] md:min-w-[150px]';

/** Large = default matrix × 1.25 (heights + proportional max/min widths). */
const LOGO_LARGE_HEADER_SCROLLED = 'h-[8.4375rem] md:h-[9.84375rem]';
const LOGO_LARGE_HEADER_UNSCROLLED = 'h-[9.375rem] md:h-[10.9375rem]';
const LOGO_LARGE_IMG_SCROLLED = 'h-[4.9375rem] max-w-[281px] md:h-[7.875rem] md:max-w-[513px]';const LOGO_LARGE_IMG_UNSCROLLED = 'h-[5.46875rem] max-w-[313px] md:h-[8.75rem] md:max-w-[569px]';
const LOGO_LARGE_MIN_W = 'min-w-[175px] md:min-w-[275px]';

/**
 * @return array{0: Site, 1: GeneratedPage}
 */
function makeHomePageWithLogo(?string $archetype = null): array
{
    Storage::fake('s3');

    $site = Site::factory()->create([
        'business_name' => 'Acme Plumbing',
        'theme' => 'trades-bold',
    ]);

    $profileData = [
        'name' => 'Acme Plumbing',
        'summary' => 'Local plumbers',
        'contact' => ['phones' => ['01234 567890']],
        'geo' => ['service_area' => 'Wigan'],
    ];
    if ($archetype !== null) {
        $profileData['archetype'] = $archetype;
    }

    BusinessProfile::factory()->for($site)->create([
        'profile_data' => $profileData,
    ]);

    LogoConcept::factory()->for($site)->selected()->create([
        'path' => 'logos/acme-logo.png',
    ]);

    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Welcome to Acme', 'subtitle' => 'Plumbing in Wigan'],
        ]],
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

    return [$site->fresh(), $page];
}

it('sites default to the standard logo size', function () {
    $site = Site::factory()->create();

    expect($site->fresh()->logo_size)->toBe(LogoSize::Standard);
});

it('standard logo size emits the default (non-compact) class matrix', function () {
    [$site, $page] = makeHomePageWithLogo();

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain(LOGO_DEFAULT_HEADER_SCROLLED);
    expect($html)->toContain(LOGO_DEFAULT_HEADER_UNSCROLLED);
    expect($html)->toContain(LOGO_DEFAULT_IMG_SCROLLED);
    expect($html)->toContain(LOGO_DEFAULT_IMG_UNSCROLLED);
    expect($html)->toContain(LOGO_DEFAULT_MIN_W);

    // No large or compact class sets on a non-saas standard site.
    expect($html)->not->toContain(LOGO_LARGE_HEADER_UNSCROLLED);
    expect($html)->not->toContain(LOGO_COMPACT_HEADER_UNSCROLLED);
    expect($html)->not->toContain(LOGO_COMPACT_MIN_W);
});

it('compact logo size forces the compact class matrix', function () {
    [$site, $page] = makeHomePageWithLogo();
    $site->update(['logo_size' => LogoSize::Compact]);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($html)->toContain(LOGO_COMPACT_HEADER_SCROLLED);
    expect($html)->toContain(LOGO_COMPACT_HEADER_UNSCROLLED);
    expect($html)->toContain(LOGO_COMPACT_IMG_SCROLLED);
    expect($html)->toContain(LOGO_COMPACT_IMG_UNSCROLLED);
    expect($html)->toContain(LOGO_COMPACT_MIN_W);

    expect($html)->not->toContain(LOGO_DEFAULT_HEADER_UNSCROLLED);
    expect($html)->not->toContain(LOGO_LARGE_HEADER_UNSCROLLED);
});

it('large logo size emits the ~25% taller class matrix', function () {
    [$site, $page] = makeHomePageWithLogo();
    $site->update(['logo_size' => LogoSize::Large]);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($html)->toContain(LOGO_LARGE_HEADER_SCROLLED);
    expect($html)->toContain(LOGO_LARGE_HEADER_UNSCROLLED);
    expect($html)->toContain(LOGO_LARGE_IMG_SCROLLED);
    expect($html)->toContain(LOGO_LARGE_IMG_UNSCROLLED);
    expect($html)->toContain(LOGO_LARGE_MIN_W);

    expect($html)->not->toContain(LOGO_DEFAULT_HEADER_UNSCROLLED);
    expect($html)->not->toContain(LOGO_COMPACT_HEADER_UNSCROLLED);
});

it('standard logo size is byte-identical to the pre-toggle render', function () {
    [$site, $page] = makeHomePageWithLogo();

    $before = app(PageRenderer::class)->render($site, $page->id, mode: 'public');
    $site->update(['logo_size' => LogoSize::Standard]);
    $after = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($after)->toBe($before);
});

it('saas_platform archetype with standard logo size keeps the compact heuristic', function () {
    [$site, $page] = makeHomePageWithLogo('saas_platform');
    expect($site->logo_size)->toBe(LogoSize::Standard);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain(LOGO_COMPACT_HEADER_UNSCROLLED);
    expect($html)->toContain(LOGO_COMPACT_IMG_UNSCROLLED);
    expect($html)->toContain(LOGO_COMPACT_MIN_W);
    expect($html)->not->toContain(LOGO_DEFAULT_HEADER_UNSCROLLED);
    expect($html)->not->toContain(LOGO_LARGE_HEADER_UNSCROLLED);
});

it('large logo size overrides the saas_platform compact heuristic', function () {
    [$site, $page] = makeHomePageWithLogo('saas_platform');
    $site->update(['logo_size' => LogoSize::Large]);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($html)->toContain(LOGO_LARGE_HEADER_UNSCROLLED);
    expect($html)->toContain(LOGO_LARGE_IMG_UNSCROLLED);
    expect($html)->toContain(LOGO_LARGE_MIN_W);
    expect($html)->not->toContain(LOGO_COMPACT_HEADER_UNSCROLLED);
    expect($html)->not->toContain(LOGO_COMPACT_MIN_W);
});
