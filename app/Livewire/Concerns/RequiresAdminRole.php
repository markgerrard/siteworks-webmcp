<?php

namespace App\Livewire\Concerns;

use App\Support\RoleGuard;

trait RequiresAdminRole
{
    /**
     * Re-check admin capability on every subsequent Livewire request so a
     * demoted admin cannot keep using a snapshot mounted as admin.
     * Route-level admin middleware is not replayed unless registered as
     * Livewire persistent middleware; this is the in-component backup.
     */
    public function hydrateRequiresAdminRole(): void
    {
        $this->assertCurrentUserIsAdmin();
    }

    protected function assertCurrentUserIsAdmin(): void
    {
        RoleGuard::assertAdmin();
    }
}
