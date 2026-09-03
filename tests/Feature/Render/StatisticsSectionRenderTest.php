<?php

use App\Enums\PageKind;
use App\Models\GeneratedPage;
use App\Models\LayoutPreset;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createStatisticsPage(Site $site, array $sectionData, string $pageType = 'about'): GeneratedPage
{
    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => $pageType,
        'kind' => PageKind::Core,
    ]);

    $sections = array_merge([
        ['type' => 'statistics', ...$sectionData],
    ]);

    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => $sections],
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

    return $page;
}

it('renders classic statistics variant with numerals, labels, suffixes, and prefixes', function () {
    $site = Site::factory()->create();
    $page = createStatisticsPage($site, [
        'title' => 'Proven Results in Numbers',
        'eyebrow' => 'Key Metrics',
        'intro' => 'Decades of combined experience delivering outstanding results across the region.',
        'items' => [
            ['value' => '250', 'label' => 'Projects Completed', 'suffix' => '+', 'prefix' => ''],
            ['value' => '15', 'label' => 'Years Experience', 'suffix' => '', 'prefix' => ''],
            ['value' => '99.4', 'label' => 'Client Satisfaction', 'suffix' => '%', 'prefix' => ''],
            ['value' => '500', 'label' => 'Average Savings', 'suffix' => 'k', 'prefix' => '£', 'description' => 'Across commercial builds'],
        ],
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('Proven Results in Numbers')
        ->and($html)->toContain('Key Metrics')
        ->and($html)->toContain('Decades of combined experience')
        ->and($html)->toContain('250')
        ->and($html)->toContain('Projects Completed')
        ->and($html)->toContain('+')
        ->and($html)->toContain('15')
        ->and($html)->toContain('Years Experience')
        ->and($html)->toContain('99.4')
        ->and($html)->toContain('%')
        ->and($html)->toContain('£')
        ->and($html)->toContain('500')
        ->and($html)->toContain('Across commercial builds');
});

it('renders server-rendered final values directly in markup for reduced-motion / no-JS state', function () {
    $site = Site::factory()->create();
    $page = createStatisticsPage($site, [
        'title' => 'Our Track Record',
        'items' => [
            ['value' => '1,500', 'label' => 'Installations', 'suffix' => '+'],
            ['value' => '98', 'label' => 'Five Star Reviews', 'suffix' => '%'],
        ],
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    // Final values must be present in the server-rendered HTML text
    expect($html)->toContain('>1,500<')
        ->and($html)->toContain('>98<')
        ->and($html)->toContain('data-stat-target="1,500"')
        ->and($html)->toContain('data-stat-final="1,500"');
});

it('contains NO script bytes when stat_count_up option is absent or false (inertness)', function () {
    $site = Site::factory()->create();
    $page = createStatisticsPage($site, [
        'title' => 'Our Figures',
        'items' => [
            ['value' => '100', 'label' => 'Projects'],
        ],
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    // Output must contain NO count-up script bytes when option is unstamped
    expect($html)->not->toContain('data-stat-counted')
        ->and($html)->not->toContain('animateValue')
        ->and($html)->not->toContain('IntersectionObserver')
        ->and($html)->not->toContain('stat-count-up');
});

it('emits count-up inline script when stat_count_up option is stamped true on the section', function () {
    $site = Site::factory()->create();

    // Create layout preset opting in to stat_count_up
    LayoutPreset::create([
        'site_id' => $site->id,
        'page_kind' => 'about',
        'key' => 'motion-about',
        'label' => 'Motion About',
        'status' => LayoutPreset::STATUS_ACTIVE,
        'recipe' => [
            'schema_version' => 1,
            'variants' => ['statistics' => 'classic'],
            'options' => ['stat_count_up' => true],
            'eyebrow_policy' => 'all',
        ],
    ]);
    $site->update(['about_layout' => 'motion-about']);

    $page = createStatisticsPage($site, [
        'title' => 'Animated Figures',
        'items' => [
            ['value' => '350', 'label' => 'Clients', 'suffix' => '+'],
        ],
    ], pageType: 'about');

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('data-stat-section')
        ->and($html)->toContain('data-stat-target="350"')
        ->and($html)->toContain('<script>')
        ->and($html)->toContain('IntersectionObserver')
        ->and($html)->toContain('prefers-reduced-motion')
        ->and($html)->toContain('data-stat-counted');
});

it('emits count-up inline script when motion_tier subtle/expressive/cinema expands stat_count_up', function () {
    $site = Site::factory()->create();

    LayoutPreset::create([
        'site_id' => $site->id,
        'page_kind' => 'about',
        'key' => 'tier-about',
        'label' => 'Tier About',
        'status' => LayoutPreset::STATUS_ACTIVE,
        'recipe' => [
            'schema_version' => 1,
            'variants' => ['statistics' => 'classic'],
            'options' => ['motion_tier' => 'subtle'],
            'eyebrow_policy' => 'all',
        ],
    ]);
    $site->update(['about_layout' => 'tier-about']);

    $page = createStatisticsPage($site, [
        'title' => 'Subtle Motion Figures',
        'items' => [
            ['value' => '42', 'label' => 'Awards Won'],
        ],
    ], pageType: 'about');

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('data-stat-section')
        ->and($html)->toContain('data-stat-target="42"')
        ->and($html)->toContain('<script>')
        ->and($html)->toContain('IntersectionObserver');
});

it('script is IntersectionObserver-gated, unobserves after trigger, and guards against reduced motion', function () {
    $site = Site::factory()->create();

    LayoutPreset::create([
        'site_id' => $site->id,
        'page_kind' => 'about',
        'key' => 'guard-about',
        'label' => 'Guard About',
        'status' => LayoutPreset::STATUS_ACTIVE,
        'recipe' => [
            'schema_version' => 1,
            'variants' => ['statistics' => 'classic'],
            'options' => ['stat_count_up' => true],
            'eyebrow_policy' => 'all',
        ],
    ]);
    $site->update(['about_layout' => 'guard-about']);

    $page = createStatisticsPage($site, [
        'title' => 'Safety Tested',
        'items' => [
            ['value' => '100', 'label' => 'Percent Safe'],
        ],
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    // Verify key safety patterns in the script
    expect($html)->toContain('prefers-reduced-motion: reduce')
        ->and($html)->toContain('obs.unobserve(section)')
        ->and($html)->toContain('data-stat-counted');
});

it('renders editable markers in editor mode', function () {
    $site = Site::factory()->create();
    $page = createStatisticsPage($site, [
        'title' => 'Editor Test',
        'eyebrow' => 'Editable Eyebrow',
        'intro' => 'Editable Intro',
        'items' => [
            ['value' => '120', 'label' => 'Reviews', 'suffix' => '+', 'prefix' => '>', 'description' => 'Verified'],
        ],
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'admin-edit');

    expect($html)->toContain('data-editable="page.'.$page->id.'.section.0.title"')
        ->and($html)->toContain('data-editable="page.'.$page->id.'.section.0.eyebrow"')
        ->and($html)->toContain('data-editable="page.'.$page->id.'.section.0.intro"')
        ->and($html)->toContain('data-editable="page.'.$page->id.'.section.0.items.0.value"')
        ->and($html)->toContain('data-editable="page.'.$page->id.'.section.0.items.0.label"')
        ->and($html)->toContain('data-editable="page.'.$page->id.'.section.0.items.0.suffix"')
        ->and($html)->toContain('data-editable="page.'.$page->id.'.section.0.items.0.prefix"')
        ->and($html)->toContain('data-editable="page.'.$page->id.'.section.0.items.0.description"');
});

it('suppresses eyebrow when __suppress_eyebrow is set', function () {
    $site = Site::factory()->create();
    $page = createStatisticsPage($site, [
        'title' => 'No Eyebrow Here',
        'eyebrow' => 'Should Be Hidden',
        '__suppress_eyebrow' => true,
        'items' => [
            ['value' => '10', 'label' => 'Years'],
        ],
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('No Eyebrow Here')
        ->and($html)->not->toContain('Should Be Hidden');
});

function motionStatsSite(string $value): array {
    $site = Site::factory()->create();
    LayoutPreset::create([
        'site_id' => $site->id,
        'page_kind' => 'about',
        'key' => 'motion-about',
        'label' => 'Motion About',
        'status' => LayoutPreset::STATUS_ACTIVE,
        'recipe' => [
            'schema_version' => 1,
            'variants' => ['statistics' => 'classic'],
            'options' => ['stat_count_up' => true],
            'eyebrow_policy' => 'all',
        ],
    ]);
    $site->update(['about_layout' => 'motion-about']);
    $page = createStatisticsPage($site, [
        'title' => 'Numbers',
        'items' => [['value' => $value, 'label' => 'Things', 'suffix' => '']],
    ], pageType: 'about');

    return [$site, $page];
}

it('suppresses the count-up script in admin-edit mode', function () {
    [$site, $page] = motionStatsSite('250');

    $public = app(PageRenderer::class)->render($site, $page->id, mode: 'public');
    $admin = app(PageRenderer::class)->render($site, $page->id, mode: 'admin-edit');

    expect($public)->toContain('IntersectionObserver')
        ->and($admin)->toContain('data-stat-target')
        ->and($admin)->not->toContain('IntersectionObserver');
});

it('does not double-escape stat values in data attributes', function () {
    [$site, $page] = motionStatsSite('1,200 & counting');

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('data-stat-final="1,200 &amp; counting"')
        ->and($html)->not->toContain('&amp;amp;');
});
