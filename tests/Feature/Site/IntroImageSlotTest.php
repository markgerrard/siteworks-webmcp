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

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// (a) Migration + backfill: existing rows default to slot='hero'
// ---------------------------------------------------------------------------

test('existing hero_versions rows have slot defaulting to hero', function () {
    $site = Site::factory()->create();

    // Create a row without specifying slot — should default via DB default.
    $hv = HeroVersion::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'url' => 'https://example.test/hero.jpg',
        'watermark_url' => null,
        'prompt' => 'test prompt',
        'model' => 'demo-test',
        'placement' => [],
        'is_active' => true,
    ]);

    expect($hv->fresh()->slot)->toBe('hero');
});

// ---------------------------------------------------------------------------
// (b) HeroVersionService slot scoping
// ---------------------------------------------------------------------------

test('activate with slot=hero creates a hero-slot row', function () {
    $site = Site::factory()->create();

    $hv = app(HeroVersionService::class)->activate($site->id, 'home', [
        'url' => 'https://example.test/hero.jpg',
        'prompt' => 'hero prompt',
        'model' => 'demo',
        'placement' => ['text_zone' => 'middle-left'],
    ], 'hero');

    expect($hv->slot)->toBe('hero');
    expect($hv->is_active)->toBeTrue();
});

test('activate with slot=intro creates an intro-slot row independently of hero slot', function () {
    $site = Site::factory()->create();

    app(HeroVersionService::class)->activate($site->id, 'home', [
        'url' => 'https://example.test/hero.jpg',
        'prompt' => 'hero prompt',
        'model' => 'demo',
        'placement' => [],
    ], 'hero');

    $intro = app(HeroVersionService::class)->activate($site->id, 'home', [
        'url' => 'https://example.test/intro.jpg',
        'prompt' => 'intro prompt',
        'model' => 'demo',
        'placement' => [],
    ], 'intro');

    expect($intro->slot)->toBe('intro');
    expect($intro->is_active)->toBeTrue();

    // Both hero AND intro should be active simultaneously for the same page_type.
    $activeHero = HeroVersion::where('site_id', $site->id)
        ->where('page_type', 'home')
        ->where('slot', 'hero')
        ->where('is_active', true)
        ->count();

    $activeIntro = HeroVersion::where('site_id', $site->id)
        ->where('page_type', 'home')
        ->where('slot', 'intro')
        ->where('is_active', true)
        ->count();

    expect($activeHero)->toBe(1);
    expect($activeIntro)->toBe(1);
});

test('scopeHeroSlot and scopeIntroSlot filter by slot correctly', function () {
    $site = Site::factory()->create();

    app(HeroVersionService::class)->activate($site->id, 'about', [
        'url' => 'https://example.test/hero.jpg',
        'prompt' => 'h',
        'model' => 'g',
        'placement' => [],
    ], 'hero');

    app(HeroVersionService::class)->activate($site->id, 'about', [
        'url' => 'https://example.test/intro.jpg',
        'prompt' => 'i',
        'model' => 'g',
        'placement' => [],
    ], 'intro');

    $heroRows = HeroVersion::where('site_id', $site->id)->where('page_type', 'about')->heroSlot()->get();
    $introRows = HeroVersion::where('site_id', $site->id)->where('page_type', 'about')->introSlot()->get();

    expect($heroRows)->toHaveCount(1);
    expect($heroRows->first()->slot)->toBe('hero');
    expect($introRows)->toHaveCount(1);
    expect($introRows->first()->slot)->toBe('intro');
});

test('activate second hero deactivates the first hero but leaves intro active', function () {
    $site = Site::factory()->create();

    $hero1 = app(HeroVersionService::class)->activate($site->id, 'contact', [
        'url' => 'https://example.test/hero1.jpg',
        'prompt' => 'h1',
        'model' => 'g',
        'placement' => [],
    ], 'hero');

    app(HeroVersionService::class)->activate($site->id, 'contact', [
        'url' => 'https://example.test/intro.jpg',
        'prompt' => 'i',
        'model' => 'g',
        'placement' => [],
    ], 'intro');

    // Regenerate the hero — should only deactivate the old hero, not the intro.
    $hero2 = app(HeroVersionService::class)->activate($site->id, 'contact', [
        'url' => 'https://example.test/hero2.jpg',
        'prompt' => 'h2',
        'model' => 'g',
        'placement' => [],
    ], 'hero');

    expect($hero1->fresh()->is_active)->toBeFalse();
    expect($hero2->fresh()->is_active)->toBeTrue();

    // Intro must still be active.
    $activeIntros = HeroVersion::where('site_id', $site->id)
        ->where('page_type', 'contact')
        ->where('slot', 'intro')
        ->where('is_active', true)
        ->count();

    expect($activeIntros)->toBe(1);
});

// ---------------------------------------------------------------------------
// (c) PageRenderer exposes $introImages when intro rows exist
// ---------------------------------------------------------------------------

function setupSiteForIntroTest(): array
{
    $site = Site::factory()->create(['business_name' => 'Intro Test Co', 'theme' => 'trades-bold']);

    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'hero_source' => 'shared',
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

test('PageRenderer renders without error when intro HeroVersion rows exist', function () {
    [$site, $page] = setupSiteForIntroTest();

    // Write both hero and intro slots.
    app(HeroVersionService::class)->activate($site->id, 'home', [
        'url' => 'https://cdn.test/hero.jpg',
        'watermark_url' => null,
        'prompt' => 'hero',
        'model' => 'demo',
        'placement' => ['text_zone' => 'middle-left'],
    ], 'hero');

    app(HeroVersionService::class)->activate($site->id, 'home', [
        'url' => 'https://cdn.test/intro.jpg',
        'watermark_url' => 'https://cdn.test/intro-wm.jpg',
        'prompt' => 'intro',
        'model' => 'demo',
        'placement' => [],
    ], 'intro');

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toBeString()->not->toBeEmpty();
});

test('PageRenderer renders without error when no intro rows exist (graceful null)', function () {
    [$site, $page] = setupSiteForIntroTest();

    // Only hero slot — no intro row written.
    app(HeroVersionService::class)->activate($site->id, 'home', [
        'url' => 'https://cdn.test/hero.jpg',
        'watermark_url' => null,
        'prompt' => 'hero',
        'model' => 'demo',
        'placement' => [],
    ], 'hero');

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toBeString()->not->toBeEmpty();
});
