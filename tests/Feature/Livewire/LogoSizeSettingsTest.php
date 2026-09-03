<?php

use App\Enums\AgentRole;
use App\Enums\LogoSize;
use App\Models\Client;
use App\Models\LogoConcept;
use App\Models\Site;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('agent can switch the logo size to large', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('logo-size-settings', ['siteId' => $site->id])
        ->call('setLogoSize', 'large');

    expect($site->fresh()->logo_size)->toBe(LogoSize::Large);
});

it('switching the logo size invalidates the public page cache', function () {
    config(['site.public_cache_enabled' => true]);
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    $counterKey = "site:{$site->id}:pubcache_counter";
    $before = (int) cache()->get($counterKey, 0);

    Livewire::actingAs($agent)
        ->test('logo-size-settings', ['siteId' => $site->id])
        ->call('setLogoSize', 'large');

    expect((int) cache()->get($counterKey, 0))->toBeGreaterThan($before);
});

/**
 * mount() uses assertAuthorizedSiteAccess() (fail-closed) so unauthorized
 * users never reach the blade that previously Site::find()'d the real site.
 * Livewire surfaces abort as a 403 response status — assertStatus(403)
 * matches project Livewire idiom (AssertAuthorizedSiteAccessTest).
 */
it('a user without access to the site cannot mount or change its logo size', function () {
    $owner = User::factory()->staff(AgentRole::Agent)->create();
    $outsider = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $owner->id]);

    Livewire::actingAs($outsider)
        ->test('logo-size-settings', ['siteId' => $site->id])
        ->assertStatus(403);

    expect($site->fresh()->logo_size)->toBe(LogoSize::Standard);
});

it('agent can set and clear the overlay logo size', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $overlay = LogoConcept::factory()->for($site)->create();
    $site->update(['overlay_logo_concept_id' => $overlay->id]);

    Livewire::actingAs($agent)
        ->test('logo-size-settings', ['siteId' => $site->id])
        ->call('setOverlayLogoSize', 'large');

    expect($site->fresh()->overlay_logo_size)->toBe(LogoSize::Large);

    Livewire::actingAs($agent)
        ->test('logo-size-settings', ['siteId' => $site->id])
        ->call('setOverlayLogoSize', null);

    expect($site->fresh()->overlay_logo_size)->toBeNull();
});

it('switching the overlay logo size invalidates the public page cache', function () {
    config(['site.public_cache_enabled' => true]);
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $overlay = LogoConcept::factory()->for($site)->create();
    $site->update(['overlay_logo_concept_id' => $overlay->id]);

    $counterKey = "site:{$site->id}:pubcache_counter";
    $before = (int) cache()->get($counterKey, 0);

    Livewire::actingAs($agent)
        ->test('logo-size-settings', ['siteId' => $site->id])
        ->call('setOverlayLogoSize', 'compact');

    expect((int) cache()->get($counterKey, 0))->toBeGreaterThan($before);
});

it('a user without access to the site cannot change its overlay logo size', function () {
    $owner = User::factory()->staff(AgentRole::Agent)->create();
    $outsider = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $owner->id]);
    $overlay = LogoConcept::factory()->for($site)->create();
    $site->update(['overlay_logo_concept_id' => $overlay->id, 'overlay_logo_size' => LogoSize::Large]);

    Livewire::actingAs($outsider)
        ->test('logo-size-settings', ['siteId' => $site->id])
        ->assertStatus(403);

    expect($site->fresh()->overlay_logo_size)->toBe(LogoSize::Large);
});

it('a client of another site is denied on setOverlayLogoSize', function () {
    $ownerClient = Client::factory()->create();
    $otherClient = Client::factory()->create();
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create([
        'client_id' => $ownerClient->id,
        'created_by_user_id' => $agent->id,
        'overlay_logo_size' => LogoSize::Compact,
    ]);
    $overlay = LogoConcept::factory()->for($site)->create();
    $site->update(['overlay_logo_concept_id' => $overlay->id]);
    $outsider = User::factory()->create([
        'role' => null,
        'client_id' => $otherClient->id,
    ]);

    $component = Livewire::actingAs($agent)
        ->test('logo-size-settings', ['siteId' => $site->id]);

    $this->actingAs($outsider);

    $component->call('setOverlayLogoSize', 'large')->assertStatus(403);

    expect($site->fresh()->overlay_logo_size)->toBe(LogoSize::Compact);
});

it('a client of this site can persist overlay logo size', function () {
    $client = Client::factory()->create();
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create([
        'client_id' => $client->id,
        'created_by_user_id' => $agent->id,
    ]);
    $overlay = LogoConcept::factory()->for($site)->create();
    $site->update(['overlay_logo_concept_id' => $overlay->id]);
    $clientUser = User::factory()->create([
        'role' => null,
        'client_id' => $client->id,
    ]);

    Livewire::actingAs($clientUser)
        ->test('logo-size-settings', ['siteId' => $site->id])
        ->call('setOverlayLogoSize', 'large')
        ->assertOk();

    expect($site->fresh()->overlay_logo_size)->toBe(LogoSize::Large);
});

it('shows a hint instead of the floating size group when no overlay logo is set', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('logo-size-settings', ['siteId' => $site->id])
        ->assertSee('Set a logo to use on the overlay header first')
        ->assertDontSee('Floating logo size')
        ->assertDontSee('Same as logo');
});

it('renders the floating logo size group when an overlay logo is set', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $overlay = LogoConcept::factory()->for($site)->create();
    $site->update(['overlay_logo_concept_id' => $overlay->id]);

    Livewire::actingAs($agent)
        ->test('logo-size-settings', ['siteId' => $site->id])
        ->assertSee('Floating logo size')
        ->assertSee('Same as logo')
        ->assertDontSee('Set a logo to use on the overlay header first');
});

it('agent can set, clamp and clear the floating logo margin', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id, 'logo_margin' => 5]);

    Livewire::actingAs($agent)->test('logo-size-settings', ['siteId' => $site->id])->call('setOverlayLogoMargin', 3);
    expect($site->fresh()->overlay_logo_margin)->toBe(3);

    Livewire::actingAs($agent)->test('logo-size-settings', ['siteId' => $site->id])->call('setOverlayLogoMargin', 40);
    expect($site->fresh()->overlay_logo_margin)->toBe(12);

    Livewire::actingAs($agent)->test('logo-size-settings', ['siteId' => $site->id])->call('setOverlayLogoMargin', null);
    expect($site->fresh()->overlay_logo_margin)->toBeNull();
});
