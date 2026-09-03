<?php

use App\Models\GeneratedPage;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('blocks hard-delete when item is pinned in the live SiteVersion', function () {
    $site = Site::factory()->create();
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects']);
    $item = ProjectItem::factory()->for($site)->create();

    $revision = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => [
            'sections' => [
                ['type' => 'project_gallery', 'item_ids' => [$item->id]],
            ],
        ],
    ]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $revision->id]],
        'published_at' => now(),
    ]);

    SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);

    expect(fn () => $item->delete())
        ->toThrow(\RuntimeException::class, 'pinned in a live SiteVersion');
});

it('allows hard-delete when item is NOT pinned anywhere', function () {
    $site = Site::factory()->create();
    $item = ProjectItem::factory()->for($site)->create();

    $item->delete();

    expect(ProjectItem::find($item->id))->toBeNull();
});
