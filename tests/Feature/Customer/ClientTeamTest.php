<?php

use App\Models\Client;
use App\Models\User;
use App\Notifications\Customer\ClientUserInvited;
use App\Services\Customer\InviteClientUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->client = Client::factory()->create();
    $this->owner = User::factory()->create([
        'client_id' => $this->client->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
});

test('InviteClientUser creates a pending member and notifies them', function () {
    Notification::fake();

    $invited = (new InviteClientUser)('new@example.com', $this->client, $this->owner);

    expect($invited)->toBeInstanceOf(User::class)
        ->and($invited->email)->toBe('new@example.com')
        ->and($invited->client_id)->toBe($this->client->id)
        ->and($invited->role)->toBeNull()
        ->and($invited->isClientUser())->toBeTrue()
        ->and($invited->email_verified_at)->not->toBeNull()
        ->and($invited->last_login_at)->toBeNull();

    Notification::assertSentTo($invited, ClientUserInvited::class, function ($n) {
        return $n->clientName === $this->client->name
            && $n->invitedByName === $this->owner->name
            && ! empty($n->token);
    });
});

test('InviteClientUser rejects duplicate emails on the same team', function () {
    User::factory()->create(['email' => 'dup@example.com', 'client_id' => $this->client->id]);

    expect(fn () => (new InviteClientUser)('dup@example.com', $this->client, $this->owner))
        ->toThrow(\Illuminate\Validation\ValidationException::class, 'This email is already on your team.');
});

test('InviteClientUser rejects emails attached to another client', function () {
    $otherClient = Client::factory()->create();
    User::factory()->create(['email' => 'other@example.com', 'client_id' => $otherClient->id]);

    expect(fn () => (new InviteClientUser)('other@example.com', $this->client, $this->owner))
        ->toThrow(\Illuminate\Validation\ValidationException::class, 'A user with this email already exists.');
});

test('InviteClientUser surfaces duplicate-email error under the inviteEmail key', function () {
    // Regression: both invite forms (agents.client-team + customer.team)
    // bind the input to property `inviteEmail`. Throwing under any other
    // key (e.g. `email`) lands the message on a non-existent field and
    // the UI renders nothing — silent failure.
    User::factory()->create(['email' => 'taken@example.com', 'client_id' => $this->client->id]);

    try {
        (new InviteClientUser)('taken@example.com', $this->client, $this->owner);
        $this->fail('Expected ValidationException');
    } catch (\Illuminate\Validation\ValidationException $e) {
        expect($e->errors())->toHaveKey('inviteEmail')
            ->and($e->errors())->not->toHaveKey('email');
    }
});

test('InviteClientUser restores a soft-deleted teammate on re-invite', function () {
    Notification::fake();

    // Pre-existing teammate that was removed.
    $removed = User::factory()->create([
        'email' => 'comeback@example.com',
        'client_id' => $this->client->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $removed->delete();
    expect($removed->fresh()->trashed())->toBeTrue();

    $reinvited = (new InviteClientUser)('comeback@example.com', $this->client, $this->owner);

    expect($reinvited->id)->toBe($removed->id, 'should reuse the same User row, not create a new one')
        ->and($reinvited->trashed())->toBeFalse('should be restored')
        ->and($reinvited->client_id)->toBe($this->client->id)
        ->and($reinvited->last_login_at)->toBeNull('should reset to Pending state');

    Notification::assertSentTo($reinvited, ClientUserInvited::class);
});

test('InviteClientUser reassigns a soft-deleted user from another client', function () {
    Notification::fake();
    $otherClient = Client::factory()->create();
    $removed = User::factory()->create([
        'email' => 'transfer@example.com',
        'client_id' => $otherClient->id,
        'role' => null,
    ]);
    $removed->delete();

    $reinvited = (new InviteClientUser)('transfer@example.com', $this->client, $this->owner);

    expect($reinvited->id)->toBe($removed->id)
        ->and($reinvited->client_id)->toBe($this->client->id, 'should be reassigned to the new client')
        ->and($reinvited->trashed())->toBeFalse();

    Notification::assertSentTo($reinvited, ClientUserInvited::class);
});

test('team page lists all members of the same client', function () {
    $teammate = User::factory()->create([
        'client_id' => $this->client->id,
        'role' => null,
    ]);

    $unrelated = User::factory()->create([
        'client_id' => Client::factory()->create()->id,
        'role' => null,
    ]);

    $this->actingAs($this->owner);

    Livewire::test('customer.team')
        ->assertSee($this->owner->email)
        ->assertSee($teammate->email)
        ->assertDontSee($unrelated->email);
});

test('team page can send an invite', function () {
    Notification::fake();
    $this->actingAs($this->owner);

    Livewire::test('customer.team')
        ->set('inviteEmail', 'colleague@example.com')
        ->call('sendInvite')
        ->assertSet('inviteEmail', '')
        ->assertSee('Invite sent');

    expect(User::where('email', 'colleague@example.com')->exists())->toBeTrue();
    Notification::assertSentTo(User::where('email', 'colleague@example.com')->first(), ClientUserInvited::class);
});

test('team page can remove a teammate but not the current user', function () {
    $teammate = User::factory()->create([
        'client_id' => $this->client->id,
        'role' => null,
    ]);
    $teammateId = $teammate->id;

    $this->actingAs($this->owner);

    // Self-removal is blocked.
    Livewire::test('customer.team')
        ->call('removeMember', $this->owner->id)
        ->assertSee("can't remove yourself");

    expect(User::find($this->owner->id))->not->toBeNull();

    // Removing someone else soft-deletes them.
    Livewire::test('customer.team')
        ->call('removeMember', $teammateId)
        ->assertSee('removed from the team');

    expect(User::find($teammateId))->toBeNull()
        ->and(User::withTrashed()->find($teammateId))->not->toBeNull();
});

test('staff users cannot mount the team component', function () {
    $staff = User::factory()->create(['role' => \App\Enums\AgentRole::Agent]);
    $this->actingAs($staff);

    Livewire::test('customer.team')->assertStatus(403);
});

test('the team route requires authentication', function () {
    // /team is domain-scoped to the customer surface (routes/customer.php
    // wraps everything in Route::domain(config('domains.customer_domain'))).
    // .env.testing sets SURFACE=all so the route is registered; we hit
    // it via the customer host so Route::domain() matches.
    expect(config('surfaces.current'))->toBe('all', 'SURFACE=all expected; check .env.testing');
    expect(\Illuminate\Support\Facades\Route::has('client.team'))->toBeTrue();

    $host = config('domains.customer_domain');
    $this->get("https://{$host}/team")->assertRedirect();
});
