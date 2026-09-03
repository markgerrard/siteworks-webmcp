<?php

use App\Enums\AgentRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// NOTE: 'client.only' is NOT currently wrapped around /dashboard in
// routes/web.php — every route in that group is staff-facing today and
// wrapping would lock staff out. See the NOTE block in routes/web.php.
// When the staff routes migrate to the agent domain, the wrap will land
// and this test should be updated to assert the staff→agent redirect
// behaviour. Until then we only verify the middleware exists and is
// registered + the alias works (covered in EnsureClientUserTest).
test('client.only middleware is registered and can be applied to a route', function () {
    // Prove the alias resolves and behaves as specified when explicitly
    // applied (not via the main web.php group).
    \Illuminate\Support\Facades\Route::middleware(['web', 'auth', 'client.only'])
        ->get('/_test/client-only', fn () => response('ok', 200));

    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $agentLoginUrl = 'https://'.config('domains.agent_domain').'/login';

    $response = $this->actingAs($staff)->get('/_test/client-only');

    $response->assertRedirect($agentLoginUrl);
    $this->assertGuest();
});

test('client.only still redirects staff on a synthetic primary-domain route', function () {
    // When real client-facing routes land on the primary domain, they
    // get wrapped in client.only. This test proves the middleware still
    // works: a synthetic client-only route rejects a staff user's session.
    \Illuminate\Support\Facades\Route::domain(config('domains.primary_domain'))
        ->middleware(['web', 'auth', 'client.only'])
        ->get('/_test/primary-client-route', fn () => response('ok', 200));

    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $primary = config('domains.primary_domain');

    $response = $this
        ->withServerVariables(['HTTP_HOST' => $primary])
        ->actingAs($staff)
        ->get('http://'.$primary.'/_test/primary-client-route');

    $response->assertRedirect('https://'.config('domains.agent_domain').'/login');
});
