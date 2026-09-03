<?php

use App\Enums\Archetype;
use App\Enums\PageKind;
use App\Enums\PageStatus;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageLayoutRegistry;
use App\Services\Site\PageRenderer;

function projectDetailPage(Site $site, string $pageType, GeneratedPage $parent, array $attributes = []): GeneratedPage
{
    return GeneratedPage::factory()->for($site)->create(array_merge([
        'page_type' => $pageType,
        'parent_id' => $parent->id,
        'kind' => PageKind::ProjectDetail,
        'status' => PageStatus::Published,
        'nav_label' => 'Loft Conversion',
    ], $attributes));
}

it('maps an explicitly kinded nested page to project_detail ahead of page_type heuristics', function () {
    $site = Site::factory()->create();
    $parent = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'projects',
        'kind' => PageKind::Core,
    ]);
    $detail = projectDetailPage($site, 'projects/loft-conversion-wigan', $parent);
    $registry = app(PageLayoutRegistry::class);

    expect($registry->layoutKindForPage($detail))->toBe('project_detail')
        ->and($detail->isServicePage())->toBeFalse();
});

it('does not let a nested project_detail page fall through to the service kind', function () {
    $site = Site::factory()->create();
    $parent = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'projects',
        'kind' => PageKind::Core,
    ]);
    $detail = projectDetailPage($site, 'projects/loft-conversion-wigan', $parent);
    $registry = app(PageLayoutRegistry::class);

    expect($registry->layoutKindForPage($detail))->not->toBe('service')
        ->and($registry->layoutKindForPage($detail))->not->toBeNull();
});

it('keeps home about projects and service heuristics when kind is not project_detail', function () {
    $site = Site::factory()->create();
    $registry = app(PageLayoutRegistry::class);
    $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'kind' => PageKind::Core]);
    $about = GeneratedPage::factory()->for($site)->create(['page_type' => 'about', 'kind' => PageKind::Core]);
    $projects = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects', 'kind' => PageKind::Core]);
    $service = GeneratedPage::factory()->for($site)->create(['page_type' => 'roofing', 'kind' => PageKind::Service]);
    $guide = GeneratedPage::factory()->for($site)->create(['page_type' => 'guide-x', 'kind' => PageKind::Guide]);

    expect($registry->layoutKindForPage($home))->toBe('home')
        ->and($registry->layoutKindForPage($about))->toBe('about')
        ->and($registry->layoutKindForPage($projects))->toBe('projects')
        ->and($registry->layoutKindForPage($service))->toBe('service')
        ->and($registry->layoutKindForPage($guide))->toBeNull();
});

it('includes nested detail pages in pageTypesForKind and picker options', function () {
    $site = Site::factory()->create();
    $parent = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'projects',
        'kind' => PageKind::Core,
    ]);
    $detail = projectDetailPage($site, 'projects/loft-conversion-wigan', $parent);
    $registry = app(PageLayoutRegistry::class);

    expect($registry->pageTypesForKind($site, 'project_detail'))->toContain('projects/loft-conversion-wigan')
        ->and($registry->pageTypesForKind($site, 'projects'))->toBe(['projects'])
        ->and($registry->pageTypesForKind($site, 'project_detail'))->not->toContain($detail->parent->page_type)
        ->and($registry->optionsFor($site, 'project_detail'))->toHaveKey('classic')
        ->and($registry->optionsFor($site, 'project_detail')['classic']['label'])->toBeString();
});

it('does not inject a lead form onto an explicitly kinded project_detail page', function () {
    $site = Site::factory()->create(['business_name' => 'Acme Lofts', 'theme' => 'trades-bold']);
    BusinessProfile::factory()->for($site)->create([
        'profile_data' => [
            'archetype' => Archetype::EmergencyTrade->value,
            'lead_form_policy' => 'all',
            'contact' => ['phones' => ['0161 123 4567'], 'emails' => ['hello@acme.test']],
            'geo' => ['service_area' => 'Wigan'],
        ],
    ]);

    $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'kind' => PageKind::Core]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Home Hero'],
            ['type' => 'lead_form', 'title' => 'Get in touch', 'intro' => 'Tell us about it.', 'submit_label' => 'Send', 'extra_fields' => []],
            ['type' => 'cta', 'title' => 'Home CTA'],
        ]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);

    $parent = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'projects',
        'kind' => PageKind::Core,
        'nav_label' => 'Our Work',
    ]);
    $detail = projectDetailPage($site, 'projects/loft-conversion-wigan', $parent);
    $detailRev = PageRevision::factory()->for($detail, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'project_detail_hero', 'title' => 'Loft conversion in Wigan', 'intro' => 'A quiet extra storey.'],
            ['type' => 'project_cta_row', 'title' => 'Planning something similar?', 'cta_label' => 'Have a chat', 'cta_url' => '/contact'],
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
            'homepage_page_id' => $home->id,
        ],
        'page_revisions' => [
            ['page_id' => $home->id, 'revision_id' => $homeRev->id],
            ['page_id' => $detail->id, 'revision_id' => $detailRev->id],
        ],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    $renderer = app(PageRenderer::class);
    $inject = new ReflectionMethod($renderer, 'injectServiceLeadForm');
    $injected = $inject->invoke(
        $renderer,
        $site,
        $detail,
        $detailRev->content_data['sections'],
    );

    expect(collect($injected)->firstWhere('type', 'lead_form'))->toBeNull();

    $html = $renderer->render($site, $detail->id, mode: 'public');
    expect($html)->not->toContain('Get a free')
        ->and($html)->not->toContain('type="lead_form"')
        ->and($html)->not->toContain('data-form-kind="lead_form"');
});

it('exposes a usable stock classic recipe that never names lead_form', function () {
    $registry = app(PageLayoutRegistry::class);
    $recipe = config('site_project_detail_layouts.classic');

    expect($recipe)->toBeArray()
        ->and($registry->validate($recipe, 'project_detail'))->toBe([])
        ->and($registry->isUsable($recipe, 'project_detail'))->toBeTrue()
        ->and($recipe['variants'] ?? [])->not->toHaveKey('lead_form')
        ->and(PageLayoutRegistry::ALLOWED_FAMILIES['project_detail'] ?? [])->not->toContain('lead_form')
        ->and(PageLayoutRegistry::ALLOWED_FAMILIES['project_detail'] ?? [])->toEqualCanonicalizing([
            'project_detail_hero',
            'project_meta_band',
            'project_photo_essay',
            'project_about',
            'project_cta_row',
            'similar_projects',
        ]);
});
