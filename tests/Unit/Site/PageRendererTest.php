<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => $this->renderer = app(PageRenderer::class));

function setupPublishedSite(): array
{
    $site = Site::factory()->create(['business_name' => 'Acme', 'theme' => 'trades-bold']);
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

    return [$site, $page, $rev, $version];
}

test('public render emits page HTML containing the published title', function () {
    [$site, $page] = setupPublishedSite();

    $html = $this->renderer->render($site, $page->id, mode: 'public');

    expect($html)->toContain('Welcome to Acme');
    expect($html)->toContain('Plumbing in Wigan');
});

test('public render does NOT include data-editable markers', function () {
    [$site, $page] = setupPublishedSite();

    $html = $this->renderer->render($site, $page->id, mode: 'public');

    // Match the actual attribute form on elements (with a space before), not
    // substring — the page's inline CSS contains selectors like
    // [data-editable-type="rich"] which would otherwise trigger a false match.
    expect($html)->not->toMatch('/\s+data-editable="/');
    expect($html)->not->toMatch('/\s+data-editable-type="/');
});

test('public render keeps serving a page that is pinned by the published version even after live archive', function () {
    // Spec §6: public mode reads strictly from site_versions_current. The
    // editor archiving the live page row must NOT 404 content that the published
    // version still pins — and rolling back must still render those pages.
    [$site, $page] = setupPublishedSite();
    $page->update(['archived_at' => now()]);

    $html = $this->renderer->render($site, $page->id, mode: 'public');

    expect($html)->toContain('Welcome to Acme');
});

test('public render includes pagesBySlug href for archived page still pinned by published version', function () {
    // Mirrors: 'keeps serving a page that is pinned by the published version even after live archive'
    // but checks cross-link resolution (pagesBySlug) rather than direct page render.
    // A service card on the home page cross-links to a service page by slug; the
    // service page gets archived after publish — public render must still resolve the href.
    $site = Site::factory()->create(['business_name' => 'Acme', 'theme' => 'trades-bold']);

    // Home page with a services section whose item title slugifies to 'roofing'.
    $homePage = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $homeRev = PageRevision::factory()->for($homePage, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'services', 'title' => 'Our Services', 'items' => [
                ['title' => 'Roofing', 'body' => 'We roof things.'],
            ]],
        ]],
    ]);
    $homePage->update(['published_revision_id' => $homeRev->id]);

    // Service page with page_type = 'roofing'.
    $servicePage = GeneratedPage::factory()->for($site)->create(['page_type' => 'roofing']);
    $serviceRev = PageRevision::factory()->for($servicePage, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Roofing']]],
    ]);
    $servicePage->update(['published_revision_id' => $serviceRev->id]);

    // Create a version that pins both pages.
    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'],
            'homepage_page_id' => $homePage->id,
        ],
        'page_revisions' => [
            ['page_id' => $homePage->id, 'revision_id' => $homeRev->id],
            ['page_id' => $servicePage->id, 'revision_id' => $serviceRev->id],
        ],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    // Archive the service page (simulates editor archiving after publish).
    $servicePage->update(['archived_at' => now()]);

    // Public render of the home page should still include '/roofing' href in its HTML
    // because the published version still pins the service page.
    $html = $this->renderer->render($site, $homePage->id, mode: 'public');

    expect($html)->toContain('/roofing');
});

test('admin-preview rejects archived page (live editing surface)', function () {
    [$site, $page] = setupPublishedSite();
    $page->update(['archived_at' => now()]);

    expect(fn () => $this->renderer->render($site, $page->id, mode: 'admin-preview'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
});

test('admin-preview reads draft revision when present', function () {
    [$site, $page, $rev] = setupPublishedSite();

    // Add a draft via PageService
    app(\App\Services\Site\PageService::class)->editField($page, 'sections.0.title', 'Draft title');

    $html = $this->renderer->render($site, $page->id, mode: 'admin-preview');
    expect($html)->toContain('Draft title');
});

test('admin-preview falls back to published when no draft', function () {
    [$site, $page] = setupPublishedSite();

    $html = $this->renderer->render($site, $page->id, mode: 'admin-preview');
    expect($html)->toContain('Welcome to Acme');
});

test('admin-edit emits data-editable markers per schema', function () {
    [$site, $page] = setupPublishedSite();

    $html = $this->renderer->render($site, $page->id, mode: 'admin-edit');
    expect($html)->toContain('data-editable');
    expect($html)->toContain('data-editable-type="plain"');
    expect($html)->toContain('data-editable-section-type="hero"');
    expect($html)->toContain('data-editable-field="title"');
});

test('admin-edit emits markers for every editable field declared in section schema', function () {
    [$site, $page, $rev] = setupPublishedSite();

    $html = $this->renderer->render($site, $page->id, mode: 'admin-edit');

    // hero declares title + subtitle in this revision
    $titleMarker = preg_match('/data-editable="page\.\d+\.section\.0\.title"/', $html);
    $subtitleMarker = preg_match('/data-editable="page\.\d+\.section\.0\.subtitle"/', $html);
    expect($titleMarker)->toBe(1);
    expect($subtitleMarker)->toBe(1);
});

test('all 13 section types render without throwing given minimal valid shapes', function () {
    $allSections = [
        ['type' => 'hero',         'title' => 'Hero heading', 'subtitle' => 'Sub'],
        ['type' => 'services',     'title' => 'Services', 'items' => [['title' => 'Svc', 'body' => 'Desc']]],
        ['type' => 'trust',        'title' => 'Trust', 'items' => [['title' => 'T1', 'body' => 'B1']]],
        ['type' => 'story',        'title' => 'Our story', 'body' => 'Story body'],
        ['type' => 'values',       'title' => 'Values', 'items' => [['title' => 'V1', 'body' => 'B1']]],
        ['type' => 'details',      'title' => 'Details', 'items' => [['label' => 'Phone', 'value' => '01234 567890']]],
        ['type' => 'contact_form', 'title' => 'Contact', 'submit_label' => 'Send'],
        ['type' => 'intro',        'title' => 'Intro', 'body' => 'Intro body'],
        ['type' => 'process',      'title' => 'Process', 'items' => [['step' => '1', 'title' => 'Step one', 'body' => 'Do it']]],
        ['type' => 'faqs',         'title' => 'FAQs', 'items' => [['question' => 'Q?', 'answer' => 'A.']]],
        ['type' => 'benefits',     'title' => 'Benefits', 'items' => [['title' => 'Benefit', 'body' => 'Reason']]],
        ['type' => 'cta',          'title' => 'CTA heading', 'body' => 'CTA body', 'button_label' => 'Click'],
        ['type' => 'about-text',   'title' => 'About', 'body' => 'About body'],
    ];

    $site = Site::factory()->create(['business_name' => 'TestCo', 'theme' => 'trades-bold']);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => $allSections],
    ]);
    $page->update(['published_revision_id' => $rev->id]);

    $version = SiteVersion::create([
        'site_id'        => $site->id,
        'version'        => 1,
        'composition'    => [
            'nav'              => ['items' => []],
            'footer'           => ['columns' => [], 'show_credit' => true],
            'theme'            => ['key' => 'trades-bold'],
            'homepage_page_id' => $page->id,
        ],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev->id]],
        'published_at'   => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    $html = $this->renderer->render($site, $page->id, mode: 'public');

    expect($html)->toContain('Hero heading');
    expect($html)->toContain('Contact');
    expect($html)->toContain('TestCo');
    expect(strlen($html))->toBeGreaterThan(10000);
});
