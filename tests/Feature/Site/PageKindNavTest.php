<?php

use App\Enums\AgentRole;
use App\Enums\PageKind;
use App\Enums\PageOrigin;
use App\Enums\PageStatus;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Models\User;
use App\Services\Site\PageRenderer;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * setupPublishedSite() idiom from tests/Unit/Site/PageRendererTest.php,
 * extended so extra published pages can carry kind/origin.
 *
 * @param  array<int, array<string, mixed>>  $extraPages
 * @return array{0: Site, 1: GeneratedPage, 2: array<string, GeneratedPage>}
 */
function setupPublishedKindSite(array $extraPages = [], array $profileData = []): array
{
    $site = Site::factory()->create(['business_name' => 'Acme', 'theme' => 'trades-bold']);
    BusinessProfile::factory()->for($site)->create([
        'profile_data' => array_merge([
            'archetype' => 'local_service',
            'lead_form_policy' => 'all',
        ], $profileData),
    ]);

    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
        'origin' => PageOrigin::Pipeline,
        'nav_label' => 'Home',
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Welcome to Acme'],
            ['type' => 'lead_form', 'title' => 'Get a quote', 'intro' => 'Tell us about it.', 'submit_label' => 'Send'],
        ]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);

    $byType = ['home' => $home];
    $pageRevisions = [
        ['page_id' => $home->id, 'revision_id' => $homeRev->id],
    ];

    foreach ($extraPages as $attrs) {
        $page = GeneratedPage::factory()->for($site)->create(array_merge([
            'status' => PageStatus::Published,
        ], $attrs));
        $rev = PageRevision::factory()->for($page, 'page')->create([
            'content_data' => ['sections' => [
                ['type' => 'hero', 'title' => $page->nav_label ?: $page->page_type],
                ['type' => 'intro', 'body' => 'Body copy.'],
                ['type' => 'cta', 'title' => 'Call us'],
            ]],
        ]);
        $page->update(['published_revision_id' => $rev->id]);
        $byType[$page->page_type] = $page;
        $pageRevisions[] = ['page_id' => $page->id, 'revision_id' => $rev->id];
    }

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => collect($byType)->values()->map(fn (GeneratedPage $p) => [
                'type' => 'page',
                'label' => $p->nav_label ?: $p->displayName(),
                'page_id' => $p->id,
            ])->all()],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'homepage_page_id' => $home->id,
        ],
        'page_revisions' => $pageRevisions,
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    return [$site, $home, $byType];
}

function autoGroupServicesFor(array $pages): array
{
    $items = collect($pages)->map(fn (GeneratedPage $p) => [
        'type' => 'page',
        'label' => $p->nav_label ?: $p->displayName(),
        'page_type' => $p->page_type,
        'page_id' => $p->id,
        'href' => '/'.$p->page_type,
    ])->all();

    $method = new ReflectionMethod(PageRenderer::class, 'autoGroupServices');

    return $method->invoke(app(PageRenderer::class), $items);
}

function seedPageManagerSite(PageKind $kind, string $pageType): array
{
    $staff = User::factory()->staff(AgentRole::Admin)->create();
    $site = Site::factory()->create();
    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => $pageType,
        'kind' => $kind,
        'origin' => PageOrigin::Pipeline,
        'hero_source' => 'shared',
        'status' => PageStatus::Published,
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Story']]],
    ]);
    Preview::factory()->create(['site_id' => $site->id]);
    RateLimiter::clear("hero-regen:{$site->id}");
    RateLimiter::clear("hero-variations:{$site->id}");

    return [$staff, $site, $page];
}

// ─── Delta (a): lead-form injection is kind=service only ────────────────

it('does not inject a lead_form on a published guide page but still injects on a service page', function () {
    [$site, $home, $pages] = setupPublishedKindSite([
        [
            'page_type' => 'kitchen-cost-guide',
            'nav_label' => 'Kitchen Cost Guide',
            'kind' => PageKind::Guide,
            'origin' => PageOrigin::Managed,
        ],
        [
            'page_type' => 'roofing-wigan',
            'nav_label' => 'Roofing',
            'kind' => PageKind::Service,
            'origin' => PageOrigin::Pipeline,
        ],
    ]);

    $renderer = app(PageRenderer::class);
    $guideHtml = $renderer->render($site, $pages['kitchen-cost-guide']->id, mode: 'public');
    $serviceHtml = $renderer->render($site, $pages['roofing-wigan']->id, mode: 'public');

    expect($guideHtml)->not->toContain('name="message"')
        ->and($serviceHtml)->toContain('name="message"')
        ->and($serviceHtml)->toContain('Get a free Roofing quote');
});

// ─── Delta (b): autoGroupServices groups pipeline services only ──────────

