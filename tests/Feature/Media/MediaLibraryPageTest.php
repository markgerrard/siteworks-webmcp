<?php

use App\Enums\AgentRole;
use App\Models\Site;
use App\Models\User;

it('redirects guests to the agent login', function () {
    $site = Site::factory()->create();

    $this->get(route('sites.media', $site))->assertRedirect(route('agent.login'));
});

it('renders the media library for an agent who can view the site', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    $this->actingAs($agent)
        ->get(route('sites.media', $site))
        ->assertOk()
        ->assertSee('Media library')
        ->assertSeeLivewire('media.library');
});

it('forbids an agent who cannot view the site', function () {
    $owner = User::factory()->staff(AgentRole::Agent)->create();
    $outsider = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $owner->id]);

    $this->actingAs($outsider)
        ->get(route('sites.media', $site))
        ->assertForbidden();
});
