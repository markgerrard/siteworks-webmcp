<?php

use App\Enums\ProjectItemStatus;
use App\Models\Client;
use App\Models\GeneratedPage;
use App\Models\LogoConcept;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\User;
use App\Services\Site\SitePublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportComputed\CannotCallComputedDirectlyException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Second-order cross-tenant guards.
 *
 * Sibling of ProjectsEditorsCrossTenantIdorTest, which covers the DIRECT
 * IDOR on the four projects-page editors. This file covers a second-order
 * class one hop below that fix:
 *
 *  1. The LAUNDERED ID chain. reorder() scoped its UPDATE correctly but then
 *     persisted the raw client-supplied array into the page revision's
 *     section.item_ids. SitePublishService harvested those ids with no site
 *     scoping and promoted / snapshotted / reverted them — turning a read of
 *     an id into a cross-tenant WRITE, reachable by any client reordering
 *     their own gallery and clicking Publish. Both ends are closed and both
 *     ends are asserted here.
 *
 *  2. The client-portal-reachable stragglers on the same screen —
 *     projects-page-settings, project-category-manager, manual-logo-generator
 *     — whose mounts either discarded the authorization result or had none.
 */
uses(RefreshDatabase::class);

/**
 * Caller (client A) and victim (client B). Site B's category vocabulary is
 * a canary string: if it ever renders inside a component mounted by user A,
 * a cross-tenant read got through.
 *
 * @return array<string, mixed>
 */
function launderTenants(): array
{
    $clientA = Client::factory()->create();
    $clientB = Client::factory()->create();

    $userA = User::factory()->create([
        'client_id' => $clientA->id,
        'role' => null,
        'last_login_at' => now(),
    ]);

    $siteA = Site::factory()->create(['client_id' => $clientA->id, 'project_categories' => ['Residential']]);
    $siteB = Site::factory()->create(['client_id' => $clientB->id, 'project_categories' => ['VICTIMVOCAB']]);

    return compact('clientA', 'clientB', 'userA', 'siteA', 'siteB');
}

/**
 * A projects page on the given site with one gallery tile and a revision
 * pinning it, ready for publishSite().
 *
 * @return array{0: GeneratedPage, 1: ProjectItem, 2: PageRevision}
 */
function projectsPageWithPinnedTile(Site $site, string $sectionType = 'project_gallery'): array
{
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects']);

    $item = ProjectItem::factory()->for($site)->create([
        'page_id' => $page->id,
        'category' => 'Residential',
        'status' => ProjectItemStatus::Draft,
    ]);

    $revision = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => $sectionType, 'item_ids' => [$item->id]],
        ]],
    ]);

    $page->update(['draft_revision_id' => $revision->id]);

    return [$page, $item, $revision];
}

/**
 * Every ProjectItem id referenced by any revision of the given page.
 *
 * @return array<int, int>
 */
function pinnedIdsAcrossRevisions(GeneratedPage $page): array
{
    return PageRevision::where('page_id', $page->id)->get()
        ->flatMap(fn (PageRevision $r) => collect($r->content_data['sections'] ?? [])
            ->flatMap(fn ($s) => array_map('intval', $s['item_ids'] ?? [])))
        ->unique()
        ->values()
        ->all();
}

// ---------------------------------------------------------------------
// 1. Laundered id → cross-tenant write via the publish path
// ---------------------------------------------------------------------

it('does not promote another tenants project item when its id is smuggled through a gallery reorder and published', function () {
    ['userA' => $userA, 'siteA' => $siteA, 'siteB' => $siteB] = launderTenants();

    [$pageA, $itemA] = projectsPageWithPinnedTile($siteA);

    [, $itemB] = projectsPageWithPinnedTile($siteB);
    $itemB->update(['title' => 'VICTIM TILE', 'published_snapshot' => null]);

    // The attack: client A drags their own gallery; the browser posts an
    // ordered id array with tenant B's item id spliced in.
    Livewire::actingAs($userA)
        ->test('projects-gallery-editor', ['siteId' => $siteA->id, 'pageId' => $pageA->id])
        ->call('reorder', [$itemB->id, $itemA->id]);

    // The foreign id must never have been persisted into any revision.
    expect(pinnedIdsAcrossRevisions($pageA))->not->toContain($itemB->id);

    // …and even if it had been, publishing site A must not touch it.
    app(SitePublishService::class)->publishSite($siteA->fresh());

    $fresh = $itemB->fresh();
    expect($fresh->status)->toBe(ProjectItemStatus::Draft)
        ->and($fresh->published_snapshot)->toBeNull()
        ->and($fresh->title)->toBe('VICTIM TILE');
});

