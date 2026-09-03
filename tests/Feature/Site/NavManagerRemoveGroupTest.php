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

function seedSiteForGroupRemoval(): Site
{
    $site = Site::factory()->create();
    foreach (['home', 'about', 'plumbing', 'contact'] as $slug) {
        $p = GeneratedPage::create([
            'site_id' => $site->id,
            'page_type' => $slug,
            'content_data' => [],
            'sort_order' => $slug === 'home' ? 0 : 5,
            'version' => 1,
            'status' => PageStatus::Published,
        ]);
        $r = PageRevision::create(['page_id' => $p->id, 'content_data' => [], 'ai_generated' => false, 'created_at' => now()]);
        $p->update(['published_revision_id' => $r->id]);
    }
    Preview::factory()->create(['site_id' => $site->id]);

    return $site;
}

beforeEach(fn () => $this->staff = User::factory()->staff(AgentRole::Admin)->create());

test('an empty group can be removed from the nav', function () {
    // The reported bug: createGroup() makes a group with no children, and the
    // only existing removal path (removeFromGroup) deletes a group as a side
    // effect of its LAST CHILD leaving. A group that never had children could
    // therefore never be removed from the UI at all.
    $site = seedSiteForGroupRemoval();

    $component = Livewire::actingAs($this->staff)
        ->test('nav-manager', ['siteId' => $site->id])
        ->set('items', [
            ['page' => 'about', 'nav_label' => 'About Us'],
            ['page' => '_group_services', 'type' => 'group', 'nav_label' => 'Services', 'children' => []],
            ['page' => 'contact', 'nav_label' => 'Contact'],
        ])
        ->call('removeGroup', 1);

    $items = $component->get('items');

    expect($items)->toHaveCount(2)
        ->and(array_column($items, 'page'))->toBe(['about', 'contact']);
});

test('removing a group frees its children instead of deleting them', function () {
    // A group holds pages. Removing the group must not remove the pages from
    // the nav with it -- that would silently drop a page the client can no
    // longer navigate to, which is a worse bug than the one being fixed.
    $site = seedSiteForGroupRemoval();

    $component = Livewire::actingAs($this->staff)
        ->test('nav-manager', ['siteId' => $site->id])
        ->set('items', [
            ['page' => 'about', 'nav_label' => 'About Us'],
            ['page' => '_group_services', 'type' => 'group', 'nav_label' => 'Services', 'children' => [
                ['page' => 'plumbing', 'nav_label' => 'Plumbing'],
            ]],
            ['page' => 'contact', 'nav_label' => 'Contact'],
        ])
        ->call('removeGroup', 1);

    $items = $component->get('items');

    // The child lands where the group was, keeping the client's ordering.
    expect(array_column($items, 'page'))->toBe(['about', 'plumbing', 'contact']);
});

test('removeGroup refuses to touch an ordinary page item', function () {
    // Otherwise it becomes a backdoor for removing pages from the nav, which
    // is a different feature with a different confirmation story.
    $site = seedSiteForGroupRemoval();

    $component = Livewire::actingAs($this->staff)
        ->test('nav-manager', ['siteId' => $site->id])
        ->set('items', [
            ['page' => 'about', 'nav_label' => 'About Us'],
            ['page' => 'contact', 'nav_label' => 'Contact'],
        ])
        ->call('removeGroup', 0);

    expect(array_column($component->get('items'), 'page'))->toBe(['about', 'contact']);
});