it('render-time nav groups only pipeline services; core projects and managed services stay top-level', function () {
    // Full public render — not a reflection call into autoGroupServices.
    // Desktop dropdown children emit `block px-4 py-2`; top-level links do not.
    // Removing the page_id thread in resolveNavItems must turn this red:
    // projects + managed services fall through to the reserved-type list and
    // land inside the Services dropdown.
    [$site, $home] = setupPublishedKindSite([
        ['page_type' => 'about', 'nav_label' => 'About', 'kind' => PageKind::Core, 'origin' => PageOrigin::Pipeline],
        ['page_type' => 'projects', 'nav_label' => 'Our Work', 'kind' => PageKind::Core, 'origin' => PageOrigin::Pipeline],
        ['page_type' => 'pipeline-one', 'nav_label' => 'Pipeline One', 'kind' => PageKind::Service, 'origin' => PageOrigin::Pipeline],
        ['page_type' => 'pipeline-two', 'nav_label' => 'Pipeline Two', 'kind' => PageKind::Service, 'origin' => PageOrigin::Pipeline],
        ['page_type' => 'pipeline-three', 'nav_label' => 'Pipeline Three', 'kind' => PageKind::Service, 'origin' => PageOrigin::Pipeline],
        ['page_type' => 'managed-roofing', 'nav_label' => 'Managed Roofing', 'kind' => PageKind::Service, 'origin' => PageOrigin::Managed],
        ['page_type' => 'contact', 'nav_label' => 'Contact', 'kind' => PageKind::Core, 'origin' => PageOrigin::Pipeline],
    ]);

    $html = app(PageRenderer::class)->render($site, $home->id, mode: 'public');

    expect($html)->toMatch('/<button[^>]*>\s*Services/')
        ->and($html)->toMatch('/class="block px-4 py-2[^"]*">\s*Pipeline One\s*</')
        ->and($html)->toMatch('/class="block px-4 py-2[^"]*">\s*Pipeline Two\s*</')
        ->and($html)->toMatch('/class="block px-4 py-2[^"]*">\s*Pipeline Three\s*</')
        ->and($html)->not->toMatch('/class="block px-4 py-2[^"]*">\s*Our Work\s*</')
        ->and($html)->not->toMatch('/class="block px-4 py-2[^"]*">\s*Managed Roofing\s*</')
        ->and($html)->toMatch('/class="text-sm font-medium transition-colors[^"]*">\s*Our Work\s*</')
        ->and($html)->toMatch('/class="text-sm font-medium transition-colors[^"]*">\s*Managed Roofing\s*</');
});

it('auto-groups only pipeline service pages, leaving core projects and managed services ungrouped', function () {
    $site = Site::factory()->create();

    $pages = collect([
        ['page_type' => 'home', 'nav_label' => 'Home', 'kind' => PageKind::Core, 'origin' => PageOrigin::Pipeline],
        ['page_type' => 'about', 'nav_label' => 'About', 'kind' => PageKind::Core, 'origin' => PageOrigin::Pipeline],
        ['page_type' => 'projects', 'nav_label' => 'Our Work', 'kind' => PageKind::Core, 'origin' => PageOrigin::Pipeline],
        ['page_type' => 'pipeline-one', 'nav_label' => 'Pipeline One', 'kind' => PageKind::Service, 'origin' => PageOrigin::Pipeline],
        ['page_type' => 'pipeline-two', 'nav_label' => 'Pipeline Two', 'kind' => PageKind::Service, 'origin' => PageOrigin::Pipeline],
        ['page_type' => 'pipeline-three', 'nav_label' => 'Pipeline Three', 'kind' => PageKind::Service, 'origin' => PageOrigin::Pipeline],
        ['page_type' => 'managed-roofing', 'nav_label' => 'Managed Roofing', 'kind' => PageKind::Service, 'origin' => PageOrigin::Managed],
        ['page_type' => 'contact', 'nav_label' => 'Contact', 'kind' => PageKind::Core, 'origin' => PageOrigin::Pipeline],
    ])->map(fn (array $attrs) => GeneratedPage::factory()->for($site)->create($attrs))->all();

    $grouped = autoGroupServicesFor($pages);

    $servicesGroup = collect($grouped)->firstWhere('type', 'group');
    expect($servicesGroup)->not->toBeNull()
        ->and($servicesGroup['label'])->toBe('Services')
        ->and(collect($servicesGroup['children'])->pluck('label')->all())
        ->toBe(['Pipeline One', 'Pipeline Two', 'Pipeline Three']);

    $topLevel = collect($grouped)->where('type', 'page')->pluck('label')->all();
    expect($topLevel)->toContain('Our Work')
        ->and($topLevel)->toContain('Managed Roofing')
        ->and($topLevel)->not->toContain('Pipeline One');
});

// ─── Delta (c): OrganiseNavJob skips editorial/guide extras ──────────────

// ─── Carry-forward B: OrganiseNavJob must hydrate kind+origin ────────────

// ─── Carry-forward A: page-manager dedicated-hero gates use !isCorePage ──

// ─── Carry-forward C: writers stamp kind+origin at creation ──────────────

// ─── Carry-forward D: (site_id, kind) index ─────────────────────────────

it('indexes generated_pages by site_id and kind', function () {
    $indexes = collect(Schema::getIndexes('generated_pages'));

    expect($indexes->contains(fn (array $index) => $index['columns'] === ['site_id', 'kind']))->toBeTrue();
});
