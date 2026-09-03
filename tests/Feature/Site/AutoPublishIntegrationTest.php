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
use App\Models\User;
use App\Services\Site\AutoPublishCoordinator;
use App\Services\Site\CompositionService;
use App\Services\Site\SitePublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * End-to-end coverage of the page-status + auto-publish story.
 *
 * Doesn't actually run the AI service — Bus::fake() captures the
 * GenerateServicePageJob instances the page-manager would dispatch,
 * and we invoke the batch's then()/catch() callbacks manually with
 * a fresh state to verify the auto-publish decision logic.
 */
function seedSiteForIntegration(int $extraPages = 0): Site
{
    $site = Site::factory()->create(['preview_domain' => 'integration-test']);

    // Home + about pinned in a real published SiteVersion — baseline
    $home = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'content_data' => [],
        'sort_order' => 0,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    PageRevision::create(['page_id' => $home->id, 'content_data' => [], 'ai_generated' => false, 'created_at' => now()])->id;
    $home->update(['published_revision_id' => PageRevision::where('page_id', $home->id)->first()->id]);

    $about = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'about',
        'content_data' => [],
        'sort_order' => 1,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    PageRevision::create(['page_id' => $about->id, 'content_data' => [], 'ai_generated' => false, 'created_at' => now()]);
    $about->update(['published_revision_id' => PageRevision::where('page_id', $about->id)->first()->id]);

    // Any extra Published pages the test wants (simulates a prior pipeline fill)
    for ($i = 0; $i < $extraPages; $i++) {
        $p = GeneratedPage::create([
            'site_id' => $site->id,
            'page_type' => 'extra-'.($i + 1),
            'content_data' => [],
            'sort_order' => 2 + $i,
            'version' => 1,
            'status' => PageStatus::Published,
        ]);
        PageRevision::create(['page_id' => $p->id, 'content_data' => [], 'ai_generated' => false, 'created_at' => now()]);
        $p->update(['published_revision_id' => PageRevision::where('page_id', $p->id)->first()->id]);
    }

    Preview::factory()->create(['site_id' => $site->id, 'slug' => 'integration-test-'.$site->id]);

    // Baseline publish — draft in sync with live
    app(SitePublishService::class)->publishSite($site);

    return $site;
}

beforeEach(fn () => $this->staff = User::factory()->staff(AgentRole::Admin)->create());

test('clean batch → auto-publish fires; new service pages are pinned into next SiteVersion', function () {
    $site = seedSiteForIntegration(extraPages: 2);
    $versionsBefore = SiteVersion::where('site_id', $site->id)->count();
    $preBatchRev = (int) (SiteDraft::where('site_id', $site->id)->value('admin_revision') ?? 0);

    // Pipeline simulation: the batch-handler side of the workflow would create the
    // content, revision and nav entries; we stand in for that by seeding the pages directly.
    $newService = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'boiler-repairs',
        'content_data' => [],
        'sort_order' => 10,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    PageRevision::create(['page_id' => $newService->id, 'content_data' => [], 'ai_generated' => false, 'created_at' => now()]);
    $newService->update(['published_revision_id' => PageRevision::where('page_id', $newService->id)->first()->id]);

    // Pipeline-sourced nav append (does NOT bump admin_revision)
    app(CompositionService::class)->appendNavPageAtomic(
        $site, $newService->id, 'Boiler Repairs', MutationSource::Pipeline
    );

    // Now the coordinator finalises the batch — admin didn't touch the
    // draft, so auto-publish should fire.
    app(AutoPublishCoordinator::class)->finalizeAfterBatch(
        siteId: $site->id,
        preBatchRev: $preBatchRev,
        userId: $this->staff->id,
        batchId: 'batch-clean-'.uniqid(),
        pagesExpected: 1,
    );

    expect(SiteVersion::where('site_id', $site->id)->count())->toBe($versionsBefore + 1);

    $newVersion = SiteVersion::where('site_id', $site->id)->latest('id')->first();
    $pinnedIds = collect($newVersion->page_revisions)->pluck('page_id')->map(fn ($i) => (int) $i);
    expect($pinnedIds)->toContain($newService->id);
    expect($newVersion->publish_note)->toContain('Auto-publish after bulk service-page generation');
});

test('admin edit mid-batch → auto-publish skipped, banner shows pending', function () {
    $site = seedSiteForIntegration();
    $versionsBefore = SiteVersion::where('site_id', $site->id)->count();
    $preBatchRev = (int) (SiteDraft::where('site_id', $site->id)->value('admin_revision') ?? 0);

    // Pipeline adds a service page (no admin_revision bump)
    $newService = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'new-service',
        'content_data' => [],
        'sort_order' => 5,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    PageRevision::create(['page_id' => $newService->id, 'content_data' => [], 'ai_generated' => false, 'created_at' => now()]);
    $newService->update(['published_revision_id' => PageRevision::where('page_id', $newService->id)->first()->id]);
    app(CompositionService::class)->appendNavPageAtomic(
        $site, $newService->id, 'New Service', MutationSource::Pipeline
    );

    // Admin edits during batch: Published → Draft on the home page.
    // This goes through the Livewire action so admin_revision bumps.
    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('updatePageStatus', 'home', 'draft');

    expect((int) SiteDraft::where('site_id', $site->id)->value('admin_revision'))
        ->toBeGreaterThan($preBatchRev);

    // Coordinator notices admin_revision bumped → skips publish
    app(AutoPublishCoordinator::class)->finalizeAfterBatch(
        siteId: $site->id,
        preBatchRev: $preBatchRev,
        userId: $this->staff->id,
        batchId: 'batch-interrupted-'.uniqid(),
        pagesExpected: 1,
    );

    expect(SiteVersion::where('site_id', $site->id)->count())->toBe($versionsBefore);

    // Banner surfaces pending state
    Livewire::actingAs($this->staff)
        ->test('site.unpublished-changes-banner', ['siteId' => $site->id])
        ->assertSet('pending', true);
});

test('subsequent manual publish via banner goes through, includes admin intent', function () {
    $site = seedSiteForIntegration();

    // Pipeline adds a page
    $newService = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'another-service',
        'content_data' => [],
        'sort_order' => 5,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    PageRevision::create(['page_id' => $newService->id, 'content_data' => [], 'ai_generated' => false, 'created_at' => now()]);
    $newService->update(['published_revision_id' => PageRevision::where('page_id', $newService->id)->first()->id]);
    app(CompositionService::class)->appendNavPageAtomic(
        $site, $newService->id, 'Another Service', MutationSource::Pipeline
    );

    // Admin archives the about page mid-edit
    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('updatePageStatus', 'about', 'archived');

    // Admin then clicks "Publish now" in the banner
    Livewire::actingAs($this->staff)
        ->test('site.unpublished-changes-banner', ['siteId' => $site->id])
        ->call('publish');

    // New version reflects BOTH: new service page pinned + about dropped
    $newVersion = SiteVersion::where('site_id', $site->id)->latest('id')->first();
    $pinned = collect($newVersion->page_revisions)->pluck('page_id')->map(fn ($i) => (int) $i);
    expect($pinned)->toContain($newService->id);

    $aboutId = GeneratedPage::where('site_id', $site->id)->where('page_type', 'about')->value('id');
    expect($pinned)->not->toContain($aboutId);

    // Nav does NOT include about (status=archived)
    $navPageIds = collect($newVersion->composition['nav']['items'] ?? [])
        ->where('type', 'page')
        ->pluck('page_id')
        ->map(fn ($i) => (int) $i);
    expect($navPageIds)->not->toContain($aboutId);
});
