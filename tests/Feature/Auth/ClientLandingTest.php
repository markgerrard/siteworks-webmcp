<?php

use App\Enums\AgentRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('client login redirects to /account on the customer domain, not /dashboard', function () {
    $user = User::factory()->create(['role' => null]);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect('https://'.config('domains.customer_domain').'/account');
    $this->assertAuthenticated();
});

test('staff login via Fortify lands on the agent-domain dashboard', function () {
    // Staff-by-password is still permitted (see FortifyServiceProvider note).
    // The LoginResponse should send them to the agent domain, not the
    // customer one, so they hit the correct dashboard route.
    $staff = User::factory()->staff(AgentRole::Agent)->create(['password' => bcrypt('secret-password')]);

    $response = $this->post(route('login.store'), [
        'email' => $staff->email,
        'password' => 'secret-password',
    ]);

    $response->assertRedirect('https://'.config('domains.agent_domain').'/dashboard');
    $this->assertAuthenticated();
});

test('authenticated client GET / on customer domain redirects to /account', function () {
    $customer = config('domains.customer_domain');
    $user = User::factory()->create(['role' => null]);

    $response = $this->withServerVariables(['HTTP_HOST' => $customer])
        ->actingAs($user)
        ->get('http://'.$customer.'/');

    $response->assertRedirect('https://'.$customer.'/account');
});

test('authenticated staff GET / on customer domain redirects to agent dashboard', function () {
    $customer = config('domains.customer_domain');
    $staff = User::factory()->staff(AgentRole::Agent)->create();

    $response = $this->withServerVariables(['HTTP_HOST' => $customer])
        ->actingAs($staff)
        ->get('http://'.$customer.'/');

    $response->assertRedirect('https://'.config('domains.agent_domain').'/dashboard');
});

test('client GET /account returns 200', function () {
    $customer = config('domains.customer_domain');
    $user = User::factory()->create(['role' => null]);

    $response = $this->withServerVariables(['HTTP_HOST' => $customer])
        ->actingAs($user)
        ->get('http://'.$customer.'/account');

    $response->assertOk();
    $response->assertSee($user->name);
});

test('staff hitting /account is redirected to agent login by client.only middleware', function () {
    $customer = config('domains.customer_domain');
    $staff = User::factory()->staff(AgentRole::Agent)->create();

    $response = $this->withServerVariables(['HTTP_HOST' => $customer])
        ->actingAs($staff)
        ->get('http://'.$customer.'/account');

    $response->assertRedirect('https://'.config('domains.agent_domain').'/login');
    $this->assertGuest();
});

test('open registration is disabled — /register returns 404', function () {
    // Client users are provisioned by staff; Features::registration() is
    // intentionally commented out in config/fortify.php. If this test
    // fails, someone re-enabled registration — confirm that's intended
    // before updating.
    $response = $this->get('/register');

    $response->assertNotFound();
});

test('LoginResponse discards url.intended so stashed cross-host URLs cannot leak', function () {
    // A client who pre-visited an agent-domain route while unauthenticated
    // would have url.intended stashed by Laravel's Authenticate middleware.
    // LoginResponse must not honour that — the agent.only middleware would
    // reject the client anyway, but a consistent role-first landing avoids
    // the round-trip.
    $user = User::factory()->create(['role' => null]);

    $this->withSession(['url.intended' => 'https://agents.example.test/dashboard']);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect('https://'.config('domains.customer_domain').'/account');
});
