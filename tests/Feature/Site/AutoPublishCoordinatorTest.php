<?php

use App\Enums\MutationSource;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\Site\SiteVersion;
use App\Services\Site\AutoPublishCoordinator;
use App\Services\Site\CompositionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function seedSiteWithHome(): Site
{
    $site = Site::factory()->create();
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'content_data' => [],
        'sort_order' => 0,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    $rev = PageRevision::create([
        'page_id' => $page->id,
        'content_data' => [],
        'ai_generated' => false,
        'created_at' => now(),
    ]);
    $page->update(['published_revision_id' => $rev->id]);

    return $site;
}

test('clean batch publishes with the auto-publish note', function () {
    $site = seedSiteWithHome();
    app(CompositionService::class)->getOrCreateDraft($site);
    $preBatchRev = (int) SiteDraft::where('site_id', $site->id)->value('admin_revision');

    app(AutoPublishCoordinator::class)->finalizeAfterBatch(
        siteId: $site->id,
        preBatchRev: $preBatchRev,
        userId: null,
        batchId: 'batch-123',
        pagesExpected: 3,
    );

    $version = SiteVersion::where('site_id', $site->id)->latest('id')->first();
    expect($version)->not->toBeNull();
    expect($version->publish_note)->toContain('Auto-publish after bulk service-page generation');
});

test('admin edit during batch skips auto-publish', function () {
    $site = seedSiteWithHome();
    app(CompositionService::class)->getOrCreateDraft($site);
    $preBatchRev = (int) (SiteDraft::where('site_id', $site->id)->value('admin_revision') ?? 0);

    // Simulate an admin edit mid-batch — bumps admin_revision
    $user = \App\Models\User::factory()->create();
    app(CompositionService::class)->bumpAdminRevision($site, userId: $user->id);
    $postBatchRev = (int) SiteDraft::where('site_id', $site->id)->value('admin_revision');
    expect($postBatchRev)->toBe($preBatchRev + 1);

    $versionsBefore = SiteVersion::where('site_id', $site->id)->count();

    app(AutoPublishCoordinator::class)->finalizeAfterBatch(
        siteId: $site->id,
        preBatchRev: $preBatchRev,
        userId: null,
        batchId: 'batch-456',
        pagesExpected: 3,
    );

    $versionsAfter = SiteVersion::where('site_id', $site->id)->count();
    expect($versionsAfter)->toBe($versionsBefore); // no new publish
});

test('missing site short-circuits without throwing', function () {
    // Unknown siteId → log + return, no exceptions
    app(AutoPublishCoordinator::class)->finalizeAfterBatch(
        siteId: 99999999,
        preBatchRev: 0,
        userId: null,
        batchId: 'batch-ghost',
        pagesExpected: 1,
    );

    expect(true)->toBeTrue(); // no exception reached
});

test('logBatchFailure logs without attempting publish', function () {
    $site = seedSiteWithHome();
    $before = SiteVersion::where('site_id', $site->id)->count();

    app(AutoPublishCoordinator::class)->logBatchFailure(
        siteId: $site->id,
        batchId: 'batch-fail',
        error: 'Job boom',
        userId: null,
    );

    $after = SiteVersion::where('site_id', $site->id)->count();
    expect($after)->toBe($before);
});

test('finalize with zero Published pages throws handled via publish_failed reason', function () {
    // Build a site with only a Draft-status home — publishSite would throw
    $site = Site::factory()->create();
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'content_data' => [],
        'sort_order' => 0,
        'version' => 1,
        'status' => PageStatus::Draft,
    ]);
    PageRevision::create([
        'page_id' => $page->id,
        'content_data' => [],
        'ai_generated' => false,
        'created_at' => now(),
    ]);

    $versionsBefore = SiteVersion::where('site_id', $site->id)->count();

    app(AutoPublishCoordinator::class)->finalizeAfterBatch(
        siteId: $site->id,
        preBatchRev: 0,
        userId: null,
        batchId: 'batch-empty',
        pagesExpected: 0,
    );

    // Coordinator catches the SitePublishException and logs it — no new version.
    expect(SiteVersion::where('site_id', $site->id)->count())->toBe($versionsBefore);
});
