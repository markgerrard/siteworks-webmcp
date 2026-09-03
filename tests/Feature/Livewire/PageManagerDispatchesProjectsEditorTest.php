<?php

use App\Enums\AgentRole;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->staff = User::factory()->staff(AgentRole::Admin)->create();
});

function seedProjectsTab(int $siteId): GeneratedPage
{
    $page = GeneratedPage::create([
        'site_id' => $siteId,
        'page_type' => 'projects',
        'content_data' => [],
        'sort_order' => 30,
        'version' => 1,
    ]);
    // page-manager tabs are built from Preview.snapshot as a fallback, but
    // $allPages reads from $site->generatedPages first — creating the GP row
    // is enough. Still, create a minimal preview for parity with other tests.
    $preview = Preview::where('site_id', $siteId)->first()
        ?? Preview::factory()->create(['site_id' => $siteId]);
    $snap = $preview->snapshot ?? [];
    $snap['pages']['projects'] = [];
    $preview->update(['snapshot' => $snap]);

    return $page;
}

it('dispatches to projects-page-editor when a projects page exists', function () {
    $site = Site::factory()->create();
    seedProjectsTab($site->id);

    // projects-page-editor lazy-loads: page-manager registers it as a
    // child (wire:key in the snapshot's children memo) and renders its
    // placeholder rather than the full component markup.
    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->assertSeeHtml('projects-editor-'.$site->id)
        ->assertDontSeeHtml('data-livewire-component="projects-page-editor"');
});

it('does NOT render projects-page-editor for sites without a projects page', function () {
    $site = Site::factory()->create();

    GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'content_data' => [],
        'sort_order' => 0,
        'version' => 1,
    ]);
    $preview = Preview::factory()->create(['site_id' => $site->id]);
    $snap = $preview->snapshot ?? [];
    $snap['pages']['home'] = [];
    $preview->update(['snapshot' => $snap]);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->assertDontSeeHtml('data-livewire-component="projects-page-editor"');
});
