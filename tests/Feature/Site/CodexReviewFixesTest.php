<?php

use App\Enums\AgentRole;
use App\Enums\MutationSource;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Models\User;
use App\Services\Site\CompositionService;
use App\Services\Site\SitePublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function seedBasicSite(PageStatus $homeStatus = PageStatus::Published): Site
{
    $site = Site::factory()->create();
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'content_data' => [],
        'sort_order' => 0,
        'version' => 1,
        'status' => $homeStatus,
    ]);
    $rev = PageRevision::create(['page_id' => $page->id, 'content_data' => [], 'ai_generated' => false, 'created_at' => now()]);
    $page->update(['published_revision_id' => $rev->id]);

    return $site;
}

// ============ HIGH 1 ============

test('HIGH 1: publishSite syncs the pruned composition back to the draft (banner clears after publish)', function () {
    $site = seedBasicSite();
    $hidden = GeneratedPage::create([
        'site_id' => $site->id, 'page_type' => 'hidden', 'content_data' => [],
        'sort_order' => 5, 'version' => 1, 'status' => PageStatus::Draft,
    ]);
    PageRevision::create(['page_id' => $hidden->id, 'content_data' => [], 'ai_generated' => false, 'created_at' => now()])
        ->id;
    $hidden->update(['published_revision_id' => PageRevision::where('page_id', $hidden->id)->first()->id]);

    // Craft a draft whose nav references the Draft page (stale entry)
    SiteDraft::create([
        'site_id' => $site->id,
        'composition' => [
            'nav' => ['items' => [
                ['type' => 'page', 'page_id' => $hidden->id, 'label' => 'Stale'],
            ]],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'footer' => ['columns' => [], 'show_credit' => true],
            'homepage_page_id' => $site->generatedPages()->where('page_type', 'home')->value('id'),
        ],
        'updated_at' => now(),
    ]);

    app(SitePublishService::class)->publishSite($site);

    // Draft nav should have been cleaned up in lock-step with the publish
    $freshDraft = SiteDraft::where('site_id', $site->id)->first();
    expect($freshDraft->composition['nav']['items'])->toBeEmpty();

    // Banner's source-of-truth: hasPendingComposition now false
    expect(app(CompositionService::class)->hasPendingComposition($site))->toBeFalse();
});

// ============ HIGH 2 ============

test('HIGH 2: updatePageStatus bumps admin_revision atomically with the status write', function () {
    // Reproduce the race conceptually: after updatePageStatus returns, BOTH
    // the status AND admin_revision must have moved. A split-transaction
    // would allow the check to pass between the two writes.
    $site = seedBasicSite();
    $preview = Preview::factory()->create(['site_id' => $site->id]);
    $snap = $preview->snapshot ?? [];
    $snap['pages']['home'] = [];
    $preview->update(['snapshot' => $snap]);

    $before = (int) (SiteDraft::where('site_id', $site->id)->value('admin_revision') ?? 0);

    $staff = User::factory()->staff(AgentRole::Admin)->create();
    Livewire::actingAs($staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('updatePageStatus', 'home', 'draft');

    // Both changes landed
    $page = GeneratedPage::where('site_id', $site->id)->where('page_type', 'home')->first();
    expect($page->status)->toBe(PageStatus::Draft);

    $after = (int) SiteDraft::where('site_id', $site->id)->value('admin_revision');
    expect($after)->toBe($before + 1);
});

test('HIGH 2: CompositionService::applyAdminChange composes mutation + bump atomically', function () {
    $site = seedBasicSite();
    $user = User::factory()->create();
    $cs = app(CompositionService::class);
    $cs->getOrCreateDraft($site);

    $ran = false;
    $cs->applyAdminChange($site, function () use (&$ran) {
        $ran = true;
    }, userId: $user->id);

    expect($ran)->toBeTrue();
    $rev = (int) SiteDraft::where('site_id', $site->id)->value('admin_revision');
    expect($rev)->toBe(1);
});

// ============ HIGH 3 ============

test('HIGH 3: banner does not 500 when the site has a published version but no SiteDraft row', function () {
    $site = seedBasicSite();

    // Simulate the first-publish path: publish a version but deliberately
    // leave site_drafts empty. (publishSite normally creates one, so we
    // remove it after to reach the state.)
    app(SitePublishService::class)->publishSite($site);
    SiteDraft::where('site_id', $site->id)->delete();
    expect(SiteDraft::where('site_id', $site->id)->count())->toBe(0);

    $staff = User::factory()->staff(AgentRole::Admin)->create();

    // Mount must not throw a fatal
    Livewire::actingAs($staff)
        ->test('site.unpublished-changes-banner', ['siteId' => $site->id])
        ->assertSet('pending', false);
});

// ============ MEDIUM ============

test('MEDIUM: reserveServicePageSlots persists unique sort_orders across calls', function () {
    // Regression: two concurrent addServicePages on the same site used to
    // compute the same max(sort_order) and reserve overlapping slots. The
    // fix inserts placeholder rows inside the reservation transaction so
    // subsequent callers see the higher max.
    $site = seedBasicSite();
    $cs = app(CompositionService::class);

    $first = $cs->reserveServicePageSlots($site, [
        ['service' => 'Boiler Servicing', 'slug' => 'boiler-servicing', 'nav_label' => 'Boiler Servicing'],
        ['service' => 'Bathroom Fitting', 'slug' => 'bathroom-fitting', 'nav_label' => 'Bathroom Fitting'],
    ]);

    // Both slugs reserved, monotonically increasing from the existing max
    expect($first)->toHaveKeys(['boiler-servicing', 'bathroom-fitting']);
    expect(count(array_unique($first)))->toBe(2); // no duplicate slots

    // Placeholder rows landed with status=Draft + the reserved sort_order
    foreach ($first as $slug => $sortOrder) {
        $gp = GeneratedPage::where('site_id', $site->id)->where('page_type', $slug)->first();
        expect($gp)->not->toBeNull();
        expect($gp->status)->toBe(PageStatus::Draft);
        expect($gp->sort_order)->toBe($sortOrder);
    }

    // Second reservation must see the bumped max, not recompute on stale data
    $second = $cs->reserveServicePageSlots($site, [
        ['service' => 'Kitchen Fitting', 'slug' => 'kitchen-fitting', 'nav_label' => 'Kitchen Fitting'],
    ]);
    expect($second)->toHaveKey('kitchen-fitting');
    $allSlots = array_merge(array_values($first), [$second['kitchen-fitting']]);
    expect(count(array_unique($allSlots)))->toBe(count($allSlots)); // still no collisions
    expect($second['kitchen-fitting'])->toBeGreaterThan(max($first));
});

test('MEDIUM: reserveServicePageSlots skips slugs that already exist as pages or placeholders', function () {
    $site = seedBasicSite();
    // Add an existing page
    GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'plumbing',
        'content_data' => [],
        'sort_order' => 2,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);

    $cs = app(CompositionService::class);
    $reserved = $cs->reserveServicePageSlots($site, [
        ['service' => 'Plumbing', 'slug' => 'plumbing', 'nav_label' => 'Plumbing'],
        ['service' => 'Roofing', 'slug' => 'roofing', 'nav_label' => 'Roofing'],
    ]);

    expect($reserved)->not->toHaveKey('plumbing'); // existing, skipped
    expect($reserved)->toHaveKey('roofing');       // new, reserved
});

