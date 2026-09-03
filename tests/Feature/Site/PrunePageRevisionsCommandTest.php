<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('keeps last N revisions per page (config-driven)', function () {
    config(['site.revision_keep_count' => 3, 'site.revision_keep_days' => 0]);

    $page = GeneratedPage::factory()->create();
    foreach (range(1, 10) as $i) {
        PageRevision::factory()->for($page, 'page')->create([
            'created_at' => now()->subDays(100 - $i),
        ]);
    }

    $this->artisan('site:prune-page-revisions')->assertSuccessful();

    expect(PageRevision::where('page_id', $page->id)->count())->toBe(3);
});

test('keeps revisions within keep_days regardless of count', function () {
    config(['site.revision_keep_count' => 2, 'site.revision_keep_days' => 90]);

    $page = GeneratedPage::factory()->create();
    foreach (range(1, 10) as $i) {
        PageRevision::factory()->for($page, 'page')->create([
            'created_at' => now()->subDays($i),  // all within 10 days, all within 90
        ]);
    }

    $this->artisan('site:prune-page-revisions')->assertSuccessful();

    // All 10 within keep_days, so all survive even though keep_count is 2
    expect(PageRevision::where('page_id', $page->id)->count())->toBe(10);
});

test('never prunes a revision pointed to by published_revision_id or draft_revision_id', function () {
    config(['site.revision_keep_count' => 1, 'site.revision_keep_days' => 0]);

    $page = GeneratedPage::factory()->create();
    $oldPublished = PageRevision::factory()->for($page, 'page')->create([
        'created_at' => now()->subYear(),
    ]);
    $oldDraft = PageRevision::factory()->for($page, 'page')->create([
        'created_at' => now()->subYear()->addHour(),
    ]);
    $recent = PageRevision::factory()->for($page, 'page')->create([
        'created_at' => now(),
    ]);

    $page->update([
        'published_revision_id' => $oldPublished->id,
        'draft_revision_id' => $oldDraft->id,
    ]);

    $this->artisan('site:prune-page-revisions')->assertSuccessful();

    expect(PageRevision::find($oldPublished->id))->not->toBeNull();
    expect(PageRevision::find($oldDraft->id))->not->toBeNull();
    expect(PageRevision::find($recent->id))->not->toBeNull();
});

test('never prunes a revision pinned by a non-current site_version even if outside keep window', function () {
    config(['site.revision_keep_count' => 1, 'site.revision_keep_days' => 0]);

    $site = Site::factory()->create();
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);

    // Old revision pinned by v1 (outside keep window, NOT current page pointer).
    $v1Rev = PageRevision::factory()->for($page, 'page')->create([
        'created_at' => now()->subYear(),
    ]);

    // Newer revision that is the current published pointer.
    $currentRev = PageRevision::factory()->for($page, 'page')->create([
        'created_at' => now(),
    ]);
    $page->update(['published_revision_id' => $currentRev->id]);

    // v1 pins the old revision; it is NOT the current version.
    $v1 = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => ['homepage_page_id' => $page->id, 'nav' => ['items' => []]],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $v1Rev->id]],
        'published_at' => now()->subYear(),
    ]);

    $v2 = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 2,
        'composition' => ['homepage_page_id' => $page->id, 'nav' => ['items' => []]],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $currentRev->id]],
        'published_at' => now(),
    ]);

    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $v2->id, 'updated_at' => now()]);

    $this->artisan('site:prune-page-revisions')->assertSuccessful();

    // v1's pinned revision must survive so rollback to v1 remains possible.
    expect(PageRevision::find($v1Rev->id))->not->toBeNull();
    expect(PageRevision::find($currentRev->id))->not->toBeNull();
});

test('revision created after prune start time is not deleted even if outside keep window (H1 race guard)', function () {
    config(['site.revision_keep_count' => 1, 'site.revision_keep_days' => 0]);

    $page = GeneratedPage::factory()->create();

    // Old revision that would normally be pruned (outside keep window, not pinned).
    $oldRev = PageRevision::factory()->for($page, 'page')->create([
        'created_at' => now()->subYear(),
    ]);

    // Current published pointer (kept as "recent 1").
    $currentRev = PageRevision::factory()->for($page, 'page')->create([
        'created_at' => now()->subMinutes(5),
    ]);
    $page->update(['published_revision_id' => $currentRev->id]);

    // A "new" revision created in the future relative to prune start — simulates
    // a concurrent editField that lands just after prune begins. We back-date to
    // a future timestamp via direct DB insert so the timestamp test is deterministic.
    $futureRev = PageRevision::factory()->for($page, 'page')->create([
        'created_at' => now()->addSeconds(10),
    ]);

    $this->artisan('site:prune-page-revisions')->assertSuccessful();

    // The old revision (before prune start, not pinned, outside keep window) is pruned.
    expect(PageRevision::find($oldRev->id))->toBeNull();
    // The current published revision is retained.
    expect(PageRevision::find($currentRev->id))->not->toBeNull();
    // The future revision (created after prune started) must NOT be deleted.
    expect(PageRevision::find($futureRev->id))->not->toBeNull();
});

test('current draft and published revisions are never deleted by prune (H1 pointer safety)', function () {
    config(['site.revision_keep_count' => 1, 'site.revision_keep_days' => 0]);

    $page = GeneratedPage::factory()->create();

    // Ancient published and draft revisions — outside keep window.
    $publishedRev = PageRevision::factory()->for($page, 'page')->create([
        'created_at' => now()->subYears(2),
    ]);
    $draftRev = PageRevision::factory()->for($page, 'page')->create([
        'created_at' => now()->subYears(2)->addMinute(),
    ]);
    // A more recent one to satisfy keep_count = 1 so the above would be pruned
    // if not pinned.
    $recentRev = PageRevision::factory()->for($page, 'page')->create([
        'created_at' => now(),
    ]);

    $page->update([
        'published_revision_id' => $publishedRev->id,
        'draft_revision_id' => $draftRev->id,
    ]);

    $this->artisan('site:prune-page-revisions')->assertSuccessful();

    // Both active pointers must survive regardless of age.
    expect(PageRevision::find($publishedRev->id))->not->toBeNull();
    expect(PageRevision::find($draftRev->id))->not->toBeNull();
    // Recent revision (within keep_count = 1) also survives.
    expect(PageRevision::find($recentRev->id))->not->toBeNull();
});
