<?php

use App\Enums\AgentRole;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('scopes agents to own-created-or-assigned sites', function (): void {
    $agentA = User::factory()->staff(AgentRole::Agent)->create();
    $agentB = User::factory()->staff(AgentRole::Agent)->create();

    // Site created and assigned to agent A.
    $siteCreatedByA = Site::factory()->create([
        'created_by_user_id' => $agentA->id,
        'assigned_to_user_id' => $agentA->id,
    ]);

    // Site created by agent B but assigned to agent A.
    $siteAssignedToA = Site::factory()->create([
        'created_by_user_id' => $agentB->id,
        'assigned_to_user_id' => $agentA->id,
    ]);

    // Site belonging entirely to agent B — agent A must not see it.
    $siteB = Site::factory()->create([
        'created_by_user_id' => $agentB->id,
        'assigned_to_user_id' => $agentB->id,
    ]);

    $visibleIds = $agentA->accessibleSites()->pluck('id')->sort()->values()->all();

    expect($visibleIds)->toContain($siteCreatedByA->id)
        ->toContain($siteAssignedToA->id)
        ->not->toContain($siteB->id);
});

it('returns all sites for managers', function (): void {
    $manager = User::factory()->staff(AgentRole::Manager)->create();
    $agent = User::factory()->staff(AgentRole::Agent)->create();

    Site::factory()->count(3)->create([
        'created_by_user_id' => $agent->id,
        'assigned_to_user_id' => $agent->id,
    ]);

    expect($manager->accessibleSites()->count())->toBe(3);
});

it('returns all sites for admins', function (): void {
    $admin = User::factory()->staff(AgentRole::Admin)->create();
    $agent = User::factory()->staff(AgentRole::Agent)->create();

    Site::factory()->count(4)->create([
        'created_by_user_id' => $agent->id,
        'assigned_to_user_id' => $agent->id,
    ]);

    expect($admin->accessibleSites()->count())->toBe(4);
});

it('scopes client users to their client sites', function (): void {
    $client = Client::factory()->create();
    $otherClient = Client::factory()->create();

    $clientUser = User::factory()->create(['client_id' => $client->id, 'role' => null]);

    $agent = User::factory()->staff(AgentRole::Agent)->create();

    $clientSite = Site::factory()->create([
        'client_id' => $client->id,
        'created_by_user_id' => $agent->id,
        'assigned_to_user_id' => $agent->id,
    ]);

    $otherSite = Site::factory()->create([
        'client_id' => $otherClient->id,
        'created_by_user_id' => $agent->id,
        'assigned_to_user_id' => $agent->id,
    ]);

    $visibleIds = $clientUser->accessibleSites()->pluck('id')->all();

    expect($visibleIds)->toContain($clientSite->id)
        ->not->toContain($otherSite->id);
});
