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

test('publishSite snapshots per-item content_hash + media_hash into pinned revision sections', function () {
    $site = Site::factory()->create();
    $projects = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects']);

    $a = ProjectItem::factory()->create([
        'site_id' => $site->id,
        'page_id' => $projects->id,
        'status' => ProjectItemStatus::Draft,
        'source' => ProjectItemSource::AiGenerated,
    ]);
    $b = ProjectItem::factory()->create([
        'site_id' => $site->id,
        'page_id' => $projects->id,
        'status' => ProjectItemStatus::Draft,
        'source' => ProjectItemSource::AiGenerated,
    ]);

    $rev = PageRevision::factory()->for($projects, 'page')->create([
        'content_data' => [
            'sections' => [
                ['type' => 'projects_hero', 'title' => 'Our Work'],
                [
                    'type' => 'project_gallery',
                    'item_ids' => [$a->id, $b->id],
                    'published_content_hashes' => [],
                    'published_media_hashes' => [],
                ],
            ],
        ],
    ]);
    $projects->update(['draft_revision_id' => $rev->id]);

    $this->svc->publishSite($site);

    // The same row was mutated in place — re-read the pinned revision.
    $pinnedRev = PageRevision::find($rev->id);
    $gallerySection = collect($pinnedRev->content_data['sections'])
        ->firstWhere('type', 'project_gallery');

    expect($gallerySection['published_content_hashes'])->toHaveKey($a->id);
    expect($gallerySection['published_content_hashes'])->toHaveKey($b->id);
    expect($gallerySection['published_content_hashes'][$a->id])->toBe($a->fresh()->content_hash);
    expect($gallerySection['published_content_hashes'][$b->id])->toBe($b->fresh()->content_hash);

    expect($gallerySection['published_media_hashes'])->toHaveKey($a->id);
    expect($gallerySection['published_media_hashes'][$a->id])->toBe($a->fresh()->media_hash);
});

test('publishSite regenerates brand images so the favicon tracks the published palette', function () {
    \Illuminate\Support\Facades\Bus::fake([\App\Jobs\GenerateBrandImagesJob::class]);

    $site = Site::factory()->create();
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Hi']]],
    ]);
    $page->update(['draft_revision_id' => $rev->id]);

    $this->svc->publishSite($site);

    \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\GenerateBrandImagesJob::class);
});
