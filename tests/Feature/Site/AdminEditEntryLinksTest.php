<?php

use App\Enums\AgentRole;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('site show edit button links to site editor shell', function () {
    config()->set('site.use_versioned_renderer', true);

    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $user->id,
        'preview_domain' => 'admin-edit-entry',
        'preview_brand' => 'a',
    ]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    Preview::factory()->for($site)->create(['slug' => 'admin-edit-entry']);

    $expected = route('site.editor-shell', ['site' => $site->id, 'page' => $page->id]);
    $legacy = route('site.admin.open-live-editor', ['site' => $site->id, 'page' => $page->id]);

    $this->actingAs($user)
        ->get(route('sites.show', $site))
        ->assertOk()
        ->assertSee($expected, false)
        ->assertDontSee($legacy, false);
});

test('page manager edit button links to site editor shell', function () {
    config()->set('site.use_versioned_renderer', true);

    $user = User::factory()->staff(AgentRole::Admin)->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $user->id,
        'preview_domain' => 'page-manager-edit-entry',
        'preview_brand' => 'a',
    ]);
    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Home']]],
        'nav_label' => 'Home',
    ]);
    Preview::factory()->for($site)->create([
        'slug' => 'page-manager-edit-entry',
        'snapshot' => [
            'pages' => [
                'home' => ['sections' => [['type' => 'hero', 'title' => 'Home']]],
            ],
        ],
    ]);

    $expected = route('site.editor-shell', ['site' => $site->id, 'page' => $page->id]);
    $legacy = route('site.admin.open-live-editor', ['site' => $site->id, 'page' => $page->id]);

    Livewire::actingAs($user)
        ->test('page-manager', ['siteId' => $site->id])
        ->assertSee($expected, false)
        ->assertDontSee($legacy, false);
});
