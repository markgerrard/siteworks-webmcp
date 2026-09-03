<?php

use App\Enums\AgentRole;
use App\Models\Client;
use App\Models\User;
use App\Notifications\Customer\ClientUserInvited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->client = Client::factory()->create();
    $this->agent = User::factory()->create([
        'role' => AgentRole::Agent,
        'last_login_at' => now(),
    ]);
});

test('agent can invite a user to a client team', function () {
    Notification::fake();
    $this->actingAs($this->agent);

    Livewire::test('agents.client-team', ['client' => $this->client])
        ->set('inviteEmail', 'newuser@example.com')
        ->call('sendInvite')
        ->assertSet('inviteEmail', '')
        ->assertSee('Invite sent');

    $created = User::where('email', 'newuser@example.com')->first();
    expect($created)->not->toBeNull()
        ->and($created->client_id)->toBe($this->client->id)
        ->and($created->isClientUser())->toBeTrue();

    Notification::assertSentTo($created, ClientUserInvited::class, function ($n) {
        return $n->clientName === $this->client->name
            && $n->invitedByName === $this->agent->name;
    });
});

test('agent can resend an invite to a pending member', function () {
    Notification::fake();
    $pending = User::factory()->create([
        'client_id' => $this->client->id,
        'role' => null,
        'last_login_at' => null,
    ]);

    $this->actingAs($this->agent);

    Livewire::test('agents.client-team', ['client' => $this->client])
        ->call('resendInvite', $pending->id)
        ->assertSee('Invite resent');

    Notification::assertSentTo($pending, ClientUserInvited::class);
});

test('agent can remove a client team member', function () {
    $teammate = User::factory()->create([
        'client_id' => $this->client->id,
        'role' => null,
    ]);

    $this->actingAs($this->agent);

    Livewire::test('agents.client-team', ['client' => $this->client])
        ->call('removeMember', $teammate->id)
        ->assertSee('removed from the team');

    expect(User::find($teammate->id))->toBeNull()
        ->and(User::withTrashed()->find($teammate->id))->not->toBeNull();
});

test('agent client-team list excludes staff users that may share client_id', function () {
    // A staff user with client_id (rare — usually null for staff) shouldn't
    // appear in the team list. The component scopes ->whereNull('role').
    $clientUser = User::factory()->create([
        'client_id' => $this->client->id,
        'role' => null,
    ]);
    $staffShadowed = User::factory()->create([
        'client_id' => $this->client->id,
        'role' => AgentRole::Agent,
    ]);

    $this->actingAs($this->agent);

    Livewire::test('agents.client-team', ['client' => $this->client])
        ->assertSee($clientUser->email)
        ->assertDontSee($staffShadowed->email);
});

test('non-staff users cannot mount the agent client-team component', function () {
    $clientUser = User::factory()->create([
        'client_id' => $this->client->id,
        'role' => null,
    ]);
    $this->actingAs($clientUser);

    Livewire::test('agents.client-team', ['client' => $this->client])
        ->assertStatus(403);
});

test('existing snapshot cannot invite after the staff user is demoted to a client', function () {
    Notification::fake();
    $this->actingAs($this->agent);

    $component = Livewire::test('agents.client-team', ['client' => $this->client])
        ->set('inviteEmail', 'after-demotion@example.com');

    $this->agent->role = null;
    $this->agent->client_id = $this->client->id;
    $this->agent->save();

    $component->call('sendInvite')->assertForbidden();

    expect(User::where('email', 'after-demotion@example.com')->exists())->toBeFalse();
});
