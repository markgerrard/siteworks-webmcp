<?php

use App\Enums\AgentRole;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;

it('renders the assistant panel and toggle on the agents control panel', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();

    $html = $this->actingAs($agent)
        ->get(route('dashboard'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('siteworks.assistant.open')
        ->and($html)->toContain('role="complementary"')
        ->and($html)->toContain('aria-label="Assistant"')
        ->and($html)->toContain(__('Assistant'))
        ->and($html)->toContain(__('Assistant coming soon'))
        ->and($html)->toContain(__('Contact support'))
        ->and($html)->toContain('sparkles')
        ->and($html)->toContain('keydown.escape')
        ->and($html)->toMatch('/<textarea[^>]*disabled|<input[^>]*disabled/')
        ->and($html)->toMatch('/aria-expanded|x-bind:aria-expanded|:aria-expanded/')
        ->and($html)->toMatch('/tabindex="-1"|tabindex=\'-1\'/');
});

it('binds aria-expanded on the toggle and does not steal focus when restoring open', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();

    $html = $this->actingAs($agent)
        ->get(route('dashboard'))
        ->assertOk()
        ->getContent();

    expect($html)->toMatch('/x-bind:aria-expanded|:aria-expanded|aria-expanded/')
        ->and($html)->toContain('data-cp-assistant-toggle')
        ->and($html)->toContain('tabindex="-1"')
        ->and($html)->toContain('$refs.assistantPanel')
        ->and($html)->toContain('[data-cp-assistant-toggle]')
        ->and($html)->toMatch('/this\.open = localStorage\.getItem\('."'".'siteworks\.assistant\.open'."'".'\) === '."'".'1'."'".'/');
});

it('renders the assistant panel on the client portal', function () {
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

    expect($html)->toContain('siteworks.assistant.open')
        ->and($html)->toContain('role="complementary"')
        ->and($html)->toContain(__('Assistant coming soon'));
});

it('slides the assistant panel in from the right with a 200-250ms ease-out', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();

    $html = $this->actingAs($agent)
        ->get(route('dashboard'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data-cp-assistant-panel')
        ->and($html)->toContain('data-cp-assistant-backdrop')
        ->and($html)->toContain('translate-x-full')
        ->and($html)->toContain('translate-x-0')
        ->and($html)->toMatch('/duration-200|duration-250|duration-\[2[0-5]0ms\]/')
        ->and($html)->toContain('ease-out');
});

it('fades the overlay backdrop and disables motion when the user prefers reduced motion', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();

    $html = $this->actingAs($agent)
        ->get(route('dashboard'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data-cp-assistant-backdrop')
        ->and($html)->toContain('opacity-0')
        ->and($html)->toContain('opacity-100');

    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toContain('prefers-reduced-motion')
        ->and($css)->toMatch('/data-cp-assistant-panel/')
        ->and($css)->toMatch('/transition:\s*none/');
});
