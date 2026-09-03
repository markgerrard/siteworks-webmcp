<?php

use App\Enums\AgentRole;
use App\Models\Client;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();

    config()->set('domains.editor_preview_domain', 'editor-preview.test');
    config()->set('domains.agent_domain', 'agents.test');
    config()->set('domains.customer_domain', 'app.test');
});

// The iframe-side bridge rejects every postMessage whose origin doesn't match
// parentOrigin, so parentOrigin must reflect the actual invoking surface rather
// than being hardcoded to one — otherwise a client opening the editor from a
// different surface would see all save/publish/discard messages silently dropped.

it('mints an iframe URL whose parent_origin matches the agent surface when invoked from agents', function () {
    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->for($user)->create();
    $page = GeneratedPage::factory()->for($site)->create();

    $response = $this->actingAs($user)
        ->get('https://agents.test'.route('site.editor-shell', [
            'site' => $site->id,
            'page' => $page->id,
        ], absolute: false));

    $response->assertOk();
    $body = $response->getContent();

    expect($body)->toContain('parent_origin=https%3A%2F%2Fagents.test');
});

it('mints an iframe URL whose parent_origin matches the customer surface when invoked from customer', function () {
    $client = Client::factory()->create();
    $clientUser = User::factory()->create(['client_id' => $client->id, 'role' => null]);
    $site = Site::factory()->create(['client_id' => $client->id]);
    $page = GeneratedPage::factory()->for($site)->create();

    $response = $this->actingAs($clientUser)
        ->get('https://app.test'.route('site.editor-shell', [
            'site' => $site->id,
            'page' => $page->id,
        ], absolute: false));

    $response->assertOk();
    $body = $response->getContent();

    expect($body)->toContain('parent_origin=https%3A%2F%2Fapp.test');
});
