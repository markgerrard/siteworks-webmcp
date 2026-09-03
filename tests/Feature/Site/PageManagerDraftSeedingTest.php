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
 * Regression guards for the draft-seeding data-loss class: the flyout and
 * saveSection() must operate on the DRAFT revision when one exists —
 * matching PageService::currentEditableContent — not the published one.
 * Previously both read publishedRevision-first, so a second unpublished
 * edit session silently discarded the first (seed showed stale content
 * AND the new draft was rebuilt from published).
 */
function seedTwoSectionPage(): Site
{
    $site = Site::factory()->create();
    $content = ['sections' => [
        ['type' => 'intro', 'title' => 'Intro v1', 'body' => 'Intro body v1'],
        ['type' => 'cta', 'title' => 'CTA v1', 'body' => 'CTA body v1'],
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

test('edit() seeds from the pending draft, not the published revision', function () {
    $site = seedTwoSectionPage();

    $lw = Livewire::actingAs($this->staff)->test('page-manager', ['siteId' => $site->id]);

    // First edit session — saved as a draft.
    $lw->call('edit', 'home', 'intro')
        ->set('editHeading', 'Intro v2 (draft)')
        ->call('saveSection');

    // Second session before publish must show the draft's content.
    $lw->call('edit', 'home', 'intro')
        ->assertSet('editHeading', 'Intro v2 (draft)');
});

test('consecutive draft saves compound instead of overwriting each other', function () {
    $site = seedTwoSectionPage();
    $page = GeneratedPage::where('site_id', $site->id)->first();

    $lw = Livewire::actingAs($this->staff)->test('page-manager', ['siteId' => $site->id]);

    $lw->call('edit', 'home', 'intro')
        ->set('editHeading', 'Intro v2 (draft)')
        ->call('saveSection');

    $lw->call('edit', 'home', 'cta')
        ->set('editHeading', 'CTA v2 (draft)')
        ->call('saveSection');

    $page->refresh();
    $sections = PageRevision::find($page->draft_revision_id)->content_data['sections'];

    expect($sections[0]['title'])->toBe('Intro v2 (draft)')
        ->and($sections[1]['title'])->toBe('CTA v2 (draft)');
});

test('edit() still seeds from published when no draft exists', function () {
    $site = seedTwoSectionPage();

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'intro')
        ->assertSet('editHeading', 'Intro v1');
});
