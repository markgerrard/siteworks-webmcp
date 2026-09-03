<?php

use App\Enums\AgentRole;
use App\Models\BusinessProfile;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\SiteDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->staff = User::factory()->staff(AgentRole::Admin)->create());

function seedSiteForHeroSizes(array $initial = []): Site
{
    $site = Site::factory()->create();
    BusinessProfile::create(['site_id' => $site->id, 'profile_data' => $initial]);
    Preview::factory()->create(['site_id' => $site->id]);

    return $site;
}

test('hero-size-picker apply() writes to BusinessProfile.profile_data.hero_sizes (versioned renderer path)', function () {
    $site = seedSiteForHeroSizes();

    Livewire::actingAs($this->staff)
        ->test('hero-size-picker', ['siteId' => $site->id])
        ->set('homeSize', '65vh')
        ->set('innerSize', '45vh')
        ->call('apply')
        ->assertSet('saved', true)
        ->assertDispatched('composition-dirty');

    $profile = $site->businessProfile->fresh()->profile_data;
    expect($profile['hero_sizes']['home'])->toBe('65vh');
    expect($profile['hero_sizes']['inner'])->toBe('45vh');
});

test('hero-size-picker apply() mirrors to Preview.snapshot for legacy compat', function () {
    $site = seedSiteForHeroSizes();

    Livewire::actingAs($this->staff)
        ->test('hero-size-picker', ['siteId' => $site->id])
        ->set('homeSize', '25vh')
        ->call('apply');

    $preview = $site->latestPreview->fresh();
    expect($preview->snapshot['hero_sizes']['home'])->toBe('25vh');
});

test('hero-size-picker mount() prefers BusinessProfile over legacy snapshot', function () {
    $site = seedSiteForHeroSizes(['hero_sizes' => ['home' => '65vh', 'inner' => '25vh']]);
    // Legacy snapshot says something different — profile must win.
    $preview = $site->latestPreview;
    $snap = $preview->snapshot ?? [];
    $snap['hero_sizes'] = ['home' => '35vh', 'inner' => '55vh'];
    $preview->update(['snapshot' => $snap]);

    Livewire::actingAs($this->staff)
        ->test('hero-size-picker', ['siteId' => $site->id])
        ->assertSet('homeSize', '65vh')
        ->assertSet('innerSize', '25vh');
});

test('hero-size-picker apply() bumps admin_revision', function () {
    $site = seedSiteForHeroSizes();
    app(\App\Services\Site\CompositionService::class)->getOrCreateDraft($site);
    $before = (int) SiteDraft::where('site_id', $site->id)->value('admin_revision');

    Livewire::actingAs($this->staff)
        ->test('hero-size-picker', ['siteId' => $site->id])
        ->set('homeSize', '45vh')
        ->call('apply');

    $after = (int) SiteDraft::where('site_id', $site->id)->value('admin_revision');
    expect($after)->toBeGreaterThan($before);
});
