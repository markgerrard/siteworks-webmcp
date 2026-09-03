<?php

use App\Enums\AgentRole;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff can mark a preview site as paid', function () {
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $staff->id]);

    Livewire::actingAs($staff)
        ->test('site-paid-flag', ['siteId' => $site->id])
        ->call('toggle');

    expect($site->refresh()->isPaid())->toBeTrue();
});

test('staff can mark a paid site back to preview', function () {
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $staff->id,
        'paid_at' => now()->subDay(),
    ]);

    Livewire::actingAs($staff)
        ->test('site-paid-flag', ['siteId' => $site->id])
        ->call('toggle');

    expect($site->refresh()->paid_at)->toBeNull();
});

test('a Manager can set the paid flag, unlike the cost cap', function () {
    // site-cost-cap gates on exact-role isAgent(), which locks Managers and
    // Admins out of their own feature -- a known open finding. This component
    // must not repeat it: staff capability is the right bar.
    $manager = User::factory()->staff(AgentRole::Manager)->create();
    $site = Site::factory()->create();

    Livewire::actingAs($manager)
        ->test('site-paid-flag', ['siteId' => $site->id])
        ->call('toggle');

    expect($site->refresh()->isPaid())->toBeTrue();
});

test('a client user cannot set the paid flag on their own site', function () {
    // Whether a customer is paying is a commercial fact about them; letting
    // the customer assert it would be self-certification.
    $client = Client::factory()->create();
    $clientUser = User::factory()->create([
        'client_id' => $client->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $client->id]);

    // Blocked at mount, so the component never renders for them at all.
    // The follow-up request path (a hand-crafted Livewire update on a
    // snapshot minted while staff) is covered by RequiresStaffRole's own
    // hydrate hook -- it cannot be driven from the suite, because
    // /livewire/update 404s under the test kernel.
    Livewire::actingAs($clientUser)
        ->test('site-paid-flag', ['siteId' => $site->id])
        ->assertForbidden();

    expect($site->refresh()->isPaid())->toBeFalse();
});

test('setting the paid flag leaves image_quality_tier alone', function () {
    // The two are deliberately independent. If this ever starts failing,
    // someone has coupled them without revisiting the decision.
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $staff->id]);
    // refresh() first: the column's default is applied by the DB on insert,
    // so the in-memory model still has null until it is read back.
    $tierBefore = $site->refresh()->image_quality_tier;

    Livewire::actingAs($staff)
        ->test('site-paid-flag', ['siteId' => $site->id])
        ->call('toggle');

    expect($site->refresh()->image_quality_tier)->toBe($tierBefore);
});
