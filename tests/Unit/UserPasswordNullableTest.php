<?php

use App\Enums\AgentRole;
use App\Models\User;


it('allows null password for staff users', function (): void {
    $user = User::factory()->staff(AgentRole::Agent)->create(['password' => null]);

    expect($user->exists)->toBeTrue()
        ->and($user->password)->toBeNull();
});

it('allows null password for admin staff users', function (): void {
    $user = User::factory()->staff(AgentRole::Admin)->create(['password' => null]);

    expect($user->exists)->toBeTrue()
        ->and($user->password)->toBeNull();
});
