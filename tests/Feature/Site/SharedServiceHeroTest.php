<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\HeroVersionService;
use App\Services\Site\PageRenderer;

beforeEach(fn () => $this->renderer = app(PageRenderer::class));

function setupSharedHeroSite(string $serviceHeroSource = 'shared'): array
{
    $site = Site::factory()->create([
        'business_name' => 'Acme Services',
        'theme' => 'trades-bold',
    ]);

    $homePage = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'hero_source' => 'shared',
    ]);
    $servicePage = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'roofing',
        'hero_source' => $serviceHeroSource,
    ]);

    $homeRevision = PageRevision::factory()->for($homePage, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Home hero', 'subtitle' => 'Welcome home'],
        ]],
    ]);
    $serviceRevision = PageRevision::factory()->for($servicePage, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Roofing services', 'subtitle' => 'Fast local roofing'],
        ]],
    ]);

    $homePage->update(['published_revision_id' => $homeRevision->id]);
    $servicePage->update(['published_revision_id' => $serviceRevision->id]);

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
            ['page_id' => $homePage->id, 'revision_id' => $homeRevision->id],
            ['page_id' => $servicePage->id, 'revision_id' => $serviceRevision->id],
        ],
        'published_at' => now(),
    ]);

    SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);

    return [$site, $homePage, $servicePage];
}

test('service pages with shared hero source use the shared service hero image full-bleed', function () {
    [$site, $homePage, $servicePage] = setupSharedHeroSite('shared');

    app(HeroVersionService::class)->activate($site->id, '__shared_service_hero', [
        'url' => 'https://cdn.example.com/shared-service.jpg',
        'watermark_url' => 'https://cdn.example.com/shared-service-watermark.jpg',
        'prompt' => 'shared',
        'model' => 'demo',
        'placement' => [],
    ]);

    $html = $this->renderer->render($site, $servicePage->id, mode: 'public');

    // Shared hero is the full-bleed background; not rendered as a
    // thumbnail image tag.
    expect($html)->toContain('shared-service-watermark.jpg')
        ->toContain("background-image: url('https://cdn.example.com/shared-service-watermark.jpg')");
});

test('service pages with dedicated hero source prefer a dedicated hero', function () {
    [$site, $homePage, $servicePage] = setupSharedHeroSite('dedicated');

    app(HeroVersionService::class)->activate($site->id, '__shared_service_hero', [
        'url' => 'https://cdn.example.com/shared-service.jpg',
        'watermark_url' => 'https://cdn.example.com/shared-service-watermark.jpg',
        'prompt' => 'shared',
        'model' => 'demo',
        'placement' => [],
    ]);
    app(HeroVersionService::class)->activate($site->id, 'roofing', [
        'url' => 'https://cdn.example.com/roofing-dedicated.jpg',
        'watermark_url' => 'https://cdn.example.com/roofing-dedicated-watermark.jpg',
        'prompt' => 'dedicated',
        'model' => 'demo',
        'placement' => [],
    ]);

    $html = $this->renderer->render($site, $servicePage->id, mode: 'public');

    expect($html)->toContain('roofing-dedicated-watermark.jpg')
        ->not->toContain('shared-service-watermark.jpg');
});

test('dedicated service pages fall back to the shared hero when no dedicated hero exists', function () {
    [$site, $homePage, $servicePage] = setupSharedHeroSite('dedicated');

    app(HeroVersionService::class)->activate($site->id, '__shared_service_hero', [
        'url' => 'https://cdn.example.com/shared-service.jpg',
        'watermark_url' => 'https://cdn.example.com/shared-service-watermark.jpg',
        'prompt' => 'shared',
        'model' => 'demo',
        'placement' => [],
    ]);

    $html = $this->renderer->render($site, $servicePage->id, mode: 'public');

    expect($html)->toContain('shared-service-watermark.jpg');
});

test('service pages fall back to a solid primary-colour hero when no image is available', function () {
    [$site, $homePage, $servicePage] = setupSharedHeroSite('shared');

    $html = $this->renderer->render($site, $servicePage->id, mode: 'public');

    // With no image, the full-bleed hero degrades to a flat primary
    // background (no thumbnail img tag in the hero).
    expect($html)->toContain('background-color: var(--brand-primary)')
        ->not->toContain('shared-service-watermark.jpg');
});

test('home pages keep using their dedicated home hero unchanged', function () {
    [$site, $homePage, $servicePage] = setupSharedHeroSite('shared');

    app(HeroVersionService::class)->activate($site->id, 'home', [
        'url' => 'https://cdn.example.com/home-hero.jpg',
        'watermark_url' => 'https://cdn.example.com/home-hero-watermark.jpg',
        'prompt' => 'home',
        'model' => 'demo',
        'placement' => [],
    ]);
    app(HeroVersionService::class)->activate($site->id, '__shared_service_hero', [
        'url' => 'https://cdn.example.com/shared-service.jpg',
        'watermark_url' => 'https://cdn.example.com/shared-service-watermark.jpg',
        'prompt' => 'shared',
        'model' => 'demo',
        'placement' => [],
    ]);

    $html = $this->renderer->render($site, $homePage->id, mode: 'public');

    expect($html)->toContain('home-hero-watermark.jpg')
        ->not->toContain('shared-service-watermark.jpg');
});

test('hero version service keeps only one active shared service hero version', function () {
    [$site] = setupSharedHeroSite('shared');

    $first = app(HeroVersionService::class)->activate($site->id, '__shared_service_hero', [
        'url' => 'https://cdn.example.com/shared-service-v1.jpg',
        'watermark_url' => 'https://cdn.example.com/shared-service-v1-watermark.jpg',
        'prompt' => 'shared-v1',
        'model' => 'demo',
        'placement' => [],
    ]);

    $second = app(HeroVersionService::class)->activate($site->id, '__shared_service_hero', [
        'url' => 'https://cdn.example.com/shared-service-v2.jpg',
        'watermark_url' => 'https://cdn.example.com/shared-service-v2-watermark.jpg',
        'prompt' => 'shared-v2',
        'model' => 'demo',
        'placement' => [],
    ]);

    expect($first->fresh()->is_active)->toBeFalse()
        ->and($second->fresh()->is_active)->toBeTrue()
        ->and($site->heroVersions()->where('page_type', '__shared_service_hero')->where('is_active', true)->count())->toBe(1);
});
