<?php

use App\Enums\MutationSource;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Services\Site\CompositionService;
use App\Services\Site\SitePublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeRevisionedPage(int $siteId, string $type, PageStatus $status = PageStatus::Published): GeneratedPage
{
    $page = GeneratedPage::create([
        'site_id' => $siteId,
        'page_type' => $type,
        'content_data' => [],
        'sort_order' => 0,
        'version' => 1,
        'status' => $status,
    ]);

    $rev = PageRevision::create([
        'page_id' => $page->id,
        'content_data' => [],
        'ai_generated' => false,
        'created_at' => now(),
    ]);

    $page->update(['published_revision_id' => $rev->id]);

    return $page;
}

test('appendNavPageAtomic refuses to add a Draft page to nav', function () {
    $site = Site::factory()->create();
    $page = makeRevisionedPage($site->id, 'hidden-page', PageStatus::Draft);
    $cs = app(CompositionService::class);

    $result = $cs->appendNavPageAtomic($site, $page->id, 'Hidden', MutationSource::Pipeline);

    expect($result)->toBeFalse();
    $draft = SiteDraft::where('site_id', $site->id)->first();
    $items = $draft?->composition['nav']['items'] ?? [];
    expect($items)->toBeEmpty();
});

test('appendNavPageAtomic refuses to add an Archived page to nav', function () {
    $site = Site::factory()->create();
    $page = makeRevisionedPage($site->id, 'old-page', PageStatus::Archived);
    $cs = app(CompositionService::class);

    $result = $cs->appendNavPageAtomic($site, $page->id, 'Old', MutationSource::Pipeline);

    expect($result)->toBeFalse();
});

test('appendNavPageAtomic allows a Published page (happy path still works)', function () {
    // Seed a home page so CompositionDefaults doesn't auto-add 'visible-page'
    // to the starter nav — the append is the interesting assertion.
    $site = Site::factory()->create();
    makeRevisionedPage($site->id, 'home', PageStatus::Published);
    $page = makeRevisionedPage($site->id, 'visible-page', PageStatus::Published);
    $cs = app(CompositionService::class);

    // Start the draft fresh, then clear any auto-added entries so we can
    // assert the append itself is what adds the page.
    $draft = $cs->getOrCreateDraft($site);
    $composition = $draft->composition;
    $composition['nav']['items'] = [];
    $draft->composition = $composition;
    $draft->save();

    $result = $cs->appendNavPageAtomic($site, $page->id, 'Visible', MutationSource::Pipeline);

    expect($result)->toBeTrue();
    expect($draft->fresh()->composition['nav']['items'])->toHaveCount(1);
    expect($draft->fresh()->composition['nav']['items'][0]['page_id'])->toBe($page->id);
});

test('publishSite excludes Draft pages from new version', function () {
    $site = Site::factory()->create();
    makeRevisionedPage($site->id, 'home', PageStatus::Published);
    makeRevisionedPage($site->id, 'about', PageStatus::Published);
    makeRevisionedPage($site->id, 'hidden', PageStatus::Draft);
    makeRevisionedPage($site->id, 'old', PageStatus::Archived);

    $version = app(SitePublishService::class)->publishSite($site);

    expect($version->page_revisions)->toHaveCount(2);
    $pinnedTypes = GeneratedPage::whereIn('id', collect($version->page_revisions)->pluck('page_id'))
        ->pluck('page_type')->sort()->values()->all();
    expect($pinnedTypes)->toBe(['about', 'home']);
});

test('publishSite prunes nav items referencing non-Published pages', function () {
    $site = Site::factory()->create();
    $home = makeRevisionedPage($site->id, 'home', PageStatus::Published);
    $about = makeRevisionedPage($site->id, 'about', PageStatus::Published);
    $hidden = makeRevisionedPage($site->id, 'hidden', PageStatus::Draft);

    // Hand-craft a draft composition that includes the Draft page in nav
    // (simulating a legacy/stale state — appendNavPageAtomic now refuses,
    // but historical data or admin status flip after add could leave this
    // residue).
    $draft = SiteDraft::create([
        'site_id' => $site->id,
        'composition' => [
            'nav' => ['items' => [
                ['type' => 'page', 'page_id' => $about->id, 'label' => 'About'],
                ['type' => 'page', 'page_id' => $hidden->id, 'label' => 'Hidden'],
            ]],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'footer' => ['columns' => [], 'show_credit' => true],
            'homepage_page_id' => $home->id,
        ],
        'updated_at' => now(),
    ]);

    $version = app(SitePublishService::class)->publishSite($site);

    $navItems = $version->composition['nav']['items'];
    expect($navItems)->toHaveCount(1);
    expect($navItems[0]['page_id'])->toBe($about->id);
});

