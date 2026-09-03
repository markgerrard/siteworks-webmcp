<?php

use App\Models\GeneratedPage;
use App\Models\Site\PageRevision;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('creates a revision for each page lacking pointers and sets published_revision_id', function () {
    // Simulate legacy pages: rows with content_data but no pointers
    $pageA = GeneratedPage::factory()->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'A']]],
    ]);
    $pageB = GeneratedPage::factory()->create([
        'content_data' => ['sections' => [['type' => 'about', 'body' => 'B']]],
    ]);

    // already-migrated page should be skipped
    $pageC = GeneratedPage::factory()->create();
    $existingRevC = PageRevision::factory()->for($pageC, 'page')->create();
    $pageC->update(['published_revision_id' => $existingRevC->id]);

    $this->artisan('site:backfill-initial-page-revisions')->assertSuccessful();

    $pageA->refresh();
    $pageB->refresh();
    $pageC->refresh();

    expect($pageA->published_revision_id)->not->toBeNull();
    expect($pageA->publishedRevision->content_data['sections'][0]['title'])->toBe('A');

    expect($pageB->published_revision_id)->not->toBeNull();

    // C unchanged (already migrated)
    expect($pageC->published_revision_id)->toBe($existingRevC->id);
});

test('idempotent — re-running does not create duplicate revisions', function () {
    $page = GeneratedPage::factory()->create([
        'content_data' => ['sections' => []],
    ]);

    $this->artisan('site:backfill-initial-page-revisions');
    $countAfterFirst = PageRevision::where('page_id', $page->id)->count();

    $this->artisan('site:backfill-initial-page-revisions');
    $countAfterSecond = PageRevision::where('page_id', $page->id)->count();

    expect($countAfterFirst)->toBe(1);
    expect($countAfterSecond)->toBe(1);
});
