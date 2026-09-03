<?php

use App\Exceptions\Site\StaleRevisionException;
use App\Exceptions\Site\StaleStructureException;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Services\Site\PageService;

function seedStructurePage(): GeneratedPage
{
    $site = Site::factory()->create();
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create(['content_data' => ['sections' => [['type' => 'hero', 'title' => 'A'], ['type' => 'cta', 'title' => 'B']]]]);
    $page->update(['published_revision_id' => $rev->id]);

    return $page->fresh();
}

it('mutates sections under lock, bumps structure_epoch and advances the draft', function () {
    $page = seedStructurePage();
    $base = $page->published_revision_id;
    $rev = app(PageService::class)->mutateSections($page, $base, 0, fn (array $s) => array_reverse($s));
    $page->refresh();
    expect($page->draft_revision_id)->toBe($rev->id)
        ->and($page->structure_epoch)->toBe(1)
        ->and($rev->content_data['sections'][0]['type'])->toBe('cta')
        ->and($page->published_revision_id)->toBe($base);
});

it('throws StaleStructureException on an old epoch', function () {
    $page = seedStructurePage();
    app(PageService::class)->mutateSections($page, $page->published_revision_id, 0, fn ($s) => $s);
    $page->refresh();
    app(PageService::class)->mutateSections($page, $page->draft_revision_id, 0, fn ($s) => $s);
})->throws(StaleStructureException::class);

it('checks the base on repeatable writes', function () {
    $page = seedStructurePage();
    app(PageService::class)->editRepeatableEntries($page, 0, 'items', [], null, 999_999);
})->throws(StaleRevisionException::class);

it('checks the structure epoch on field writes under the page lock', function () {
    $page = seedStructurePage();
    app(PageService::class)->editField($page, 'sections.0.title', 'X', null, $page->published_revision_id, expectedStructureEpoch: 7);
})->throws(StaleStructureException::class);
