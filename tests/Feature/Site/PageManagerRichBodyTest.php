<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->staff = User::factory()->staff(AgentRole::Admin)->create());

/**
 * WYSIWYG body editing in the page-manager flyout: body is a TipTap doc
 * end-to-end (editBodyDoc), never demoted to a plain string on save.
 */
function seedSiteWithBody(mixed $body): Site
{
    $site = Site::factory()->create();
    $content = ['sections' => [
        ['type' => 'intro', 'title' => 'Intro title', 'body' => $body],
    ]];
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'content_data' => $content,
        'sort_order' => 0,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    $rev = PageRevision::create([
        'page_id' => $page->id,
        'content_data' => $content,
        'ai_generated' => false,
        'created_at' => now(),
    ]);
    $page->update(['published_revision_id' => $rev->id]);
    Preview::factory()->create(['site_id' => $site->id]);

    return $site;
}

function boldDoc(string $plain, string $bolded): array
{
    return ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => $plain],
            ['type' => 'text', 'text' => $bolded, 'marks' => [['type' => 'bold']]],
        ]],
    ]];
}

test('edit() seeds editBodyDoc from a structured doc body', function () {
    $doc = boldDoc('Safety ', 'first');
    $site = seedSiteWithBody($doc);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'intro')
        ->assertSet('editBodyDoc', $doc);
});

test('edit() lifts a legacy plain-string body into a doc for the editor', function () {
    $site = seedSiteWithBody("First para.\n\nSecond para.");

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'intro')
        ->assertSet('editBodyDoc', [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'First para.']]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Second para.']]],
            ],
        ]);
});

test('saveSection() writes the edited doc back with marks intact — no string demotion', function () {
    $site = seedSiteWithBody(boldDoc('Original ', 'bold'));
    $page = GeneratedPage::where('site_id', $site->id)->first();

    $edited = boldDoc('Rewritten intro ', 'still bold');

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'intro')
        ->set('editBodyDoc', $edited)
        ->call('saveSection');

    $page->refresh();
    $saved = PageRevision::find($page->draft_revision_id)->content_data['sections'][0]['body'];

    expect($saved)->toEqual($edited)
        ->and($saved['content'][0]['content'][1]['marks'][0]['type'])->toBe('bold');
});

test('saveSection() upgrades a legacy string body to a doc', function () {
    $site = seedSiteWithBody('Plain old body.');
    $page = GeneratedPage::where('site_id', $site->id)->first();

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'intro')
        ->set('editBodyDoc', ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Rewritten body.']]],
        ]])
        ->call('saveSection');

    $page->refresh();
    $saved = PageRevision::find($page->draft_revision_id)->content_data['sections'][0]['body'];

    expect($saved)->toBeArray()
        ->and($saved['type'])->toBe('doc')
        ->and($saved['content'][0]['content'][0]['text'])->toBe('Rewritten body.');
});

test('saveSection() ignores editBodyDoc for sections without a body key', function () {
    $site = Site::factory()->create();
    $content = ['sections' => [['type' => 'hero', 'title' => 'No body here']]];
    $page = GeneratedPage::create([
        'site_id' => $site->id, 'page_type' => 'home', 'content_data' => $content,
        'sort_order' => 0, 'version' => 1, 'status' => PageStatus::Published,
    ]);
    $rev = PageRevision::create([
        'page_id' => $page->id, 'content_data' => $content, 'ai_generated' => false, 'created_at' => now(),
    ]);
    $page->update(['published_revision_id' => $rev->id]);
    Preview::factory()->create(['site_id' => $site->id]);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'hero')
        ->set('editHeading', 'Updated')
        ->call('saveSection');

    $page->refresh();
    $section = PageRevision::find($page->draft_revision_id)->content_data['sections'][0];
    expect($section)->not->toHaveKey('body')
        ->and($section['title'])->toBe('Updated');
});

test('saveSection() rejects a malformed editBodyDoc shape', function () {
    $site = seedSiteWithBody('Plain.');
    $page = GeneratedPage::where('site_id', $site->id)->first();

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'intro')
        ->set('editBodyDoc', ['type' => 'not-a-doc', 'content' => 'garbage'])
        ->call('saveSection');

    $page->refresh();
    $saved = PageRevision::find($page->draft_revision_id)->content_data['sections'][0]['body'];

    // Malformed doc must not be persisted — original body survives.
    expect($saved)->toBe('Plain.');
});
