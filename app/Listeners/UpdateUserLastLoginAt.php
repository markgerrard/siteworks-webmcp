<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;

class UpdateUserLastLoginAt
{
    /**
     * Stamp `last_login_at` on every successful authentication so the
     * team-management UI can surface "Pending" for invited users who
     * never logged in. Fires for both Fortify password logins and
     * SSO completions (the SSO controller previously set this column
     * inline — that path still works, the listener just makes the
     * write idempotent rather than per-controller).
     */
    public function handle(Login $event): void
    {
        $user = $event->user;
        if (! $user instanceof User) {
            return;
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();
    }
}
