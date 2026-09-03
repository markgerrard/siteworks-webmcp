<?php

use App\Enums\AgentRole;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Regression guards for the site editor's lazy-loading behaviour.
 * Former tabs are now separate section pages; page-manager still
 * lazy-loads, and per-item project cards must render as lazy
 * placeholders so they do not trip livewire.payload.max_components.
 */
beforeEach(function () {
    config()->set('site.use_versioned_renderer', true);

    $this->agent = User::factory()->staff(AgentRole::Agent)->create();
    $this->site = Site::factory()->create([
        'created_by_user_id' => $this->agent->id,
        'preview_domain' => 'lazy-loading-spec',
        'preview_brand' => 'a',
    ]);
    GeneratedPage::factory()->for($this->site)->create(['page_type' => 'home']);
    Preview::factory()->for($this->site)->create(['slug' => 'lazy-loading-spec']);
});

test('site show no longer mounts every former tab on one page', function () {
    $this->actingAs($this->agent)
        ->get(route('sites.show', $this->site))
        ->assertOk()
        ->assertDontSeeLivewire('page-manager')
        ->assertDontSeeLivewire('design-panel')
        ->assertDontSeeLivewire('site.watermark-toggle');
});

test('pages section renders page-manager as a lazy placeholder', function () {
    $response = $this->actingAs($this->agent)
        ->get(route('sites.section', ['site' => $this->site, 'section' => 'pages']))
        ->assertOk()
        ->assertSeeLivewire('page-manager');

    expect($response->getContent())->toContain('__lazyLoad');
});

test('page query param on the site URL redirects 301 to the pages section', function () {
    $this->actingAs($this->agent)
        ->get(route('sites.show', ['site' => $this->site, 'page' => 'about']))
        ->assertRedirect(route('sites.section', [
            'site' => $this->site,
            'section' => 'pages',
            'page' => 'about',
        ]))
        ->assertStatus(301);
});

test('gallery items render as lazy placeholders that keep their sortable identity', function () {
    $page = GeneratedPage::factory()->for($this->site)->create(['page_type' => 'projects']);
    $items = ProjectItem::factory()->gallery()->for($this->site)->count(3)->create([
        'page_id' => $page->id,
    ]);

    $component = Livewire::actingAs($this->agent)
        ->test('projects-gallery-editor', ['siteId' => $this->site->id, 'pageId' => $page->id]);

    // Placeholders must carry data-item-id so SortableJS reorder still
    // sees every card in DOM order — a partial orderedIds list would
    // rewrite the revision's item_ids without the unmounted cards.
    foreach ($items as $item) {
        $component->assertSee('data-item-id="'.$item->id.'"', false);
    }

    // The card's full markup must not be eagerly rendered.
    $component->assertDontSee('data-livewire-component="project-item-card"', false);
});

test('case study items render as lazy placeholders that keep their sortable identity', function () {
    $page = GeneratedPage::factory()->for($this->site)->create(['page_type' => 'projects']);
    $items = ProjectItem::factory()->caseStudy()->for($this->site)->count(2)->create([
        'page_id' => $page->id,
    ]);

    $component = Livewire::actingAs($this->agent)
        ->test('case-study-editor', ['siteId' => $this->site->id, 'pageId' => $page->id]);

    foreach ($items as $item) {
        $component->assertSee('data-item-id="'.$item->id.'"', false);
    }

    $component->assertDontSee('data-livewire-component="project-item-card"', false);
});

test('a project item card itself still renders its content when mounted', function () {
    $page = GeneratedPage::factory()->for($this->site)->create(['page_type' => 'projects']);
    $item = ProjectItem::factory()->gallery()->for($this->site)->create([
        'page_id' => $page->id,
        'title' => 'Loft conversion in Truro',
    ]);

    // Title renders via wire:model (snapshot, not server HTML), so assert
    // mount hydration + the full-render root marker the placeholder lacks.
    Livewire::actingAs($this->agent)
        ->test('project-item-card', ['itemId' => $item->id])
        ->assertSet('title', 'Loft conversion in Truro')
        ->assertSee('data-livewire-component="project-item-card"', false);
});
