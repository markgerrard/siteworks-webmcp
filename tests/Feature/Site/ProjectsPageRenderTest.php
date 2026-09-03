<?php

use App\Models\GeneratedPage;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function setupPublishedProjectsPage(): array
{
    $site = Site::factory()->create([
        'business_name' => 'Acme Roofing',
        'theme' => 'trades-bold',
        'project_categories' => ['Residential', 'Commercial', 'Heritage'],
    ]);

    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects']);

    // Public render hydrates Published only (factory default is Draft).
    $galleryItems = ProjectItem::factory()->gallery()->published()->for($site)->count(6)->create([
        'page_id' => $page->id,
        'category' => 'Residential',
    ]);
    $caseStudies = ProjectItem::factory()->caseStudy()->published()->for($site)->count(2)->create([
        'page_id' => $page->id,
        'category' => 'Commercial',
    ]);

    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'projects_hero', 'title' => 'Our Projects', 'subtitle' => 'Precision-engineered.'],
            ['type' => 'project_gallery', 'title' => 'Recent Work', 'item_ids' => $galleryItems->pluck('id')->all()],
            ['type' => 'case_study_highlights', 'title' => 'Case Studies', 'item_ids' => $caseStudies->pluck('id')->all()],
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

    return [$site, $page, $galleryItems, $caseStudies];
}

it('renders a hand-seeded projects page with all three section types', function () {
    [$site, $page, $galleryItems, $caseStudies] = setupPublishedProjectsPage();

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('Our Projects');
    expect($html)->toContain('Precision-engineered.');
    expect($html)->toContain('Recent Work');
    expect($html)->toContain('Case Studies');
    expect($html)->toContain('aspect-[4/5]');
    expect($html)->toContain('md:grid-cols-12');
    expect($html)->toContain($galleryItems->first()->title);
    expect($html)->toContain($caseStudies->first()->title);
});

it('renders placeholder tiles when image_id is null', function () {
    $site = Site::factory()->create([
        'business_name' => 'Acme',
        'project_categories' => ['Residential'],
    ]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects']);

    $items = ProjectItem::factory()->gallery()->published()->for($site)->count(3)->create([
        'page_id' => $page->id,
        'image_id' => null,
        'category' => 'Residential',
    ]);

    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'project_gallery', 'item_ids' => $items->pluck('id')->all()],
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

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    foreach ($items as $item) {
        expect($html)->toContain($item->category);
    }
    // No <img src appears for the gallery tiles (they're null). The logo
    // may still render — so check there's no "project_items/..." path.
    expect($html)->not->toContain('project_items/');
});

it('eager-loads project items without N+1', function () {
    [$site, $page, $galleryItems] = setupPublishedProjectsPage();

    \DB::enableQueryLog();
    app(PageRenderer::class)->render($site, $page->id, mode: 'public');
    $queries = collect(\DB::getQueryLog())
        ->filter(fn ($q) => str_contains($q['query'], 'from "project_items"'));

    // Renderer should issue exactly one query for project_items
    expect($queries->count())->toBeLessThanOrEqual(1);
});
