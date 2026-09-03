<?php

namespace App\Livewire\Concerns;

use App\Support\RoleGuard;

trait RequiresStaffRole
{
    /**
     * Re-check staff capability on every subsequent Livewire request so a
     * demoted/revoked user cannot keep using a snapshot mounted as staff.
     * Route-level agent.only is not replayed unless registered as Livewire
     * persistent middleware; this is the in-component backup.
     */
    public function hydrateRequiresStaffRole(): void
    {
        $this->assertCurrentUserIsStaff();
    }

    protected function assertCurrentUserIsStaff(): void
    {
        RoleGuard::assertStaff();
    }
}
