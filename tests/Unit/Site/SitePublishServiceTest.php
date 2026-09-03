<?php

use App\Exceptions\Site\PageStateException;
use App\Exceptions\Site\SitePublishException;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\CompositionService;
use App\Services\Site\PageService;
use App\Services\Site\SitePublishService;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => $this->svc = app(SitePublishService::class));

function setupSiteWithDrafts(): array
{
    $site = Site::factory()->create();

    $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $publishedRev = PageRevision::factory()->for($home, 'page')->create();
    $home->update(['published_revision_id' => $publishedRev->id]);

    $about = GeneratedPage::factory()->for($site)->create(['page_type' => 'about']);
    $aboutPub = PageRevision::factory()->for($about, 'page')->create();
    $about->update(['published_revision_id' => $aboutPub->id]);

    // home has a pending draft, about doesn't
    app(PageService::class)->editField($home, 'sections.0.title', 'Updated home title');

    // composition draft exists
    app(CompositionService::class)->getOrCreateDraft($site);

    return [$site, $home, $about];
}

test('publish creates new site_version with version=1, pins page revisions, advances pointers', function () {
    [$site, $home, $about] = setupSiteWithDrafts();

    $version = $this->svc->publishSite($site, publishNote: 'first publish');

    expect($version->version)->toBe(1);
    expect($version->publish_note)->toBe('first publish');

    // home draft was pinned, then promoted to published
    $home->refresh();
    expect($home->draft_revision_id)->toBeNull();
    $homeRev = collect($version->page_revisions)->firstWhere('page_id', $home->id);
    expect($homeRev['revision_id'])->toBe($home->published_revision_id);

    // about (no draft) still pinned to its existing published
    $aboutRev = collect($version->page_revisions)->firstWhere('page_id', $about->id);
    expect($aboutRev['revision_id'])->toBe($about->published_revision_id);

    // current pointer
    $current = SiteVersionCurrent::where('site_id', $site->id)->first();
    expect($current->version_id)->toBe($version->id);
});

test('publish increments version monotonically per site', function () {
    [$site] = setupSiteWithDrafts();
    $v1 = $this->svc->publishSite($site);

    // make another change
    app(PageService::class)->editField($site->generatedPages->first(), 'sections.0.title', 'Edit two');
    $v2 = $this->svc->publishSite($site);

    expect($v2->version)->toBe(2);
});

test('publish throws when site has no pages', function () {
    $site = Site::factory()->create();
    app(CompositionService::class)->getOrCreateDraft($site);

    expect(fn () => $this->svc->publishSite($site))
        ->toThrow(SitePublishException::class);
});

test('rollbackToVersion flips current pointer to a prior version', function () {
    [$site] = setupSiteWithDrafts();
    $v1 = $this->svc->publishSite($site);

    app(PageService::class)->editField($site->generatedPages->first(), 'sections.0.title', 'Edit two');
    $v2 = $this->svc->publishSite($site);

    expect(SiteVersionCurrent::where('site_id', $site->id)->value('version_id'))->toBe($v2->id);

    $this->svc->rollbackToVersion($site, $v1);

    expect(SiteVersionCurrent::where('site_id', $site->id)->value('version_id'))->toBe($v1->id);
});

test('discardAllDrafts is safe on a site with page drafts but no SiteDraft row', function () {
    // First-publish path: a site can have generated pages with draft revisions
    // without ever having a SiteDraft row created. discardAllDrafts must lock
    // SOMETHING (the parent sites row) to serialise — the SiteDraft lockForUpdate
    // is a no-op when the row doesn't exist.
    $site = Site::factory()->create();
    $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $publishedRev = PageRevision::factory()->for($home, 'page')->create();
    $home->update(['published_revision_id' => $publishedRev->id]);

    // Add a page draft, but DO NOT call getOrCreateDraft — leaves the
    // site_drafts row absent.
    app(PageService::class)->editField($home, 'sections.0.title', 'Pending edit');
    expect($home->fresh()->draft_revision_id)->not->toBeNull();
    expect(SiteDraft::where('site_id', $site->id)->exists())->toBeFalse();

    // Should not throw, and should clear the page draft.
    $this->svc->discardAllDrafts($site);

    expect($home->fresh()->draft_revision_id)->toBeNull();
});

test('discardAllDrafts clears every page draft + resets composition to current published', function () {
    [$site, $home, $about] = setupSiteWithDrafts();

    // ensure a published exists
    $v1 = $this->svc->publishSite($site);

    // make new pending changes
    app(PageService::class)->editField($home, 'sections.0.title', 'Newer edit');
    app(CompositionService::class)->updateNav(
        SiteDraft::where('site_id', $site->id)->first(),
        [['type' => 'shop', 'label' => 'Shop']],
        \App\Enums\MutationSource::Admin,
    );

    expect($home->fresh()->draft_revision_id)->not->toBeNull();
    expect(app(CompositionService::class)->hasPendingComposition($site))->toBeTrue();

    $this->svc->discardAllDrafts($site);

    expect($home->fresh()->draft_revision_id)->toBeNull();
    expect(app(CompositionService::class)->hasPendingComposition($site))->toBeFalse();
});

test('serialised concurrent publishes do not produce duplicate version numbers', function () {
    [$site] = setupSiteWithDrafts();

    // Publish twice in quick succession (sequential; tests row-lock semantics, not real concurrency)
    $v1 = $this->svc->publishSite($site);
    app(\App\Services\Site\PageService::class)->editField($site->generatedPages->first(), 'sections.0.title', 'Edit B');
    $v2 = $this->svc->publishSite($site);

    expect([$v1->version, $v2->version])->toBe([1, 2]);
});

test('rollbackToVersion throws when target version belongs to another site', function () {
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();

    $pageA = GeneratedPage::factory()->for($siteA)->create(['page_type' => 'home']);
    $revA = PageRevision::factory()->for($pageA, 'page')->create();
    $pageA->update(['published_revision_id' => $revA->id]);

    $pageB = GeneratedPage::factory()->for($siteB)->create(['page_type' => 'home']);
    $revB = PageRevision::factory()->for($pageB, 'page')->create();
    $pageB->update(['published_revision_id' => $revB->id]);

    app(CompositionService::class)->getOrCreateDraft($siteA);
    app(CompositionService::class)->getOrCreateDraft($siteB);

    $vA = $this->svc->publishSite($siteA);
    $vB = $this->svc->publishSite($siteB);

    expect(fn () => $this->svc->rollbackToVersion($siteA, $vB))
        ->toThrow(PageStateException::class);
});

test('publishSummary returns pending pages + composition pending flag', function () {
    [$site, $home, $about] = setupSiteWithDrafts();

    $summary = $this->svc->publishSummary($site);

    expect($summary['pending_pages'])->toHaveCount(1);
    expect($summary['pending_pages'][0]['page_id'])->toBe($home->id);
    expect($summary['composition_pending'])->toBeTrue();
    expect($summary['next_version'])->toBe(1);
});
