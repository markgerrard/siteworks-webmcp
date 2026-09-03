<?php

use App\Enums\AgentRole;
use App\Enums\GenerationMode;
use App\Enums\SiteStatus;
use App\Http\Middleware\EnsureClientUser;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// Site management routes live on the primary domain until they migrate to the
// agent domain. Bypass client.only middleware so these tests can use staff users.
// TODO(sso-future): remove when site management routes move to agent domain.
beforeEach(function () {
    $this->withoutMiddleware(EnsureClientUser::class);
});

it('requires auth', function () {
    // Sites routes live on the agent subdomain (agent.only). Guests
    // get redirected to the agent SSO page, not the primary login.
    $this->get(route('sites.create'))->assertRedirect(route('agent.login'));
});

it('shows the intake form', function () {
    $response = $this->actingAs(User::factory()->staff()->create())
        ->get(route('sites.create'))
        ->assertOk()
        ->assertSee('Business Name')
        ->assertSee('class="grid md:grid-cols-2 gap-x-8 gap-y-6"', false);

    foreach ([
        'client_id',
        'new_client_name',
        'business_name',
        'business_type',
        'site_type',
        'location',
        'region',
        'country',
        'agent_notes',
        'theme',
    ] as $field) {
        $response->assertSee('name="'.$field.'"', false);
    }

    $response->assertSee('Free text — used by the AI builder, not for filtering.');
});

it('creates a site with minimum fields', function () {
    Queue::fake();
    $user = User::factory()->staff()->create();

    $this->actingAs($user)->post(route('sites.store'), [
        'business_name' => 'Smith Plumbing',
        'business_type' => 'Plumber',
        'location' => 'Wigan',
    ]);

    $site = Site::first();
    expect($site)->not->toBeNull()
        ->and($site->business_name)->toBe('Smith Plumbing')
        ->and($site->status)->toBe(SiteStatus::Draft)
        ->and($site->generation_mode)->toBe(GenerationMode::NoSite)
        ->and($site->created_by_user_id)->toBe($user->id)
        ->and($site->site_type)->toBeNull()
        ->and($site->region)->toBeNull();
});

it('persists optional site_type and region on create', function () {
    Queue::fake();
    $user = User::factory()->staff()->create();

    $this->actingAs($user)->post(route('sites.store'), [
        'business_name' => 'Smith Plumbing',
        'business_type' => 'Plumber',
        'location' => 'Wigan',
        'site_type' => 'plumber',
        'region' => 'north-west',
    ]);

    $site = Site::first();
    expect($site)->not->toBeNull()
        ->and($site->site_type)->toBe('plumber')
        ->and($site->region)->toBe('north-west')
        ->and($site->business_type)->toBe('Plumber')
        ->and($site->location)->toBe('Wigan');
});

it('sets hybrid mode when both URLs provided', function () {
    Queue::fake();
    $this->actingAs(User::factory()->staff()->create())->post(route('sites.store'), [
        'business_name' => 'Smith Plumbing',
        'business_type' => 'Plumber',
        'location' => 'Wigan',
    ]);

    expect(Site::first()->generation_mode)->toBe(GenerationMode::Hybrid);
});

it('validates required fields', function () {
    $this->actingAs(User::factory()->staff()->create())
        ->post(route('sites.store'), [])
        ->assertSessionHasErrors(['business_name', 'business_type', 'location']);
});

it('starts pipeline from show page', function () {
    Queue::fake();
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);

    $this->actingAs($user)->post(route('sites.start', $site));

    expect($site->fresh()->status)->not->toBe(SiteStatus::Draft);
});

it('scopes sites.index to the agent viewing (created or assigned)', function () {
    // sites.index is staff-only now (agent.only). It uses
    // User::accessibleSites() which, for agents, returns sites where
    // created_by_user_id OR assigned_to_user_id matches them.
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $otherAgent = User::factory()->staff(AgentRole::Agent)->create();

    Site::factory()->create(['created_by_user_id' => $agent->id, 'business_name' => 'Mine']);
    Site::factory()->create(['created_by_user_id' => $otherAgent->id, 'business_name' => 'Theirs']);

    $this->actingAs($agent)->get(route('sites.index'))
        ->assertSee('Mine')
        ->assertDontSee('Theirs');
});
