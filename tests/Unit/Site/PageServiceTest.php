<?php

use App\Models\GeneratedPage;
use App\Models\Site\PageRevision;
use App\Services\Site\PageService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => $this->svc = app(PageService::class));

test('first edit clones published into a new draft revision', function () {
    $page = GeneratedPage::factory()->create();
    $published = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Original']]],
    ]);
    $page->update(['published_revision_id' => $published->id]);

    $newRevision = $this->svc->editField($page, 'sections.0.title', 'Updated');

    $page->refresh();
    expect($page->draft_revision_id)->toBe($newRevision->id);
    expect($newRevision->id)->not->toBe($published->id);
    expect($newRevision->content_data['sections'][0]['title'])->toBe('Updated');

    // published revision is unchanged (immutable)
    expect($published->fresh()->content_data['sections'][0]['title'])->toBe('Original');
});

test('subsequent edit creates new revision and advances draft pointer', function () {
    $page = GeneratedPage::factory()->create();
    $published = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Original']]],
    ]);
    $page->update(['published_revision_id' => $published->id]);

    $first = $this->svc->editField($page, 'sections.0.title', 'Edit one');
    $second = $this->svc->editField($page->fresh(), 'sections.0.title', 'Edit two');

    $page->refresh();
    expect($page->draft_revision_id)->toBe($second->id);
    expect($second->content_data['sections'][0]['title'])->toBe('Edit two');
    // First edit's revision still exists immutable
    expect($first->fresh()->content_data['sections'][0]['title'])->toBe('Edit one');
    // 2 draft revisions plus 1 published = 3 total
    expect(PageRevision::where('page_id', $page->id)->count())->toBe(3);
});

test('editing page with no published revision works (creates first revision)', function () {
    $page = GeneratedPage::factory()->create();

    $rev = $this->svc->editField($page, 'sections.0.title', 'Brand new');

    $page->refresh();
    expect($page->draft_revision_id)->toBe($rev->id);
    expect($page->published_revision_id)->toBeNull();
});

test('editField with nested path applies correctly', function () {
    $page = GeneratedPage::factory()->create();
    $published = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Hero'],
            ['type' => 'services', 'items' => [
                ['title' => 'Service A', 'body' => 'A'],
                ['title' => 'Service B', 'body' => 'B'],
            ]],
        ]],
    ]);
    $page->update(['published_revision_id' => $published->id]);

    $rev = $this->svc->editField($page, 'sections.1.items.1.body', 'Updated body');

    expect($rev->content_data['sections'][1]['items'][1]['body'])->toBe('Updated body');
    // Other items unchanged
    expect($rev->content_data['sections'][1]['items'][0]['body'])->toBe('A');
});

test('replaceContent replaces full content_data in a new revision', function () {
    $page = GeneratedPage::factory()->create();

    $rev = $this->svc->replaceContent(
        $page,
        ['sections' => [['type' => 'hero', 'title' => 'Whole new content']]],
        aiGenerated: true,
        aiModelVersion: 'demo-model',
    );

    $page->refresh();
    expect($page->draft_revision_id)->toBe($rev->id);
    expect($rev->ai_generated)->toBeTrue();
    expect($rev->ai_model_version)->toBe('demo-model');
});

use App\Exceptions\Site\PageStateException;
use App\Exceptions\Site\StaleRevisionException;

test('editField throws StaleRevisionException when expected base does not match', function () {
    $page = GeneratedPage::factory()->create();
    $published = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Original']]],
    ]);
    $page->update(['published_revision_id' => $published->id]);

    // First edit advances draft pointer
    $draft = $this->svc->editField($page, 'sections.0.title', 'First');

    // Second edit with stale base (pointing at original published, not the new draft)
    expect(fn () => $this->svc->editField(
        $page->fresh(),
        'sections.0.title',
        'Second',
        expectedBaseRevisionId: $published->id,
    ))->toThrow(StaleRevisionException::class);

    // Draft pointer unchanged from the successful first edit
    expect($page->fresh()->draft_revision_id)->toBe($draft->id);
});

test('editField succeeds when expected base matches current draft', function () {
    $page = GeneratedPage::factory()->create();
    $published = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Original']]],
    ]);
    $page->update(['published_revision_id' => $published->id]);

    $first = $this->svc->editField($page, 'sections.0.title', 'First', expectedBaseRevisionId: $published->id);
    $second = $this->svc->editField($page->fresh(), 'sections.0.title', 'Second', expectedBaseRevisionId: $first->id);

    expect($second->content_data['sections'][0]['title'])->toBe('Second');
});

