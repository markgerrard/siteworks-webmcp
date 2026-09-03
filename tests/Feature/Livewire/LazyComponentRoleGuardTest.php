<?php

use App\Enums\AgentRole;
use App\Models\Site;
use App\Models\User;
use Livewire\Features\SupportLazyLoading\SupportLazyLoading;
use Livewire\Livewire;

/**
 * Hydrate hooks are NOT the always-on backstop the guard traits documented.
 *
 * `SupportLazyLoading::hydrate()` calls `skipHydrate()` while `memo.lazyLoaded` is
 * false, and `SupportLifecycleHooks::hydrate()` then returns before any
 * `hydrateRequires*Role` hook runs. `HandleComponents::callMethods()` still executes
 * the whole `calls` array, and `__lazyLoad` only returns early for itself — so any
 * other method in the same request runs with no role check at all.
 *
 * Nearly every staff widget on `sites/show` is mounted `lazy.bundle`, so this is the
 * normal path for the staff UI, not an edge case.
 *
 * The tests written alongside the guards could not see this: `Livewire::test()` sets
 * `SupportLazyLoading::$disableWhileTesting`, which turns lazy loading off entirely.
 * These re-enable it.
 */
beforeEach(function () {
    SupportLazyLoading::$disableWhileTesting = false;
});

afterEach(function () {
    SupportLazyLoading::$disableWhileTesting = true;
});

function lazyStaffComponent(User $user): \Livewire\Features\SupportTesting\Testable
{
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);

    return Livewire::actingAs($user)->test('google-reviews-panel', [
        'siteId' => $site->id,
        'lazy' => true,
    ]);
}

test('a demoted user cannot act on a lazy staff component before it has loaded', function () {
    $staff = User::factory()->staff(AgentRole::Agent)->create();

    $component = lazyStaffComponent($staff);

    // Staff role revoked after the placeholder was minted (isStaff() is
    // "has any role", so a client is a user with none).
    $staff->role = null;
    $staff->save();

    $component->call('findListing')->assertForbidden();
});

test('the guard fires on a lazy component even though hydrate is skipped', function () {
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $component = lazyStaffComponent($staff);

    // Proves the enforcement point is the call hook, not the hydrate hook: the
    // placeholder's memo still says lazyLoaded=false, so SupportLazyLoading has
    // called skipHydrate() and no hydrateRequires*Role hook can have run.
    $staff->role = null;
    $staff->save();

    $component->call('disconnect')->assertForbidden();
});

test('staff in good standing are unaffected on the lazy path', function () {
    $staff = User::factory()->staff(AgentRole::Agent)->create();

    lazyStaffComponent($staff)->call('findListing')->assertOk();
});

test('a demoted user cannot write properties on a lazy staff component', function () {
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $component = lazyStaffComponent($staff);

    $staff->role = null;
    $staff->save();

    // Livewire's HandleComponents::update() applies `updates` and runs updated*()
    // hooks BEFORE callMethods(), so guarding only the `call` event leaves property
    // writes unchecked on exactly the path where hydrate is skipped. Review
    // finding, demonstrated by driving the real update endpoint.
    $component->set('input', 'attacker-controlled')->assertForbidden();
});

test('staff in good standing can still write properties on the lazy path', function () {
    $staff = User::factory()->staff(AgentRole::Agent)->create();

    lazyStaffComponent($staff)->set('input', 'legitimate')->assertOk();
});
