<?php

use App\Http\Middleware\EnsureAgentRole;
use App\Http\Middleware\EnsureClientUser;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Livewire\Livewire;

test('staff role and email-verification middleware persist across Livewire updates', function () {
    $persistent = Livewire::getPersistentMiddleware();

    expect($persistent)->toContain(EnsureAgentRole::class)
        ->and($persistent)->toContain(EnsureClientUser::class)
        ->and($persistent)->toContain(EnsureEmailIsVerified::class)
        ->and($persistent)->toContain(EnsureUserIsAdmin::class);
});