test('publishSite prunes nav group children that reference non-Published pages', function () {
    $site = Site::factory()->create();
    $home = makeRevisionedPage($site->id, 'home', PageStatus::Published);
    $about = makeRevisionedPage($site->id, 'about', PageStatus::Published);
    $s1 = makeRevisionedPage($site->id, 'service-one', PageStatus::Published);
    $s2 = makeRevisionedPage($site->id, 'service-two', PageStatus::Draft);

    SiteDraft::create([
        'site_id' => $site->id,
        'composition' => [
            'nav' => ['items' => [
                ['type' => 'page', 'page_id' => $about->id, 'label' => 'About'],
                ['type' => 'group', 'label' => 'Services', 'children' => [
                    ['type' => 'page', 'page_id' => $s1->id, 'label' => 'Service One'],
                    ['type' => 'page', 'page_id' => $s2->id, 'label' => 'Service Two'],
                ]],
            ]],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'footer' => ['columns' => [], 'show_credit' => true],
            'homepage_page_id' => $home->id,
        ],
        'updated_at' => now(),
    ]);

    $version = app(SitePublishService::class)->publishSite($site);

    $nav = $version->composition['nav']['items'];
    $group = collect($nav)->firstWhere('type', 'group');
    expect($group['children'])->toHaveCount(1);
    expect($group['children'][0]['page_id'])->toBe($s1->id);
});

test('publishSite drops a nav group entirely when all children become non-Published', function () {
    $site = Site::factory()->create();
    $home = makeRevisionedPage($site->id, 'home', PageStatus::Published);
    $s1 = makeRevisionedPage($site->id, 'service-one', PageStatus::Draft);
    $s2 = makeRevisionedPage($site->id, 'service-two', PageStatus::Archived);

    SiteDraft::create([
        'site_id' => $site->id,
        'composition' => [
            'nav' => ['items' => [
                ['type' => 'group', 'label' => 'Services', 'children' => [
                    ['type' => 'page', 'page_id' => $s1->id, 'label' => 'S1'],
                    ['type' => 'page', 'page_id' => $s2->id, 'label' => 'S2'],
                ]],
            ]],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'footer' => ['columns' => [], 'show_credit' => true],
            'homepage_page_id' => $home->id,
        ],
        'updated_at' => now(),
    ]);

    $version = app(SitePublishService::class)->publishSite($site);

    expect($version->composition['nav']['items'])->toBeEmpty();
});

test('publishSite preserves non-page nav items (shop, custom links)', function () {
    $site = Site::factory()->create();
    $home = makeRevisionedPage($site->id, 'home', PageStatus::Published);

    SiteDraft::create([
        'site_id' => $site->id,
        'composition' => [
            'nav' => ['items' => [
                ['type' => 'shop', 'label' => 'Shop'],
                ['type' => 'link', 'href' => 'https://example.com', 'label' => 'External'],
            ]],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'footer' => ['columns' => [], 'show_credit' => true],
            'homepage_page_id' => $home->id,
        ],
        'updated_at' => now(),
    ]);

    $version = app(SitePublishService::class)->publishSite($site);

    expect($version->composition['nav']['items'])->toHaveCount(2);
    expect(collect($version->composition['nav']['items'])->pluck('type')->all())
        ->toBe(['shop', 'link']);
});

test('publishSite throws when no Published pages exist', function () {
    $site = Site::factory()->create();
    makeRevisionedPage($site->id, 'home', PageStatus::Draft);
    makeRevisionedPage($site->id, 'about', PageStatus::Archived);

    expect(fn () => app(SitePublishService::class)->publishSite($site))
        ->toThrow(\App\Exceptions\Site\SitePublishException::class);
});
