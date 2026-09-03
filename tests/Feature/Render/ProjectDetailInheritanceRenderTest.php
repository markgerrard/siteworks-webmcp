<?php

use App\Enums\PageKind;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\LayoutPreset;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: Site, 1: GeneratedPage, 2: GeneratedPage}
 */
function detailInheritanceSite(): array
{
    $site = Site::factory()->create(['theme' => 'trades-bold', 'services_layout' => 'classic']);

    $parent = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'projects',
        'kind' => PageKind::Core,
        'nav_label' => 'Our Work',
        'status' => PageStatus::Published,
    ]);
    $parentRev = PageRevision::factory()->for($parent, 'page')->create([
        'content_data' => ['sections' => [['type' => 'projects_hero', 'title' => 'Projects']]],
    ]);
    $parent->update(['published_revision_id' => $parentRev->id]);

    $detail = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'projects/loft-conversion-wigan',
        'parent_id' => $parent->id,
        'kind' => PageKind::ProjectDetail,
        'nav_label' => 'Loft Conversion Wigan',
        'status' => PageStatus::Published,
    ]);
    $detailRev = PageRevision::factory()->for($detail, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'project_detail_hero', 'title' => 'A quiet extra storey'],
            ['type' => 'project_about', 'title' => 'About', 'body' => 'A fuller story about this project with enough said to earn a page.'],
        ]],
    ]);
    $detail->update(['published_revision_id' => $detailRev->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'homepage_page_id' => $parent->id,
        ],
        'page_revisions' => [
            ['page_id' => $parent->id, 'revision_id' => $parentRev->id],
            ['page_id' => $detail->id, 'revision_id' => $detailRev->id],
        ],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    return [$site, $parent, $detail];
}

it('renders the detail About with the personality set on the parent projects page', function () {
    [$site, $parent, $detail] = detailInheritanceSite();

    // Baseline: no override anywhere -> classic About (recipe resolves null).
    $html = app(PageRenderer::class)->render($site->fresh(), $detail->id, mode: 'public');
    expect($html)->toContain('data-project-about')
        ->and($html)->toContain('data-svc-variant="classic"');

    // The picker's write path: parent projects page carries the override.
    // precision maps project_about => split (config/site_project_detail_layouts.php).
    $parent->update(['layout_preset_key' => 'precision']);

    $html = app(PageRenderer::class)->render($site->fresh(), $detail->id, mode: 'public');
    expect($html)->toContain('data-svc-variant="split"');
});

it('detail pages resolve a Tier-1 project_detail row through the parent override key', function () {
    [$site, $parent, $detail] = detailInheritanceSite();
    $parent->update(['layout_preset_key' => 'eden-projects']);

    // Without the detail-kind row the key resolves to nothing -> classic.
    $html = app(PageRenderer::class)->render($site->fresh(), $detail->id, mode: 'public');
    expect($html)->toContain('data-svc-variant="classic"');

    LayoutPreset::factory()->active()->create([
        'site_id' => $site->id,
        'page_kind' => 'project_detail',
        'key' => 'eden-projects',
        'label' => 'Eden projects',
        'description' => 'Eden detail personality',
        'recipe' => [
            'schema_version' => 1,
            'variants' => [
                'project_detail_hero' => 'classic',
                'project_meta_band' => 'classic',
                'project_about' => 'split',
                'project_photo_essay' => 'classic',
                'project_cta_row' => 'classic',
                'similar_projects' => 'classic',
            ],
            'options' => ['detail_heading' => 'ruled'],
            'eyebrow_policy' => 'all',
            'eyebrow_sections' => [],
        ],
    ]);

    $html = app(PageRenderer::class)->render($site->fresh(), $detail->id, mode: 'public');
    expect($html)->toContain('data-svc-variant="split"');
});

it('pins the classic and precision detail recipes the sweep must not touch', function () {
    expect(config('site_project_detail_layouts.classic.variants'))->toBe([
        'project_detail_hero' => 'classic',
        'project_meta_band' => 'classic',
        'project_about' => 'classic',
        'project_photo_essay' => 'classic',
        'project_cta_row' => 'classic',
        'similar_projects' => 'classic',
    ])->and(config('site_project_detail_layouts.precision.variants'))->toBe([
        'project_detail_hero' => 'classic',
        'project_meta_band' => 'classic',
        'project_about' => 'split',
        'project_photo_essay' => 'classic',
        'project_cta_row' => 'classic',
        'similar_projects' => 'classic',
    ]);
});
