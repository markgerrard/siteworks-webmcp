<?php

use App\Enums\AgentRole;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('sets created_by and assigned_to on creation when authenticated', function (): void {
    $user = User::factory()->staff(AgentRole::Agent)->create();

    $this->actingAs($user);

    $site = Site::factory()->create(['created_by_user_id' => null, 'assigned_to_user_id' => null]);

    expect($site->created_by_user_id)->toBe($user->id)
        ->and($site->assigned_to_user_id)->toBe($user->id);
});

it('leaves columns null when no authenticated user', function (): void {
    // Create without any auth (queue job scenario). We must bypass the
    // observer's auth check by passing explicit nulls and creating as guest.
    $site = Site::factory()->create([
        'created_by_user_id' => null,
        'assigned_to_user_id' => null,
    ]);

    expect($site->created_by_user_id)->toBeNull()
        ->and($site->assigned_to_user_id)->toBeNull();
});

it('leaves columns null when the authenticated user is a client (role=null)', function (): void {
    // Critical: client users creating sites must NOT get their id stamped
    // into created_by/assigned_to. Agent scope is "created_by OR assigned_to
    // = me" — if a client's id sits there, no agent would ever see the site.
    $client = User::factory()->create(['role' => null]);

    $this->actingAs($client);

    $site = Site::factory()->create([
        'created_by_user_id' => null,
        'assigned_to_user_id' => null,
    ]);

    expect($site->created_by_user_id)->toBeNull()
        ->and($site->assigned_to_user_id)->toBeNull();
});

it('does not overwrite explicitly set created_by', function (): void {
    $creator = User::factory()->staff(AgentRole::Agent)->create();
    $other = User::factory()->staff(AgentRole::Agent)->create();

    $this->actingAs($other);

    $site = Site::factory()->create([
        'created_by_user_id' => $creator->id,
    ]);

    // Observer must not overwrite the explicitly supplied created_by_user_id.
    expect($site->created_by_user_id)->toBe($creator->id);
});