test('publish flips draft to published and clears draft pointer', function () {
    $page = GeneratedPage::factory()->create();
    $published = PageRevision::factory()->for($page, 'page')->create();
    $draft = PageRevision::factory()->for($page, 'page')->create();
    $page->update(['published_revision_id' => $published->id, 'draft_revision_id' => $draft->id]);

    $this->svc->publishPage($page);

    $page->refresh();
    expect($page->published_revision_id)->toBe($draft->id);
    expect($page->draft_revision_id)->toBeNull();

    // Both revision rows still exist
    expect(PageRevision::find($published->id))->not->toBeNull();
    expect(PageRevision::find($draft->id))->not->toBeNull();
});

test('publishPage no-op if no draft', function () {
    $page = GeneratedPage::factory()->create();
    $published = PageRevision::factory()->for($page, 'page')->create();
    $page->update(['published_revision_id' => $published->id]);

    $this->svc->publishPage($page);

    expect($page->fresh()->published_revision_id)->toBe($published->id);
});

test('discardDraft clears draft pointer (revision row persists for retention prune)', function () {
    $page = GeneratedPage::factory()->create();
    $published = PageRevision::factory()->for($page, 'page')->create();
    $draft = PageRevision::factory()->for($page, 'page')->create();
    $page->update(['published_revision_id' => $published->id, 'draft_revision_id' => $draft->id]);

    $this->svc->discardDraft($page);

    expect($page->fresh()->draft_revision_id)->toBeNull();
    // Revision still in table (retention cron will eventually prune)
    expect(PageRevision::find($draft->id))->not->toBeNull();
});

test('rollbackToRevision sets published pointer to a prior revision', function () {
    $page = GeneratedPage::factory()->create();
    $r1 = PageRevision::factory()->for($page, 'page')->create();
    $r2 = PageRevision::factory()->for($page, 'page')->create();
    $page->update(['published_revision_id' => $r2->id]);

    $this->svc->rollbackToRevision($page, $r1);

    expect($page->fresh()->published_revision_id)->toBe($r1->id);
});

test('rollbackToRevision throws when revision belongs to another page', function () {
    $page = GeneratedPage::factory()->create();
    $other = GeneratedPage::factory()->create();
    $strangerRev = PageRevision::factory()->for($other, 'page')->create();

    expect(fn () => $this->svc->rollbackToRevision($page, $strangerRev))
        ->toThrow(PageStateException::class);
});

test('rollbackToRevision succeeds and sets published pointer without throwing (H3 transaction wrap)', function () {
    $page = GeneratedPage::factory()->create();
    $r1 = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'v1']]],
    ]);
    $r2 = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'v2']]],
    ]);
    $page->update(['published_revision_id' => $r2->id]);

    // Should not throw; should atomically flip the pointer.
    $this->svc->rollbackToRevision($page, $r1);

    expect($page->fresh()->published_revision_id)->toBe($r1->id);
    // content_data legacy mirror also updated
    expect($page->fresh()->content_data['sections'][0]['title'])->toBe('v1');
});

test('archive sets archived_at and unsets pointers do NOT change', function () {
    $page = GeneratedPage::factory()->create();
    $r = PageRevision::factory()->for($page, 'page')->create();
    $page->update(['published_revision_id' => $r->id]);

    $this->svc->archivePage($page);

    $page->refresh();
    expect($page->archived_at)->not->toBeNull();
    // pointers preserved so unarchive is symmetric
    expect($page->published_revision_id)->toBe($r->id);
});

test('unarchive clears archived_at', function () {
    $page = GeneratedPage::factory()->create(['archived_at' => now()]);
    $this->svc->unarchivePage($page);
    expect($page->fresh()->archived_at)->toBeNull();
});

test('GenerateContentJob writes via PageService creates a revision per page', function () {
    // simplified scenario — manual call rather than running full pipeline
    $page = GeneratedPage::factory()->create();
    app(PageService::class)->replaceContent($page, [
        'sections' => [['type' => 'hero', 'title' => 'AI gen']],
    ], aiGenerated: true, aiModelVersion: 'demo-model');
    app(PageService::class)->publishPage($page);

    $page->refresh();
    expect($page->draft_revision_id)->toBeNull();
    expect($page->published_revision_id)->not->toBeNull();
    expect($page->publishedRevision->ai_generated)->toBeTrue();
    expect($page->publishedRevision->ai_model_version)->toBe('demo-model');
});
