<?php

use App\Enums\AgentRole;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;

it('reserves a hidden left dock slot on the agents control panel', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();

    $html = $this->actingAs($agent)
        ->get(route('dashboard'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('--cp-left-dock')
        ->and($html)->toMatch('/<aside[^>]*\bid="cp-left-dock"[^>]*\bhidden\b|<aside[^>]*\bhidden\b[^>]*\bid="cp-left-dock"/');
});

it('reserves a hidden left dock slot on the client portal', function () {
    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);

    $html = $this->actingAs($client)
        ->get(route('client.portal.site', $site))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('--cp-left-dock')
        ->and($html)->toMatch('/<aside[^>]*\bid="cp-left-dock"[^>]*\bhidden\b|<aside[^>]*\bhidden\b[^>]*\bid="cp-left-dock"/');
});

it('sizes the client left dock with --cp-left-dock exactly once', function () {
    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);

    $html = $this->actingAs($client)
        ->get(route('client.portal.site', $site))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('padding-inline-start: var(--cp-left-dock)')
        ->and($html)->toMatch('/<aside[^>]*\bid="cp-left-dock"/');

    preg_match_all('/style="[^"]*var\(--cp-left-dock\)/', $html, $inlineConsumers);

    expect($inlineConsumers[0])->toHaveCount(0);

    $css = file_get_contents(resource_path('css/app.css'));
    preg_match_all('/#cp-left-dock\s*\{[^}]*width:\s*var\(--cp-left-dock\)/', $css, $widthRules);

    expect($widthRules[0])->toHaveCount(1);
});
