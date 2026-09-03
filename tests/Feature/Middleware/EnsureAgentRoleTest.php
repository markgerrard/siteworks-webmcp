<?php

use App\Enums\AgentRole;
use App\Http\Middleware\EnsureAgentRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * Register a temporary route protected by agent.only for testing purposes.
 */
beforeEach(function () {
    Route::middleware(['web', 'agent.only'])
        ->get('/_test/agent-only', fn () => response('ok', 200))
        ->name('_test.agent-only');
});

test('client user (null role) hitting an agent.only route is redirected to agent.login with error', function () {
    $client = User::factory()->create([
        'role' => null,
    ]);

    $response = $this->actingAs($client)->get('/_test/agent-only');

    $response->assertRedirect(route('agent.login'));
    $response->assertSessionHasErrors('auth');
    $this->assertGuest();
});

test('unauthenticated request to agent.only route is redirected to agent.login with error', function () {
    $response = $this->get('/_test/agent-only');

    $response->assertRedirect(route('agent.login'));
    $response->assertSessionHasErrors('auth');
});

test('soft-deleted staff user hitting agent.only route is logged out and redirected with revoked error', function () {
    $staff = User::factory()->staff(AgentRole::Agent)->create();

    // Soft-delete the user after initial auth
    $staff->delete();

    $response = $this->actingAs($staff)->get('/_test/agent-only');

    $response->assertRedirect(route('agent.login'));
    $response->assertSessionHasErrors('auth');
    $this->assertGuest();
});

test('valid staff user passes through agent.only middleware', function () {
    $staff = User::factory()->staff(AgentRole::Agent)->create();

    $response = $this->actingAs($staff)->get('/_test/agent-only');

    $response->assertOk();
    $response->assertSee('ok');
});
