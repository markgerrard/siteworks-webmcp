<?php

use App\Models\Client;
use App\Models\User;
use Illuminate\Auth\Events\Login;

it('stamps last_login_at when a client user logs in via password', function () {
    $client = Client::factory()->create();
    $user = User::factory()->create([
        'client_id' => $client->id,
        'role' => null,
        'password' => bcrypt('secret-password-1'),
        'last_login_at' => null,
    ]);

    expect($user->last_login_at)->toBeNull();

    // Fortify drives password login through the standard Login event;
    // dispatching it directly is the cheapest reproduction (no need
    // to mount the Fortify routes for this regression).
    event(new Login('web', $user, false));

    expect($user->fresh()->last_login_at)->not->toBeNull();
});

it('stamps last_login_at on staff SSO completion path', function () {
    $user = User::factory()->create([
        'role' => 'agent',
        'last_login_at' => null,
    ]);

    event(new Login('web', $user, false));

    expect($user->fresh()->last_login_at)->not->toBeNull();
});

it('does not overwrite last_login_at with a stale value when the listener saves quietly', function () {
    // saveQuietly() must not trigger model events that could clobber other
    // columns — confirmed by checking the user's other attributes survive.
    $user = User::factory()->create([
        'name' => 'Original Name',
        'last_login_at' => null,
    ]);

    event(new Login('web', $user, false));

    $fresh = $user->fresh();
    expect($fresh->last_login_at)->not->toBeNull();
    expect($fresh->name)->toBe('Original Name');
});
