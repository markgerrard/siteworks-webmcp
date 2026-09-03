<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function seedSiteWithPagesForNav(): Site
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

test('nav-manager save() mirrors translated nav items into composition.nav.items', function () {
    $site = seedSiteWithPagesForNav();
    $aboutId = GeneratedPage::where('site_id', $site->id)->where('page_type', 'about')->value('id');
    $plumbingId = GeneratedPage::where('site_id', $site->id)->where('page_type', 'plumbing')->value('id');

    Livewire::actingAs($this->staff)
        ->test('nav-manager', ['siteId' => $site->id])
        ->set('enabled', true)
        ->set('items', [
            ['page' => 'about', 'nav_label' => 'About Us', 'footer_label' => 'About Us'],
            ['page' => 'plumbing', 'nav_label' => 'Plumbing Services', 'footer_label' => 'Plumbing'],
        ])
        ->call('save')
        ->assertDispatched('composition-dirty');

    $items = SiteDraft::where('site_id', $site->id)->first()->composition['nav']['items'];

    expect($items)->toHaveCount(2);
    expect($items[0])->toMatchArray([
        'type' => 'page',
        'label' => 'About Us',
        'page_id' => (int) $aboutId,
    ]);
    expect($items[1])->toMatchArray([
        'type' => 'page',
        'label' => 'Plumbing Services',
        'page_id' => (int) $plumbingId,
    ]);
});

test('nav-manager save() bumps admin_revision so auto-publish respects admin intent', function () {
    $site = seedSiteWithPagesForNav();
    app(\App\Services\Site\CompositionService::class)->getOrCreateDraft($site);
    $before = (int) SiteDraft::where('site_id', $site->id)->value('admin_revision');

    Livewire::actingAs($this->staff)
        ->test('nav-manager', ['siteId' => $site->id])
        ->set('enabled', true)
        ->set('items', [['page' => 'about', 'nav_label' => 'About']])
        ->call('save');

    $after = (int) SiteDraft::where('site_id', $site->id)->value('admin_revision');
    expect($after)->toBe($before + 1);
});

test('nav-manager save() translates groups with children into composition shape', function () {
    $site = seedSiteWithPagesForNav();
    $plumbingId = GeneratedPage::where('site_id', $site->id)->where('page_type', 'plumbing')->value('id');

    Livewire::actingAs($this->staff)
        ->test('nav-manager', ['siteId' => $site->id])
        ->set('enabled', true)
        ->set('items', [[
            'type' => 'group',
            'nav_label' => 'Services',
            'children' => [
                ['page' => 'plumbing', 'nav_label' => 'Plumbing'],
            ],
        ]])
        ->call('save');

    $items = SiteDraft::where('site_id', $site->id)->first()->composition['nav']['items'];
    expect($items)->toHaveCount(1);
    expect($items[0]['type'])->toBe('group');
    expect($items[0]['label'])->toBe('Services');
    expect($items[0]['children'])->toHaveCount(1);
    expect($items[0]['children'][0])->toMatchArray([
        'type' => 'page',
        'label' => 'Plumbing',
        'page_id' => (int) $plumbingId,
    ]);
});

test('nav-manager toggle off resets composition.nav.items to system defaults', function () {
    // Regression: toggling custom nav off used to leave previously-saved
    // custom items sitting in composition, so the public preview kept
    // rendering them. Disable must reset to the default (all pages).
    $site = seedSiteWithPagesForNav();
    $cs = app(\App\Services\Site\CompositionService::class);
    $draft = $cs->getOrCreateDraft($site);

    // Seed composition with a single custom item so we can verify the reset.
    $cs->updateNav(
        $draft,
        [[
            'type' => 'page',
            'label' => 'Solo',
            'page_id' => GeneratedPage::where('site_id', $site->id)->where('page_type', 'about')->value('id'),
        ]],
        \App\Enums\MutationSource::Admin,
        $this->staff->id,
    );

    Livewire::actingAs($this->staff)
        ->test('nav-manager', ['siteId' => $site->id])
        ->set('enabled', true) // current state
        ->call('toggle');       // flips to disabled → should reset

    $items = SiteDraft::where('site_id', $site->id)->first()->composition['nav']['items'];
    // Defaults include all non-home pages (about, plumbing, contact)
    expect(count($items))->toBeGreaterThan(1);
    $labels = collect($items)->pluck('label')->all();
    expect($labels)->toContain('About', 'Contact');
});

test('nav-manager toggle() also bumps admin_revision + dispatches composition-dirty', function () {
    // Regression: toggle() used to only call saveToSnapshot, so disabling
    // the custom nav was invisible to the banner + auto-publish guard.
    $site = seedSiteWithPagesForNav();
    app(\App\Services\Site\CompositionService::class)->getOrCreateDraft($site);
    $before = (int) SiteDraft::where('site_id', $site->id)->value('admin_revision');

    Livewire::actingAs($this->staff)
        ->test('nav-manager', ['siteId' => $site->id])
        ->set('enabled', true)
        ->call('toggle')
        ->assertDispatched('composition-dirty');

    $after = (int) SiteDraft::where('site_id', $site->id)->value('admin_revision');
    expect($after)->toBe($before + 1);
});

test('nav-manager save() drops nav items referencing pages that no longer exist', function () {
    $site = seedSiteWithPagesForNav();

    Livewire::actingAs($this->staff)
        ->test('nav-manager', ['siteId' => $site->id])
        ->set('enabled', true)
        ->set('items', [
            ['page' => 'about', 'nav_label' => 'About'],
            ['page' => 'ghost-page', 'nav_label' => 'Ghost'], // no such page
        ])
        ->call('save');

    $items = SiteDraft::where('site_id', $site->id)->first()->composition['nav']['items'];
    expect($items)->toHaveCount(1);
    expect($items[0]['label'])->toBe('About');
});
