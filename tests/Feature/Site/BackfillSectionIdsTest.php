<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Services\Site\SitePublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

// ---- helpers ----

function makeSection(string $type, ?string $id = null): array
{
    $section = ['type' => $type, 'title' => "Title for {$type}"];
    if ($id !== null) {
        $section['id'] = $id;
    }

    return $section;
}

/**
 * Strip auto-minted ids from a revision to simulate pre-observer
 * (historical) data. Uses query builder to bypass the saving hook.
 */
function stripRevisionIds(PageRevision $revision, array $sectionsWithoutIds): void
{
    DB::table('generated_page_revisions')->where('id', $revision->id)->update([
        'content_data' => json_encode(['sections' => $sectionsWithoutIds]),
    ]);
}

function collectSectionIds(array $contentData): array
{
    $sections = $contentData['sections'] ?? [];

    return array_map(fn ($s) => is_array($s) ? ($s['id'] ?? null) : null, $sections);
}

function snapshotSiteVersions(Site $site): array
{
    return SiteVersion::where('site_id', $site->id)
        ->orderBy('id')
        ->get()
        ->map(fn ($v) => $v->getAttributes())
        ->toArray();
}
test('backfill idempotence: second run changes nothing', function () {
    $site = Site::factory()->create();

    // Page 1: revision with no ids
    $page1 = GeneratedPage::factory()->for($site)->create();
    $rev1 = PageRevision::factory()->for($page1, 'page')->create([
        'content_data' => ['noop' => true],
    ]);
    // Strip the auto-minted ids to simulate pre-observer historical data.
    stripRevisionIds($rev1, [
        makeSection('hero'),
        makeSection('services'),
    ]);
    $page1->update(['draft_revision_id' => $rev1->id]);

    // Page 2: revision with all ids already present
    // Also set page's content_data to match so the mirror assertion works.
    $p2Data = ['sections' => [
        makeSection('hero', 'ID-A'),
        makeSection('services', 'ID-B'),
    ]];
    $page2 = GeneratedPage::factory()->for($site)->create([
        'content_data' => $p2Data,
    ]);
    $rev2 = PageRevision::factory()->for($page2, 'page')->create([
        'content_data' => $p2Data,
    ]);
    $page2->update(['draft_revision_id' => $rev2->id]);

    // Page 3: revision partially id'd
    $page3 = GeneratedPage::factory()->for($site)->create();
    $rev3 = PageRevision::factory()->for($page3, 'page')->create([
        'content_data' => ['noop' => true],
    ]);
    stripRevisionIds($rev3, [
        makeSection('hero', 'ID-C'),
        makeSection('cta'),
    ]);
    $page3->update(['draft_revision_id' => $rev3->id]);

    // First run
    $this->artisan('site:backfill-section-ids')->assertSuccessful();

    // Capture first-run results
    $r1a = $rev1->fresh()->content_data;
    $r2a = $rev2->fresh()->content_data;
    $r3a = $rev3->fresh()->content_data;
    $p1a = $page1->fresh()->content_data;
    $p2a = $page2->fresh()->content_data;
    $p3a = $page3->fresh()->content_data;
    $up1 = $page1->fresh()->updated_at;
    $up2 = $page2->fresh()->updated_at;
    $up3 = $page3->fresh()->updated_at;

    // After-first-run assertions
    $r1ids = collectSectionIds($r1a);
    expect($r1ids)->toHaveCount(2);
    expect($r1ids[0])->toBeString()->toHaveLength(26);
    expect($r1ids[1])->toBeString()->toHaveLength(26);
    expect(collectSectionIds($p1a))->toEqual($r1ids);

    expect(collectSectionIds($r2a))->toBe(['ID-A', 'ID-B']);
    expect(collectSectionIds($p2a))->toBe(['ID-A', 'ID-B']);

    $r3ids = collectSectionIds($r3a);
    expect($r3ids)->toHaveCount(2);
    expect($r3ids[0])->toBe('ID-C');
    expect($r3ids[1])->toBeString()->toHaveLength(26);

    // Second run
    $this->artisan('site:backfill-section-ids')->assertSuccessful();

    // Every row === identical to first run
    expect($rev1->fresh()->content_data)->toEqual($r1a);
    expect($rev2->fresh()->content_data)->toEqual($r2a);
    expect($rev3->fresh()->content_data)->toEqual($r3a);
    expect($page1->fresh()->content_data)->toEqual($p1a);
    expect($page2->fresh()->content_data)->toEqual($p2a);
    expect($page3->fresh()->content_data)->toEqual($p3a);

    // updated_at unmoved on second run
    expect($page1->fresh()->updated_at->format('Y-m-d H:i:s'))
        ->toBe($up1->format('Y-m-d H:i:s'));
    expect($page2->fresh()->updated_at->format('Y-m-d H:i:s'))
        ->toBe($up2->format('Y-m-d H:i:s'));
    expect($page3->fresh()->updated_at->format('Y-m-d H:i:s'))
        ->toBe($up3->format('Y-m-d H:i:s'));

    // Catches: re-mints every run; inexact skip churns updated_at.
});
test('the mirror is written: generated_pages matches current pointer revision', function () {
    $site = Site::factory()->create();

    $page = GeneratedPage::factory()->for($site)->create();
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['noop' => true],
    ]);
    stripRevisionIds($rev, [
        makeSection('hero'),
        makeSection('services', 'KEPT'),
    ]);
    $page->update(['draft_revision_id' => $rev->id]);

    $this->artisan('site:backfill-section-ids')->assertSuccessful();

    $revIds = collectSectionIds($rev->fresh()->content_data);
    $pageIds = collectSectionIds($page->fresh()->content_data);

    expect($pageIds)->toEqual($revIds);
    expect($revIds[0])->toBeString()->toHaveLength(26);
    expect($revIds[1])->toBe('KEPT');

    // Catches: writes revisions but not the mirror.
});

