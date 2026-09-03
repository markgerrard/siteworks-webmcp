<?php

use App\Enums\AgentRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;

uses(RefreshDatabase::class);

test('POST /login with staff email returns validation error and does not authenticate', function () {
    $staff = User::factory()->staff(AgentRole::Agent)->create();

    $response = $this->post(route('login.store'), [
        'email' => $staff->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors(Fortify::username());
    $this->assertGuest();
});

test('POST /login with client user credentials authenticates successfully', function () {
    // Client users have null role — create one explicitly.
    $client = User::factory()->create([
        'role' => null,
        'password' => Hash::make('secret123'),
    ]);

    $response = $this->post(route('login.store'), [
        'email' => $client->email,
        'password' => 'secret123',
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertAuthenticated();
});