test('MEDIUM: publishSite excludes placeholder (Draft) service pages — they are not publicly live until the job promotes them', function () {
    $site = seedBasicSite();
    $cs = app(CompositionService::class);

    $cs->reserveServicePageSlots($site, [
        ['service' => 'Kitchen Fitting', 'slug' => 'kitchen-fitting', 'nav_label' => 'Kitchen Fitting'],
    ]);

    // Publish NOW — placeholder exists but is status=Draft so must be
    // excluded from the new SiteVersion's page_revisions pin.
    $version = app(SitePublishService::class)->publishSite($site);

    $pinnedPageIds = collect($version->page_revisions)->pluck('page_id')->all();
    $placeholder = GeneratedPage::where('site_id', $site->id)->where('page_type', 'kitchen-fitting')->first();

    expect($pinnedPageIds)->not->toContain($placeholder->id);
});

// ============ LOW ============

test('LOW: view-live rejects backslash + encoded variants + control chars (no open redirect)', function () {
    $controller = app(\App\Http\Controllers\Site\PublicEditExitController::class);

    $badTargets = [
        '\\\\evil.test',              // plain backslash
        '/\\evil.test',               // /\ → //evil on some browsers
        '/%5C%5Cevil.test',           // encoded backslashes
        '/%2F%2Fevil.test',           // encoded //
        "/\x00evil.test",             // NUL
        "/\tevil.test",               // tab
        "/\nevil.test",               // newline
        'https://evil.test',          // absolute URL
        '//evil.test',                // protocol-relative
    ];

    foreach ($badTargets as $bad) {
        $request = \Illuminate\Http\Request::create('/_edit/view-live?to='.urlencode($bad), 'GET');
        /** @var \Illuminate\Http\RedirectResponse $response */
        $response = $controller->viewLive($request);
        $path = parse_url($response->getTargetUrl(), PHP_URL_PATH) ?: '/';
        expect($path)->toBe('/', "open-redirect bypass allowed for: {$bad}");
    }
});

test('LOW: view-live keeps ordinary same-host paths intact', function () {
    $controller = app(\App\Http\Controllers\Site\PublicEditExitController::class);
    foreach (['/about', '/services/boiler-installations', '/contact?x=1', '/a/b/c'] as $ok) {
        $request = \Illuminate\Http\Request::create('/_edit/view-live?to='.urlencode($ok), 'GET');
        $response = $controller->viewLive($request);
        expect($response->getTargetUrl())->toEndWith($ok);
    }
});
