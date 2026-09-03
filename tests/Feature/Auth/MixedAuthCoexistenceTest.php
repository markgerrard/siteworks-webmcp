<?php

/**
 * Password-authentication coexistence tests.
 */

use App\Enums\AgentRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('pre-existing admin with a real password can still email-login', function () {
    // Simulates an admin row that existed pre-SSO — real bcrypt hash.
    $admin = User::factory()->create([
        'role' => AgentRole::Admin,
        'password' => Hash::make('adminsecret'),
    ]);

    $response = $this->post(route('login.store'), [
        'email' => $admin->email,
        'password' => 'adminsecret',
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertAuthenticated();
});

test('SSO-created staff cannot password-login because their password is an unknowable hash', function () {
    // Factory staff() sets password = Hash::make(Str::random(64)) — a real
    // hash but of an unguessable secret. Hash::check can never succeed.
    $staff = User::factory()->staff(AgentRole::Agent)->create();

    $this->post(route('login.store'), [
        'email' => $staff->email,
        'password' => 'anything-they-might-guess',
    ]);

    $this->assertGuest();
});

test('password-authed admin and SSO-authed staff can coexist as separate sessions', function () {
    $admin = User::factory()->create([
        'role' => AgentRole::Admin,
        'password' => Hash::make('adminsecret'),
    ]);
    $sso = User::factory()->staff(AgentRole::Agent)->create();

    // Admin logs in via password.
    $this->post(route('login.store'), [
        'email' => $admin->email,
        'password' => 'adminsecret',
    ])->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($admin);

    // Admin's password hash is intact; SSO user's row exists in parallel.
    $admin->refresh();
    expect(Hash::check('adminsecret', $admin->password))->toBeTrue();
    expect($sso->fresh()->exists)->toBeTrue();
});
