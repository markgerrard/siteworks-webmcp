<?php

use App\Enums\AgentRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests hitting the staff dashboard are redirected to agent login', function () {
    // route('dashboard') now resolves to the agent subdomain; guests are
    // sent to the SSO page, not the primary-domain Fortify form.
    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('agent.login'));
});

test('authenticated staff see the dashboard', function () {
    $staff = User::factory()->staff(AgentRole::Agent)->create();

    $response = $this->actingAs($staff)->get(route('dashboard'));

    $response->assertOk();
});

test('authenticated client user is rejected from the staff dashboard', function () {
    // Dashboard is staff-only (agent.only middleware). Client users don't
    // see it even though the session could technically reach the host.
    $client = User::factory()->create(['role' => null]);

    $response = $this->actingAs($client)->get(route('dashboard'));

    $response->assertRedirect(route('agent.login'));
});
