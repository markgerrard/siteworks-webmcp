<?php

use App\Enums\PageKind;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\LayoutPreset;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;


/**
 * @return array{0: Site, 1: LayoutPreset}
 */
function promotableDonor(string $kind = 'project_detail', string $key = 'eden-projects'): array
{
    $site = Site::factory()->create();
    $row = LayoutPreset::factory()->active()->create([
        'site_id' => $site->id,
        'page_kind' => $kind,
        'key' => $key,
        'label' => 'Eden projects',
        'description' => 'Eden detail personality',
        'recipe' => [
            'schema_version' => 1,
            'variants' => ['project_about' => 'split'],
            'eyebrow_policy' => 'all',
            'eyebrow_sections' => [],
        ],
    ]);

    return [$site, $row];
}

it('emits a paste-ready config stub and writes nothing', function () {
    [$site, $row] = promotableDonor();

    $this->artisan('site:layout-promote', ['site' => (string) $site->id, 'key' => 'eden-projects', '--kind' => 'project_detail'])
        ->expectsOutputToContain("config/site_project_detail_layouts.php")
        ->expectsOutputToContain("'eden-projects' =>")
        ->expectsOutputToContain("'label' => 'Eden projects'")
        ->expectsOutputToContain("'project_about' => 'split'")
        ->expectsOutputToContain('--finalize')
        ->assertSuccessful();

    expect($row->fresh()->status)->toBe(LayoutPreset::STATUS_ACTIVE);
});

it('rejects an unknown kind', function () {
    [$site] = promotableDonor();

    $this->artisan('site:layout-promote', ['site' => (string) $site->id, 'key' => 'eden-projects', '--kind' => 'detail'])
        ->expectsOutputToContain('Invalid --kind')
        ->assertFailed();
});

it('emits a chrome stub whose recipe round-trips through the chrome validator', function () {
    $site = Site::factory()->create();
    $row = LayoutPreset::factory()->active()->create([
        'site_id' => $site->id,
        'page_kind' => 'chrome',
        'key' => 'centred-badge',
        'label' => 'Centred badge',
        'description' => 'Badge logo, nav beneath',
        'recipe' => [
            'schema_version' => 1,
            'layout' => 'centred',
            'top_bar' => 'off',
            'nav_row' => 'beneath',
            'nav_case' => 'caps',
            'logo_height' => 'md',
            'store_controls' => 'icons+labels',
            'sticky_shrink' => 'on',
        ],
    ]);

    $this->artisan('site:layout-promote', [
        'site' => (string) $site->id,
        'key' => 'centred-badge',
        '--kind' => 'chrome',
    ])->expectsOutputToContain("config/site_chrome_layouts.php")
        ->expectsOutputToContain("'centred-badge' =>")
        ->expectsOutputToContain("'label' => 'Centred badge'")
        ->expectsOutputToContain("'layout' => 'centred'")
        ->expectsOutputToContain("'store_controls' => 'icons+labels'")
        ->expectsOutputToContain('--finalize')
        ->assertSuccessful();

    expect($row->fresh()->status)->toBe(LayoutPreset::STATUS_ACTIVE);
});

it('errors when no active Tier-1 row matches', function () {
    $site = Site::factory()->create();

    $this->artisan('site:layout-promote', ['site' => (string) $site->id, 'key' => 'ghost', '--kind' => 'project_detail'])
        ->expectsOutputToContain('No active Tier-1 row')
        ->assertFailed();
});

it('refuses a recipe that fails hard validation', function () {
    $site = Site::factory()->create();
    LayoutPreset::factory()->active()->create([
        'site_id' => $site->id,
        'page_kind' => 'project_detail',
        'key' => 'broken',
        'label' => 'Broken',
        'recipe' => [
            'schema_version' => 1,
            // 'story' is an about-kind family — wrong-kind families are hard errors.
            'variants' => ['story' => 'editorial'],
            'eyebrow_policy' => 'all',
            'eyebrow_sections' => [],
        ],
    ]);

    $this->artisan('site:layout-promote', ['site' => (string) $site->id, 'key' => 'broken', '--kind' => 'project_detail'])
        ->expectsOutputToContain('hard validation')
        ->assertFailed();
});

/**
 * @return array{0: Site, 1: GeneratedPage, 2: GeneratedPage}
 */
function promoteCutoverSite(): array
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

it('finalize retires the donor when the config recipe matches exactly', function () {
    config(['site.public_cache_enabled' => true]);
    [$site, $row] = promotableDonor();
    config()->set('site_project_detail_layouts.eden-projects', [
        'label' => 'Eden projects',
        'description' => 'Eden detail personality',
        'schema_version' => 1,
        'variants' => ['project_about' => 'split'],
        'eyebrow_policy' => 'all',
        'eyebrow_sections' => [],
    ]);
    $before = (int) cache()->get("site:{$site->id}:pubcache_counter", 0);

    $this->artisan('site:layout-promote', ['site' => (string) $site->id, 'key' => 'eden-projects', '--kind' => 'project_detail', '--finalize' => true])
        ->expectsOutputToContain('retired')
        ->assertSuccessful();

    expect($row->fresh()->status)->toBe(LayoutPreset::STATUS_RETIRED)
        ->and((int) cache()->get("site:{$site->id}:pubcache_counter", 0))->toBeGreaterThan($before);
});

it('finalize refuses a mismatched config recipe and retires nothing', function () {
    [$site, $row] = promotableDonor();
    config()->set('site_project_detail_layouts.eden-projects', [
        'label' => 'Eden projects',
        'description' => 'Eden detail personality',
        'schema_version' => 1,
        'variants' => ['project_about' => 'classic'], // differs from the row's 'split'
        'eyebrow_policy' => 'all',
        'eyebrow_sections' => [],
    ]);

    $this->artisan('site:layout-promote', ['site' => (string) $site->id, 'key' => 'eden-projects', '--kind' => 'project_detail', '--finalize' => true])
        ->expectsOutputToContain('does not match')
        ->assertFailed();

    expect($row->fresh()->status)->toBe(LayoutPreset::STATUS_ACTIVE);
});