test('an edit that advances the pointer after the snapshot does not revert the mirror to R1', function () {
    $site = Site::factory()->create();
    $page = GeneratedPage::factory()->for($site)->create();
    $rev1 = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['noop' => true],
    ]);
    stripRevisionIds($rev1, [makeSection('hero')]);
    $page->update(['draft_revision_id' => $rev1->id]);

    $r2Data = ['sections' => [makeSection('services', 'R2-ID')]];
    $rev2 = null;

    PageRevision::updated(function (PageRevision $saved) use ($page, $r2Data, $rev1, &$rev2): void {
        if ($rev2 !== null || (int) $saved->id !== (int) $rev1->id) {
            return;
        }

        $rev2 = PageRevision::factory()->for($page, 'page')->create([
            'content_data' => $r2Data,
        ]);
        $page->update([
            'draft_revision_id' => $rev2->id,
            'content_data' => $r2Data,
        ]);
    });

    try {
        $this->artisan('site:backfill-section-ids')->assertSuccessful();
    } finally {
        Event::forget('eloquent.updated: '.PageRevision::class);
    }

    expect($rev2)->not->toBeNull();
    expect($page->fresh()->draft_revision_id)->toBe($rev2->id);
    expect(collectSectionIds($page->fresh()->content_data))->toBe(['R2-ID']);
    expect($page->fresh()->content_data['sections'][0]['type'])->toBe('services');
    expect(collectSectionIds($rev1->fresh()->content_data)[0])->toBeString()->toHaveLength(26);

    // Catches: mirroring from a pointer map built before the write.
});

test('the already-done row is not written at all', function () {
    $site = Site::factory()->create();

    $page = GeneratedPage::factory()->for($site)->create();
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            makeSection('hero', 'ID-X'),
            makeSection('services', 'ID-Y'),
        ]],
    ]);
    $page->update(['draft_revision_id' => $rev->id]);

    $beforeUpdatedAt = $page->fresh()->updated_at;

    $this->artisan('site:backfill-section-ids')
        ->expectsOutputToContain('skipped')
        ->assertSuccessful();

    expect(collectSectionIds($rev->fresh()->content_data))->toBe(['ID-X', 'ID-Y']);

    expect($page->fresh()->updated_at->format('Y-m-d H:i:s'))
        ->toBe($beforeUpdatedAt->format('Y-m-d H:i:s'));

    // Catches: re-writes every row instead of skipping already-done ones.
});