it('does not promote another tenants project item when its id is smuggled through a case-study reorder', function () {
    ['userA' => $userA, 'siteA' => $siteA, 'siteB' => $siteB] = launderTenants();

    [$pageA, $itemA] = projectsPageWithPinnedTile($siteA, 'case_study_highlights');
    $itemA->update(['type' => \App\Enums\ProjectItemType::CaseStudy]);

    [, $itemB] = projectsPageWithPinnedTile($siteB, 'case_study_highlights');
    $itemB->update(['type' => \App\Enums\ProjectItemType::CaseStudy, 'published_snapshot' => null]);

    Livewire::actingAs($userA)
        ->test('case-study-editor', ['siteId' => $siteA->id, 'pageId' => $pageA->id])
        ->call('reorder', [$itemB->id, $itemA->id]);

    expect(pinnedIdsAcrossRevisions($pageA))->not->toContain($itemB->id);

    app(SitePublishService::class)->publishSite($siteA->fresh());

    $fresh = $itemB->fresh();
    expect($fresh->status)->toBe(ProjectItemStatus::Draft)
        ->and($fresh->published_snapshot)->toBeNull();
});

it('publishSite ignores a foreign project item id already embedded in a pinned revision', function () {
    ['siteA' => $siteA, 'siteB' => $siteB] = launderTenants();

    [$pageA, $itemA, $revisionA] = projectsPageWithPinnedTile($siteA);
    [, $itemB] = projectsPageWithPinnedTile($siteB);
    $itemB->update(['published_snapshot' => null, 'content_hash' => str_repeat('b', 40)]);

    // Simulates a revision written before the reorder fix landed — the id is
    // already sitting in section.item_ids. SitePublishService alone must
    // refuse to act on it.
    $revisionA->update(['content_data' => ['sections' => [
        ['type' => 'project_gallery', 'item_ids' => [$itemA->id, $itemB->id]],
    ]]]);

    app(SitePublishService::class)->publishSite($siteA->fresh());

    $fresh = $itemB->fresh();
    expect($fresh->status)->toBe(ProjectItemStatus::Draft)
        ->and($fresh->published_snapshot)->toBeNull();

    // The caller's own tile still publishes normally.
    expect($itemA->fresh()->status)->toBe(ProjectItemStatus::Published)
        ->and($itemA->fresh()->published_snapshot)->not->toBeNull();

    // And the hash snapshot must not disclose the victim's hashes either.
    $section = $revisionA->fresh()->content_data['sections'][0];
    expect(array_keys($section['published_content_hashes']))->toBe([$itemA->id])
        ->and(array_keys($section['published_media_hashes']))->toBe([$itemA->id]);
});

it('discardAllDrafts does not revert another tenants project item pinned by a laundered id', function () {
    ['siteA' => $siteA, 'siteB' => $siteB] = launderTenants();

    [$pageA, $itemA, $revisionA] = projectsPageWithPinnedTile($siteA);
    [, $itemB] = projectsPageWithPinnedTile($siteB);

    $revisionA->update(['content_data' => ['sections' => [
        ['type' => 'project_gallery', 'item_ids' => [$itemA->id, $itemB->id]],
    ]]]);

    $svc = app(SitePublishService::class);
    $svc->publishSite($siteA->fresh());

    // The victim has a published baseline and has since been edited — the
    // exact state discardAllDrafts reverts from.
    $itemB->update([
        'published_snapshot' => [
            'title' => 'VICTIM ORIGINAL',
            'description' => 'victim original body',
            'category' => 'Residential',
            'metrics' => null,
            'image_id' => null,
            'sort_order' => 0,
        ],
        'title' => 'VICTIM CURRENT',
        'description' => 'victim current body',
    ]);

    $svc->discardAllDrafts($siteA->fresh());

    $fresh = $itemB->fresh();
    expect($fresh->title)->toBe('VICTIM CURRENT')
        ->and($fresh->description)->toBe('victim current body');
});