it('finalize refuses when the config key is absent', function () {
    [$site, $row] = promotableDonor();

    $this->artisan('site:layout-promote', ['site' => (string) $site->id, 'key' => 'eden-projects', '--kind' => 'project_detail', '--finalize' => true])
        ->expectsOutputToContain('missing or unusable')
        ->assertFailed();

    expect($row->fresh()->status)->toBe(LayoutPreset::STATUS_ACTIVE);
});

it('is idempotent after promotion', function () {
    [$site, $row] = promotableDonor();
    $row->update(['status' => LayoutPreset::STATUS_RETIRED]);
    config()->set('site_project_detail_layouts.eden-projects', [
        'label' => 'Eden projects',
        'description' => 'Eden detail personality',
        'schema_version' => 1,
        'variants' => ['project_about' => 'split'],
        'eyebrow_policy' => 'all',
        'eyebrow_sections' => [],
    ]);

    $this->artisan('site:layout-promote', ['site' => (string) $site->id, 'key' => 'eden-projects', '--kind' => 'project_detail', '--finalize' => true])
        ->expectsOutputToContain('already promoted')
        ->assertSuccessful();
});

it('finalize renders byte-identically before and after the cutover', function () {
    // Full render scaffolding: projects parent carrying the donor key as
    // its override, one detail page under it (same shape as Task 0's
    // helper, unique Pest global name).
    [$site, $parent, $detail] = promoteCutoverSite();
    $donor = LayoutPreset::factory()->active()->create([
        'site_id' => $site->id,
        'page_kind' => 'project_detail',
        'key' => 'eden-projects',
        'label' => 'Eden projects',
        'description' => 'Eden detail personality',
        'recipe' => [
            'schema_version' => 1,
            'variants' => ['project_about' => 'split'],
            'eyebrow_policy' => 'all',
            'eyebrow_sections' => [],
        ],
    ]);
    $parent->update(['layout_preset_key' => 'eden-projects']);
    config()->set('site_project_detail_layouts.eden-projects', [
        'label' => 'Eden projects',
        'description' => 'Eden detail personality',
        'schema_version' => 1,
        'variants' => ['project_about' => 'split'],
        'eyebrow_policy' => 'all',
        'eyebrow_sections' => [],
    ]);

    $before = app(\App\Services\Site\PageRenderer::class)->render($site->fresh(), $detail->id, mode: 'public');
    expect($before)->toContain('data-svc-variant="split"'); // Tier-1 row resolving

    $this->artisan('site:layout-promote', ['site' => (string) $site->id, 'key' => 'eden-projects', '--kind' => 'project_detail', '--finalize' => true])
        ->assertSuccessful();

    $after = app(\App\Services\Site\PageRenderer::class)->render($site->fresh(), $detail->id, mode: 'public');
    expect($after)->toBe($before) // the D8 identical-render cutover, proven on HTML
        // ...and the cutover actually happened: post-command the render is
        // served by config, not by a still-active donor.
        ->and($donor->fresh()->status)->toBe(LayoutPreset::STATUS_RETIRED);
});

it('finalize warns when the key is referenced by other sites', function () {
    [$site, $row] = promotableDonor();
    $other = Site::factory()->create(['services_layout' => 'eden-projects']);
    config()->set('site_project_detail_layouts.eden-projects', [
        'label' => 'Eden projects',
        'description' => 'Eden detail personality',
        'schema_version' => 1,
        'variants' => ['project_about' => 'split'],
        'eyebrow_policy' => 'all',
        'eyebrow_sections' => [],
    ]);

    $this->artisan('site:layout-promote', ['site' => (string) $site->id, 'key' => 'eden-projects', '--kind' => 'project_detail', '--finalize' => true])
        ->expectsOutputToContain('referenced elsewhere')
        ->assertSuccessful();
});

it('idempotent rerun refuses when the retired recipe drifted from config', function () {
    [$site, $row] = promotableDonor();
    $row->update(['status' => LayoutPreset::STATUS_RETIRED]);
    config()->set('site_project_detail_layouts.eden-projects', [
        'label' => 'Eden projects DRIFTED',
        'description' => 'Eden detail personality',
        'schema_version' => 1,
        'variants' => ['project_about' => 'split'],
        'eyebrow_policy' => 'all',
        'eyebrow_sections' => [],
    ]);

    $this->artisan('site:layout-promote', ['site' => (string) $site->id, 'key' => 'eden-projects', '--kind' => 'project_detail', '--finalize' => true])
        ->expectsOutputToContain('drifted')
        ->assertFailed();
});

it('phase 1 emits composition advisories from recipeWarnings', function () {
    $site = Site::factory()->create();
    LayoutPreset::factory()->active()->create([
        'site_id' => $site->id,
        'page_kind' => 'home',
        'key' => 'ledger-heavy',
        'label' => 'Ledger heavy',
        'description' => 'Two numbered-rows ledgers',
        'recipe' => [
            'schema_version' => 1,
            'variants' => ['services' => 'numbered-rows', 'trust' => 'numbered-rows'],
            'eyebrow_policy' => 'all',
            'eyebrow_sections' => [],
        ],
    ]);

    $this->artisan('site:layout-promote', ['site' => (string) $site->id, 'key' => 'ledger-heavy', '--kind' => 'home'])
        ->expectsOutputToContain('two ledgers on one home reads as monotony')
        ->assertSuccessful();
});
