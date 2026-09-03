<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * @return array{0: Site, 1: GeneratedPage}
 */
function makeHomePageForHeaderBgTest(): array
{
    $site = Site::factory()->create(['business_name' => 'Acme Plumbing', 'theme' => 'trades-bold']);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Welcome to Acme'],
        ]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => [['type' => 'page', 'label' => 'Home', 'page_id' => $page->id]]],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'homepage_page_id' => $page->id,
        ],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    return [$site, $page];
}

it('renders the white header default when header_bg is unset', function () {
    [$site, $page] = makeHomePageForHeaderBgTest();

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('background-color: #ffffff;')
        ->toContain('text-gray-600 hover:text-gray-900')
        ->not->toContain('text-white/80 hover:text-white');
});

it('renders a dark header with adapted link colours', function () {
    [$site, $page] = makeHomePageForHeaderBgTest();
    $site->update(['header_bg' => '#1A1A1C']);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($html)->toContain('background-color: #1a1a1c;')
        ->toContain('text-white/80 hover:text-white')
        ->toContain('border-color: rgba(255,255,255,0.15);')
        // Dropdown/mobile panels follow the header colour + dark link set.
        ->toContain('hover:bg-white/10')
        ->not->toContain('bg-white shadow-lg');
});

it('a light custom header keeps the grey link colours', function () {
    [$site, $page] = makeHomePageForHeaderBgTest();
    $site->update(['header_bg' => '#f4ede4']);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($html)->toContain('background-color: #f4ede4;')
        ->toContain('text-gray-600 hover:text-gray-900');
});

it('applies logo_margin as vertical padding on the header logo', function () {
    [$site, $page] = makeHomePageForHeaderBgTest();
    \Illuminate\Support\Facades\Storage::fake('s3');
    \App\Models\LogoConcept::factory()->for($site)->selected()->create(['path' => 'logos/acme-logo.png']);
    $site->update(['logo_margin' => 5]);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($html)->toContain('padding-top: 5px; padding-bottom: 5px;');
});

it('renders no logo padding style at the default margin', function () {
    [$site, $page] = makeHomePageForHeaderBgTest();
    \Illuminate\Support\Facades\Storage::fake('s3');
    \App\Models\LogoConcept::factory()->for($site)->selected()->create(['path' => 'logos/acme-logo.png']);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($html)->not->toContain('padding-top: 0px');
});

it('falls back to white on a malformed header_bg', function () {
    [$site, $page] = makeHomePageForHeaderBgTest();
    $site->update(['header_bg' => '#gggggg']);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($html)->toContain('background-color: #ffffff;')
        ->not->toContain('#gggggg');
});
