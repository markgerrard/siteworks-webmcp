<?php

use App\Models\GeneratedPage;
use App\Models\Site\PageRevision;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('revision belongs to page and persists content_data jsonb', function () {
    $page = GeneratedPage::factory()->create();
    $revision = PageRevision::create([
        'page_id' => $page->id,
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'X']]],
        'ai_generated' => true,
        'ai_model_version' => 'demo-model',
        'created_at' => now(),
    ]);

    expect($revision->page->id)->toBe($page->id);
    expect($revision->content_data['sections'][0]['title'])->toBe('X');
    expect($revision->ai_generated)->toBeTrue();
});

test('soft-deleting a page keeps its revisions', function () {
    // GeneratedPage uses SoftDeletes, so delete() only sets deleted_at. The
    // row survives, the DB-level cascadeOnDelete() never fires, and the
    // revisions must remain so a restore() brings back a complete page.
    $page = GeneratedPage::factory()->create();
    PageRevision::factory()->for($page, 'page')->count(3)->create();

    expect(PageRevision::count())->toBe(3);
    $page->delete();
    expect(PageRevision::count())->toBe(3);
});

test('cascade-delete revisions when page is force-deleted', function () {
    $page = GeneratedPage::factory()->create();
    PageRevision::factory()->for($page, 'page')->count(3)->create();

    expect(PageRevision::count())->toBe(3);
    $page->forceDelete();
    expect(PageRevision::count())->toBe(0);
});

test('revisions ordered by created_at', function () {
    $page = GeneratedPage::factory()->create();
    PageRevision::factory()->for($page, 'page')->create(['created_at' => now()->subHour()]);
    PageRevision::factory()->for($page, 'page')->create(['created_at' => now()]);

    $latest = PageRevision::where('page_id', $page->id)->orderByDesc('created_at')->first();
    expect($latest->created_at->isToday())->toBeTrue();
});

test('page can have draft and published revision pointers', function () {
    $page = GeneratedPage::factory()->create();
    $rev1 = PageRevision::factory()->for($page, 'page')->create();
    $rev2 = PageRevision::factory()->for($page, 'page')->create();

    $page->update([
        'published_revision_id' => $rev1->id,
        'draft_revision_id' => $rev2->id,
    ]);

    expect($page->publishedRevision->id)->toBe($rev1->id);
    expect($page->draftRevision->id)->toBe($rev2->id);
    expect($page->revisions)->toHaveCount(2);
});

test('archived_at column accepts timestamps', function () {
    $page = GeneratedPage::factory()->create(['archived_at' => now()]);
    expect($page->archived_at)->not->toBeNull();
});
