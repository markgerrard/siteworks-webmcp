<?php

use App\Enums\ProjectItemType;
use App\Enums\ProjectsLayout;
use App\Models\GeneratedPage;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Models\SiteMedia;
use App\Services\Site\PageRenderer;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('galleryImages relation excludes hero / legacy regen rows', function () {
    $site = Site::factory()->create();
    $item = ProjectItem::factory()->caseStudy()->for($site)->create();

    // Hero row — no role tag.
    $hero = SiteMedia::factory()->create([
        'site_id' => $site->id,
        'project_item_id' => $item->id,
        'metadata' => ['width' => 100],
    ]);
    $item->update(['image_id' => $hero->id]);

    // Legacy regen of the hero — no role tag, must not appear as gallery extra.
    SiteMedia::factory()->create([
        'site_id' => $site->id,
        'project_item_id' => $item->id,
        'metadata' => ['width' => 100],
    ]);

    // Two real gallery extras.
    $extra0 = SiteMedia::factory()->create([
        'site_id' => $site->id,
        'project_item_id' => $item->id,
        'metadata' => ['role' => 'case_study_gallery', 'gallery_index' => 0],
    ]);
    $extra1 = SiteMedia::factory()->create([
        'site_id' => $site->id,
        'project_item_id' => $item->id,
        'metadata' => ['role' => 'case_study_gallery', 'gallery_index' => 1],
    ]);

    $extras = $item->galleryImages()->get();
    expect($extras->pluck('id')->all())->toBe([$extra0->id, $extra1->id]);
});

it('blade renders the multi-image grid only when extras exist', function () {
    $site = Site::factory()->create([
        'business_name' => 'Acme',
        'project_categories' => ['Residential'],
        'projects_layout' => ProjectsLayout::CaseStudies,
    ]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects']);

    // Public render hydrates Published only (factory default is Draft).
    $caseStudyWithExtras = ProjectItem::factory()->caseStudy()->published()->for($site)->create([
        'page_id' => $page->id,
        'category' => 'Residential',
        'title' => 'Has Extras Project',
    ]);
    SiteMedia::factory()->count(3)->sequence(
        ['url' => 'https://test.example/extra-a.jpg'],
        ['url' => 'https://test.example/extra-b.jpg'],
        ['url' => 'https://test.example/extra-c.jpg'],
    )->create([
        'site_id' => $site->id,
        'project_item_id' => $caseStudyWithExtras->id,
        'metadata' => ['role' => 'case_study_gallery', 'gallery_index' => 0],
    ]);

    $caseStudyHeroOnly = ProjectItem::factory()->caseStudy()->published()->for($site)->create([
        'page_id' => $page->id,
        'category' => 'Residential',
        'title' => 'Hero Only Project',
    ]);

    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'projects_hero', 'title' => 'Our Work'],
            ['type' => 'project_gallery', 'title' => 'Recent Work', 'item_ids' => []],
            ['type' => 'case_study_highlights', 'title' => 'Case Studies', 'item_ids' => [$caseStudyWithExtras->id, $caseStudyHeroOnly->id]],
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

    // The case study with extras renders the grid container.
    expect($html)->toContain('Has Extras Project');
    expect($html)->toContain('grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4');
    // Three extras render as <img> tags inside the section.
    expect($html)->toContain('extra-a.jpg');
    expect($html)->toContain('extra-b.jpg');
    expect($html)->toContain('extra-c.jpg');
    // The hero-only case study renders, but the grid wrapper appears only
    // for the one with extras (not twice — that would mean both rendered grids).
    expect($html)->toContain('Hero Only Project');
    expect(substr_count($html, 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4'))->toBe(1);
});