test('historical revisions are covered, not just current pointers', function () {
    $site = Site::factory()->create();
    $page = GeneratedPage::factory()->for($site)->create();

    // Old historical revision (NOT current pointer)
    $rev1 = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['noop' => true],
    ]);
    stripRevisionIds($rev1, [makeSection('hero')]);

    // Current pointer revision
    $rev2 = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['noop' => true],
    ]);
    stripRevisionIds($rev2, [makeSection('services')]);
    $page->update(['draft_revision_id' => $rev2->id]);

    $this->artisan('site:backfill-section-ids')->assertSuccessful();

    $rev1Ids = collectSectionIds($rev1->fresh()->content_data);
    $rev2Ids = collectSectionIds($rev2->fresh()->content_data);

    expect($rev1Ids[0])->toBeString()->toHaveLength(26);
    expect($rev2Ids[0])->toBeString()->toHaveLength(26);

    // Catches: only walking current pointers.
});
test('published SiteVersions are byte-identical after backfill', function () {
    $site = Site::factory()->create();

    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            makeSection('hero'),
            makeSection('services'),
        ]],
    ]);
    stripRevisionIds($rev, [
        makeSection('hero'),
        makeSection('services'),
    ]);
    $page->update(['draft_revision_id' => $rev->id]);

    // Publish to create a site_versions row
    $svc = app(SitePublishService::class);
    $svc->publishSite($site);

    $before = snapshotSiteVersions($site);
    expect($before)->not->toBeEmpty();

    $this->artisan('site:backfill-section-ids')->assertSuccessful();

    $after = snapshotSiteVersions($site);

    // Row count unchanged
    expect(count($after))->toBe(count($before));

    // Every column of every row byte-equal
    expect($after)->toEqual($before);

    // Catches: a backfill that mutates a published
    // version in place, breaking history and rollback.
});

test('--dry-run writes nothing', function () {
    $site = Site::factory()->create();

    $page = GeneratedPage::factory()->for($site)->create();
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['noop' => true],
    ]);
    stripRevisionIds($rev, [
        makeSection('hero'),
        makeSection('services'),
    ]);
    $page->update(['draft_revision_id' => $rev->id]);

    $revBefore = $rev->fresh()->content_data;
    $pageBefore = $page->fresh()->content_data;

    $this->artisan('site:backfill-section-ids', ['--dry-run' => true])
        ->assertSuccessful();

    expect($rev->fresh()->content_data)->toEqual($revBefore);
    expect($page->fresh()->content_data)->toEqual($pageBefore);
});

test('--site scopes to that site only', function () {
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();

    $pageA = GeneratedPage::factory()->for($siteA)->create();
    $revA = PageRevision::factory()->for($pageA, 'page')->create([
        'content_data' => ['noop' => true],
    ]);
    stripRevisionIds($revA, [makeSection('hero')]);
    $pageA->update(['draft_revision_id' => $revA->id]);

    $pageB = GeneratedPage::factory()->for($siteB)->create();
    $revB = PageRevision::factory()->for($pageB, 'page')->create([
        'content_data' => ['noop' => true],
    ]);
    stripRevisionIds($revB, [makeSection('hero')]);
    $pageB->update(['draft_revision_id' => $revB->id]);

    $this->artisan('site:backfill-section-ids', ['--site' => $siteA->id])
        ->assertSuccessful();

    expect(collectSectionIds($revA->fresh()->content_data)[0])
        ->toBeString()->toHaveLength(26);
    expect(collectSectionIds($revB->fresh()->content_data)[0])
        ->toBeNull();

    // Catches: global-only implementation ignoring --site.
});