<?php

use App\Exceptions\Site\PageStateException;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\SitePublishService;
use Illuminate\Support\Facades\DB;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => $this->svc = app(SitePublishService::class));

/**
 * Build a site with one page, one revision, and a published version pointing at it.
 */
function makeVersionedSite(): array
{
    $site = Site::factory()->create();
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'v1 content']]],
    ]);
    $page->update(['published_revision_id' => $rev->id, 'content_data' => $rev->content_data]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => ['homepage_page_id' => $page->id, 'nav' => ['items' => []]],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);

    SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);

    return [$site, $page, $rev, $version];
}

test('rollback flips current pointer and mirrors content_data', function () {
    [$site, $page, $rev1, $v1] = makeVersionedSite();

    // Simulate a second publish with a different revision
    $rev2 = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'v2 content']]],
    ]);
    $page->update(['published_revision_id' => $rev2->id, 'content_data' => $rev2->content_data]);

    $v2 = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 2,
        'composition' => ['homepage_page_id' => $page->id, 'nav' => ['items' => []]],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev2->id]],
        'published_at' => now(),
    ]);

    SiteVersionCurrent::where('site_id', $site->id)->update(['version_id' => $v2->id]);

    // Roll back to v1
    $this->svc->rollbackToVersion($site, $v1);

    // Current pointer should now point at v1
    $current = SiteVersionCurrent::where('site_id', $site->id)->first();
    expect($current->version_id)->toBe($v1->id);

    // Page's published_revision_id should be rev1, content_data mirrored.
    // Reload $rev1: jsonb reorders keys (id first) and toBe is order-sensitive.
    $page->refresh();
    $rev1->refresh();
    expect($page->published_revision_id)->toBe($rev1->id);
    expect($page->content_data)->toBe($rev1->content_data);
});

test('rollback to already-current version is a no-op', function () {
    [$site, $page, $rev, $v1] = makeVersionedSite();

    $queryCount = 0;
    DB::listen(function () use (&$queryCount) {
        $queryCount++;
    });

    $this->svc->rollbackToVersion($site, $v1);

    // Current pointer still v1
    $current = SiteVersionCurrent::where('site_id', $site->id)->first();
    expect($current->version_id)->toBe($v1->id);

    // The page's published_revision_id should be unchanged
    $page->refresh();
    expect($page->published_revision_id)->toBe($rev->id);
});

test('rollback to version with pruned revision throws PageStateException and makes no writes', function () {
    [$site, $page, $rev1, $v1] = makeVersionedSite();

    // Simulate a second publish.
    $rev2 = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'v2 content']]],
    ]);
    $page->update(['published_revision_id' => $rev2->id, 'content_data' => $rev2->content_data]);

    $v2 = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 2,
        'composition' => ['homepage_page_id' => $page->id, 'nav' => ['items' => []]],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev2->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::where('site_id', $site->id)->update(['version_id' => $v2->id]);

    // Simulate pruning: hard-delete v1's revision directly (bypass the command).
    $rev1->delete();

    expect(fn () => $this->svc->rollbackToVersion($site, $v1))
        ->toThrow(PageStateException::class, "revision {$rev1->id}");

    // Current pointer must remain on v2 — no writes should have occurred.
    $current = SiteVersionCurrent::where('site_id', $site->id)->first();
    expect($current->version_id)->toBe($v2->id);

    // Page's published_revision_id must still be rev2.
    $page->refresh();
    expect($page->published_revision_id)->toBe($rev2->id);
});

test('rollback with cross-site version throws PageStateException', function () {
    [$site] = makeVersionedSite();
    $otherSite = Site::factory()->create();

    $otherPage = GeneratedPage::factory()->for($otherSite)->create(['page_type' => 'home']);
    $otherRev = PageRevision::factory()->for($otherPage, 'page')->create();
    $otherPage->update(['published_revision_id' => $otherRev->id]);

    $otherVersion = SiteVersion::create([
        'site_id' => $otherSite->id,
        'version' => 1,
        'composition' => [],
        'page_revisions' => [['page_id' => $otherPage->id, 'revision_id' => $otherRev->id]],
        'published_at' => now(),
    ]);

    expect(fn () => $this->svc->rollbackToVersion($site, $otherVersion))
        ->toThrow(PageStateException::class);
});
