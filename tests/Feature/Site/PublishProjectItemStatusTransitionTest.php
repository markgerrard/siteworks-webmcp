<?php

use App\Enums\ProjectItemSource;
use App\Enums\ProjectItemStatus;
use App\Models\GeneratedPage;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Services\Site\SitePublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->svc = app(SitePublishService::class));

// Sets up a site with a projects page whose draft revision pins
// 2 ProjectItems (one gallery, one case study) by id. Mirrors the
// content_data shape GenerateProjectsPageJob writes at lines 186-210.
function setupProjectsPageReadyToPublish(): array
{
    $site = Site::factory()->create();

    $projects = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects']);

    $gallery = ProjectItem::factory()->create([
        'site_id' => $site->id,
        'page_id' => $projects->id,
        'status' => ProjectItemStatus::Draft,
        'source' => ProjectItemSource::AiGenerated,
    ]);

    $caseStudy = ProjectItem::factory()->create([
        'site_id' => $site->id,
        'page_id' => $projects->id,
        'status' => ProjectItemStatus::Draft,
        'source' => ProjectItemSource::AiGenerated,
    ]);

    $rev = PageRevision::factory()->for($projects, 'page')->create([
        'content_data' => [
            'sections' => [
                ['type' => 'projects_hero', 'title' => 'Our Work'],
                ['type' => 'project_gallery', 'item_ids' => [$gallery->id]],
                ['type' => 'case_study_highlights', 'item_ids' => [$caseStudy->id]],
            ],
        ],
    ]);
    $projects->update(['draft_revision_id' => $rev->id]);

    return [$site, $projects, $gallery, $caseStudy];
}

test('publishSite flips pinned ProjectItem rows from Draft to Published', function () {
    [$site, $projects, $gallery, $caseStudy] = setupProjectsPageReadyToPublish();

    expect($gallery->refresh()->status)->toBe(ProjectItemStatus::Draft);
    expect($caseStudy->refresh()->status)->toBe(ProjectItemStatus::Draft);

    $this->svc->publishSite($site);

    expect($gallery->refresh()->status)->toBe(ProjectItemStatus::Published);
    expect($caseStudy->refresh()->status)->toBe(ProjectItemStatus::Published);
});

test('publishSite does not touch ProjectItem rows that are NOT pinned', function () {
    [$site, $projects] = setupProjectsPageReadyToPublish();

    // Item exists for the same page but is NOT referenced in the pinned
    // revision's item_ids — should stay at Draft.
    $orphan = ProjectItem::factory()->create([
        'site_id' => $site->id,
        'page_id' => $projects->id,
        'status' => ProjectItemStatus::Draft,
        'source' => ProjectItemSource::AiGenerated,
    ]);

    $this->svc->publishSite($site);

    expect($orphan->refresh()->status)->toBe(ProjectItemStatus::Draft);
});

test('regen-style deletion query no longer fatal-errors after publish (deadlock broken)', function () {
    [$site, $projects, $gallery, $caseStudy] = setupProjectsPageReadyToPublish();

    $this->svc->publishSite($site);

    // Mirror the deletion query in GenerateProjectsPageJob:143-147.
    // After publish, pinned items have status=Published, so they're
    // excluded from the WHERE status=Draft filter. The closure runs
    // with an empty result set — no observer throw. Pre-fix this would
    // have caught both items and ProjectItemObserver::deleting would
    // have thrown RuntimeException.
    expect(fn () => ProjectItem::where('site_id', $site->id)
        ->where('page_id', $projects->id)
        ->where('source', ProjectItemSource::AiGenerated->value)
        ->where('status', ProjectItemStatus::Draft->value)
        ->each(fn (ProjectItem $item) => $item->delete())
    )->not->toThrow(\RuntimeException::class);

    // Pinned items are untouched (defensive guard works even if the
    // status flip somehow didn't happen — but here it did).
    expect(ProjectItem::find($gallery->id))->not->toBeNull();
    expect(ProjectItem::find($caseStudy->id))->not->toBeNull();
});
