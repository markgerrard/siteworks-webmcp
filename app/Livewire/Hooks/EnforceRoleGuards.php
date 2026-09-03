<?php

namespace App\Livewire\Hooks;

use App\Livewire\Concerns\RequiresAdminRole;
use App\Livewire\Concerns\RequiresStaffRole;
use App\Livewire\Concerns\RequiresSuperAdminRole;
use App\Support\RoleGuard;
use Livewire\ComponentHook;

/**
 * Enforce the Requires*Role traits on every Livewire action call.
 *
 * Both the `call` and `update` events are guarded, regardless of whether hydration
 * ran for this request (lazy-loaded components skip it), and the hydrate hooks stay
 * as well so a stale snapshot is caught before either event runs.
 *
 * `Livewire::test()` disables lazy loading, so tests must exercise the lazy path explicitly.
 */
class EnforceRoleGuards extends ComponentHook
{
    public function call($method, $params, $returnEarly, $metadata, $componentContext)
    {
        $this->enforce();
    }

    /** Property writes are a separate event that runs before `call`; guard them too. */
    public function update($propertyName, $fullPath, $newValue)
    {
        $this->enforce();
    }

    private function enforce(): void
    {
        $traits = class_uses_recursive($this->component);

        if (in_array(RequiresSuperAdminRole::class, $traits, true)) {
            RoleGuard::assertSuperAdmin();
        }

        if (in_array(RequiresAdminRole::class, $traits, true)) {
            RoleGuard::assertAdmin();
        }

        if (in_array(RequiresStaffRole::class, $traits, true)) {
            RoleGuard::assertStaff();
        }
    }
}
