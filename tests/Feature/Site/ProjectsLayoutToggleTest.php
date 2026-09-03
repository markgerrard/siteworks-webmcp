<?php

use App\Enums\ProjectsLayout;
use App\Models\GeneratedPage;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function makeProjectsPageForLayoutTest(): array
{
    $site = Site::factory()->create([
        'business_name' => 'Acme Landscaping',
        'theme' => 'trades-bold',
        'project_categories' => ['Residential', 'Commercial'],
    ]);

    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects']);

    // Public render hydrates Published only (drafts stay admin-preview).
    $galleryItems = ProjectItem::factory()->gallery()->published()->for($site)->count(3)->create([
        'page_id' => $page->id,
        'category' => 'Residential',
    ]);
    $caseStudies = ProjectItem::factory()->caseStudy()->published()->for($site)->count(2)->create([
        'page_id' => $page->id,
        'category' => 'Commercial',
        'metrics' => [
            ['icon' => 'shield', 'label' => 'Coastal Design'],
            ['icon' => 'scale', 'label' => 'Low Maintenance'],
        ],
    ]);

    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'projects_hero', 'title' => 'Our Work'],
            ['type' => 'project_gallery', 'title' => 'Recent Work', 'item_ids' => $galleryItems->pluck('id')->all()],
            ['type' => 'case_study_highlights', 'title' => 'Featured Case Studies', 'item_ids' => $caseStudies->pluck('id')->all()],
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

it('renders the tile grid by default (projects_layout = grid)', function () {
    [$site, $page, $galleryItems, $caseStudies] = makeProjectsPageForLayoutTest();

    // DB default applied at insert; reload so the model reflects it.
    expect($site->fresh()->projects_layout)->toBe(ProjectsLayout::Grid);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    // Gallery tiles visible — the 4/5 aspect ratio is unique to project_gallery.
    expect($html)->toContain('aspect-[4/5]');
    expect($html)->toContain($galleryItems->first()->title);
    // Case-study-highlights alternating block also visible.
    expect($html)->toContain($caseStudies->first()->title);
    // The new long-form blade is NOT in this output.
    expect($html)->not->toContain('Featured Projects');
});

it('switches to the long-form case-study layout when projects_layout = case_studies', function () {
    [$site, $page, $galleryItems, $caseStudies] = makeProjectsPageForLayoutTest();

    $site->update(['projects_layout' => ProjectsLayout::CaseStudies]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    // Gallery tile-grid is hidden under case_studies layout.
    expect($html)->not->toContain('aspect-[4/5]');
    foreach ($galleryItems as $g) {
        expect($html)->not->toContain($g->title);
    }
    // Case-study items still visible — same data, new template.
    expect($html)->toContain($caseStudies->first()->title);
    // Tag chips drawn from the metrics array.
    expect($html)->toContain('Coastal Design');
    expect($html)->toContain('Low Maintenance');
});

it('toggle is bidirectional and non-destructive', function () {
    [$site, $page, $galleryItems] = makeProjectsPageForLayoutTest();

    $site->update(['projects_layout' => ProjectsLayout::CaseStudies]);
    $caseStudiesHtml = app(PageRenderer::class)->render($site, $page->id, mode: 'public');
    expect($caseStudiesHtml)->not->toContain($galleryItems->first()->title);

    $site->update(['projects_layout' => ProjectsLayout::Grid]);
    $gridHtml = app(PageRenderer::class)->render($site, $page->id, mode: 'public');
    expect($gridHtml)->toContain($galleryItems->first()->title);
});

it('only transforms the projects page — other pages are unaffected', function () {
    [$site] = makeProjectsPageForLayoutTest();
    $site->update(['projects_layout' => ProjectsLayout::CaseStudies]);

    // Add a non-projects page that happens to carry a project_gallery
    // section (defensive — the layout switch must scope to page_type).
    $homePage = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $homeItems = ProjectItem::factory()->gallery()->published()->for($site)->count(2)->create([
        'page_id' => $homePage->id,
        'category' => 'Residential',
    ]);

    $homeRev = PageRevision::factory()->for($homePage, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'project_gallery', 'title' => 'Home gallery', 'item_ids' => $homeItems->pluck('id')->all()],
        ]],
    ]);
    $homePage->update(['published_revision_id' => $homeRev->id]);

    // Update the SiteVersion to include the home page.
    $current = SiteVersionCurrent::where('site_id', $site->id)->first();
    $version = SiteVersion::find($current->version_id);
    $version->update([
        'page_revisions' => [
            ['page_id' => $homePage->id, 'revision_id' => $homeRev->id],
        ],
    ]);

    $html = app(PageRenderer::class)->render($site, $homePage->id, mode: 'public');
    // The gallery on the home page renders intact even with case_studies layout
    // toggled, because the transform only applies on page_type = projects.
    expect($html)->toContain($homeItems->first()->title);
    expect($html)->toContain('aspect-[4/5]');
});
