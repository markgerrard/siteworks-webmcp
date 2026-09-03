<?php

use App\Enums\AgentRole;
use App\Enums\PageKind;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('mounts the layout picker on the projects tab settings pill', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    GeneratedPage::factory()->for($site)->create([
        'page_type' => 'projects',
        'kind' => PageKind::Core,
        'nav_label' => 'Projects',
        'status' => PageStatus::Published,
    ]);

    Livewire::actingAs($agent)->test('page-manager', ['siteId' => $site->id])
        ->set('activeTab', 'projects')
        ->assertSeeHtml('page-layout-override');
});

it('does not mount the picker for an archived projects page', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    GeneratedPage::factory()->for($site)->create([
        'page_type' => 'projects',
        'kind' => PageKind::Core,
        'nav_label' => 'Projects',
        'status' => PageStatus::Archived,
        'archived_at' => now(),
    ]);

    Livewire::actingAs($agent)->test('page-manager', ['siteId' => $site->id])
        ->set('activeTab', 'projects')
        ->assertDontSeeHtml('page-layout-override');
});