it('still reorders the callers own gallery tiles normally', function () {
    ['userA' => $userA, 'siteA' => $siteA] = launderTenants();

    $pageA = GeneratedPage::factory()->for($siteA)->create(['page_type' => 'projects']);
    $items = ProjectItem::factory()->gallery()->for($siteA)->count(3)->create(['page_id' => $pageA->id]);

    $pageA->update(['content_data' => ['sections' => [
        ['type' => 'project_gallery', 'item_ids' => [$items[0]->id, $items[1]->id, $items[2]->id]],
    ]]]);

    Livewire::actingAs($userA)
        ->test('projects-gallery-editor', ['siteId' => $siteA->id, 'pageId' => $pageA->id])
        ->call('reorder', [$items[2]->id, $items[0]->id, $items[1]->id]);

    expect(ProjectItem::find($items[2]->id)->sort_order)->toBe(0)
        ->and(ProjectItem::find($items[0]->id)->sort_order)->toBe(1)
        ->and(ProjectItem::find($items[1]->id)->sort_order)->toBe(2);

    $draft = PageRevision::find($pageA->fresh()->draft_revision_id);
    expect($draft->content_data['sections'][0]['item_ids'])
        ->toBe([$items[2]->id, $items[0]->id, $items[1]->id]);
});

// ---------------------------------------------------------------------
// 1b. Patch-introduced N+1 on project-item-card
// ---------------------------------------------------------------------

it('project-item-card resolves the authorised site once per render', function () {
    ['userA' => $userA, 'siteA' => $siteA] = launderTenants();

    [$pageA, $itemA] = projectsPageWithPinnedTile($siteA);
    $itemA->update(['page_id' => $pageA->id]);

    $siteQueries = 0;
    DB::listen(function ($query) use (&$siteQueries) {
        if (str_contains($query->sql, 'from "sites"')) {
            $siteQueries++;
        }
    });

    Livewire::actingAs($userA)->test('project-item-card', ['itemId' => $itemA->id]);

    // Post-fix: the mount authorization check plus the eager-loaded site
    // relation on the computed item. Pre-fix the view called $this->item()
    // as a METHOD (bypassing #[Computed]) and driftBadge() called it twice
    // more, so each card issued several extra findAuthorizedSite() lookups.
    expect($siteQueries)->toBeLessThanOrEqual(3);
});

// ---------------------------------------------------------------------
// 2a. projects-page-settings
// ---------------------------------------------------------------------

it('projects-page-settings refuses client updates to siteId', function () {
    ['userA' => $userA, 'siteA' => $siteA, 'siteB' => $siteB] = launderTenants();

    $settings = Livewire::actingAs($userA)->test('projects-page-settings', ['siteId' => $siteA->id]);

    expect(fn () => $settings->set('siteId', $siteB->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('projects-page-settings refuses to mount against another tenants site', function () {
    ['userA' => $userA, 'siteB' => $siteB] = launderTenants();

    Livewire::actingAs($userA)
        ->test('projects-page-settings', ['siteId' => $siteB->id])
        ->assertStatus(403);
});

it('projects-page-settings write actions re-authorise instead of fatalling', function () {
    ['userA' => $userA, 'siteA' => $siteA, 'clientB' => $clientB] = launderTenants();

    $siteA->update(['projects_page_enabled' => null, 'projects_layout' => \App\Enums\ProjectsLayout::Grid]);

    // Each action gets its own mount: a component that has already aborted
    // cannot be driven further, so reusing one instance would mask the
    // second assertion.
    $enabled = Livewire::actingAs($userA)->test('projects-page-settings', ['siteId' => $siteA->id]);
    $layout = Livewire::actingAs($userA)->test('projects-page-settings', ['siteId' => $siteA->id]);

    // Access is revoked after mount — mount-time checks alone are not enough.
    $siteA->update(['client_id' => $clientB->id]);

    $enabled->call('setProjectsPageEnabled', 'force_on')->assertStatus(403);
    expect($siteA->fresh()->projects_page_enabled)->toBeNull();

    $layout->call('setProjectsLayout', 'case_studies')->assertStatus(403);
    expect($siteA->fresh()->projects_layout)->toBe(\App\Enums\ProjectsLayout::Grid);
});

it('lets the owning client still read and change their own projects page settings', function () {
    ['userA' => $userA, 'siteA' => $siteA] = launderTenants();

    Livewire::actingAs($userA)
        ->test('projects-page-settings', ['siteId' => $siteA->id])
        ->assertStatus(200)
        ->assertSee('Residential')
        ->assertDontSee('VICTIMVOCAB')
        ->call('setProjectsPageEnabled', 'force_on');

    expect($siteA->fresh()->projects_page_enabled)->toBeTrue();
});
