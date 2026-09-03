<?php

namespace App\Livewire\Concerns;

use App\Support\RoleGuard;

trait RequiresSuperAdminRole
{
    /**
     * Re-check super-admin capability on every subsequent Livewire request.
     *
     * isSuperAdmin() is isAdmin() PLUS an allowlist, while the only route middleware
     * these components carry is `admin`, so the component re-checks the stronger
     * predicate itself on every request rather than relying on a mount-time check.
     */
    public function hydrateRequiresSuperAdminRole(): void
    {
        $this->assertCurrentUserIsSuperAdmin();
    }

    protected function assertCurrentUserIsSuperAdmin(): void
    {
        RoleGuard::assertSuperAdmin();
    }
}
