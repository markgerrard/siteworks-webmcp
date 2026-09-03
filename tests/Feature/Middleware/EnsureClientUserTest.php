<?php

use App\Enums\AgentRole;
use App\Http\Middleware\EnsureClientUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * Register a temporary route protected by client.only for isolated middleware testing.
 * In production, client.only wraps client-facing portal routes.
 */
beforeEach(function () {
    Route::middleware(['web', 'auth', 'client.only'])
        ->get('/_test/client-only', fn () => response('ok', 200))
        ->name('_test.client-only');
});

test('staff user visiting a client-only route is logged out and redirected to agent domain login', function () {
    $staff = User::factory()->staff(AgentRole::Agent)->create();

    $response = $this->actingAs($staff)->get('/_test/client-only');

    $agentLoginUrl = 'https://'.config('domains.agent_domain').'/login';

    $response->assertRedirect($agentLoginUrl);
    $this->assertGuest();
});

test('client user visiting a client-only route is allowed through', function () {
    $client = User::factory()->create([
        'role' => null,
    ]);

    $response = $this->actingAs($client)->get('/_test/client-only');

    // Not redirected away — client users pass through.
    $response->assertOk();
    $response->assertSee('ok');
    $this->assertAuthenticated();
});
