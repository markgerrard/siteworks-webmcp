<?php

use App\Enums\AgentRole;
use App\Enums\LeadFormPolicy;
use App\Models\BusinessProfile;
use App\Models\Preview;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->staff = User::factory()->staff(AgentRole::Admin)->create();
});

function setupToggleSite(array $profileOverrides = []): Site
{
    $site = Site::factory()->create();
    BusinessProfile::factory()->for($site)->create([
        'profile_data' => $profileOverrides,
    ]);
    Preview::factory()->create(['site_id' => $site->id]);

    return $site;
}

test('mount reads topBarEnabled from profile_data defaulting to true', function () {
    $site = setupToggleSite();

    Livewire::actingAs($this->staff)
        ->test('preview-toggles', ['siteId' => $site->id])
        ->assertSet('topBarEnabled', true);
});

test('mount reads leadFormPolicy from profile_data', function () {
    $site = setupToggleSite(['lead_form_policy' => 'off']);

    Livewire::actingAs($this->staff)
        ->test('preview-toggles', ['siteId' => $site->id])
        ->assertSet('leadFormPolicy', 'off');
});

test('mount falls back to archetype default when lead_form_policy absent', function () {
    // local_service archetype defaults to home_services
    $site = setupToggleSite(['archetype' => 'local_service']);

    Livewire::actingAs($this->staff)
        ->test('preview-toggles', ['siteId' => $site->id])
        ->assertSet('leadFormPolicy', LeadFormPolicy::HomeServices->value);
});

test('updateLeadFormPolicy persists to profile_data and fires composition-dirty', function () {
    $site = setupToggleSite(['lead_form_policy' => 'home']);

    Livewire::actingAs($this->staff)
        ->test('preview-toggles', ['siteId' => $site->id])
        ->call('updateLeadFormPolicy', 'off')
        ->assertSet('leadFormPolicy', 'off')
        ->assertDispatched('composition-dirty');

    $profile = $site->fresh()->businessProfile->profile_data;
    expect($profile['lead_form_policy'])->toBe('off');
});

test('updateLeadFormPolicy accepts all four valid values', function () {
    $site = setupToggleSite();

    foreach (['off', 'home', 'home_services', 'all'] as $value) {
        Livewire::actingAs($this->staff)
            ->test('preview-toggles', ['siteId' => $site->id])
            ->call('updateLeadFormPolicy', $value)
            ->assertSet('leadFormPolicy', $value);
    }
});

test('toggleTopBar persists and flips state', function () {
    $site = setupToggleSite(['top_bar_enabled' => true]);

    Livewire::actingAs($this->staff)
        ->test('preview-toggles', ['siteId' => $site->id])
        ->call('toggleTopBar')
        ->assertSet('topBarEnabled', false)
        ->assertDispatched('composition-dirty');

    $profile = $site->fresh()->businessProfile->profile_data;
    expect($profile['top_bar_enabled'])->toBeFalse();
});

test('toggleTopBar remains unaffected by leadFormPolicy', function () {
    $site = setupToggleSite(['top_bar_enabled' => true, 'lead_form_policy' => 'off']);

    Livewire::actingAs($this->staff)
        ->test('preview-toggles', ['siteId' => $site->id])
        ->call('toggleTopBar')
        ->assertSet('topBarEnabled', false)
        ->assertSet('leadFormPolicy', 'off');
});
