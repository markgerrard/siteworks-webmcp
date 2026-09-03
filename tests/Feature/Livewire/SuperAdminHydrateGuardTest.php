<?php

use App\Enums\AgentRole;
use App\Models\User;
use Livewire\Livewire;

/**
 * isSuperAdmin() is isAdmin() PLUS membership of an allowlist, but the only route
 * middleware on these components is `admin`. A persistent-middleware replay that
 * only re-checks that weaker `admin` gate would let the stronger one lapse: a
 * super-admin removed from the allowlist, or demoted to ordinary admin, would keep
 * a snapshot that keeps rendering super-admin-only data on every subsequent request.
 * The component must re-check isSuperAdmin() itself on every hydrate.
 */
function actingSuperAdmin(): User
{
    $user = User::factory()->admin()->create();
    config(['domains.super_admin_allowlist' => $user->email]);

    return $user;
}

test('a super-admin removed from the allowlist cannot keep paging the override audit', function () {
    $user = actingSuperAdmin();

    $component = Livewire::actingAs($user)->test();
    $component->assertOk();

    config(['domains.super_admin_allowlist' => '']);

    $component->call('gotoPage', 2)->assertForbidden();
});

test('a super-admin demoted to agent cannot keep paging the override audit', function () {
    $user = actingSuperAdmin();

    $component = Livewire::actingAs($user)->test();

    $user->role = AgentRole::Agent;
    $user->save();

    $component->call('gotoPage', 2)->assertForbidden();
});

test('a super-admin removed from the allowlist cannot keep using a SID override form', function () {
    $user = actingSuperAdmin();
    $site = App\Models\Site::factory()->create();

    $component = Livewire::actingAs($user)->test( ['site' => $site]);
    $component->assertOk();

    config(['domains.super_admin_allowlist' => '']);

    $component->set('reason', 'attempting a write after losing super-admin')
        ->assertForbidden();
});

test('a super-admin still in the allowlist is unaffected', function () {
    $user = actingSuperAdmin();

    Livewire::actingAs($user)->test()
        ->call('gotoPage', 2)
        ->assertOk();
});
