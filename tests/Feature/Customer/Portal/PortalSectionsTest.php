<?php

use App\Enums\SiteStatus;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->customerHost = config('domains.customer_domain');
    $this->client = Client::factory()->create();
    $this->user = User::factory()->create([
        'client_id' => $this->client->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $this->site = Site::factory()->create([
        'client_id' => $this->client->id,
        'status' => SiteStatus::Published,
    ]);
});

test('canonical /sites/{site} URL serves the Pages section', function () {
    // /sites/{site} is the site's canonical URL — Overview was removed
    // (the sidebar already shows status + the View/Edit CTAs
    // that were Overview's main reason to exist), so this URL now
    // serves the page-manager directly.
    $this->actingAs($this->user)
        ->get("https://{$this->customerHost}/sites/{$this->site->id}")
        ->assertOk()
        ->assertSee('Pages');
});

test('design section renders the three design pills', function () {
    $this->actingAs($this->user)
        ->get("https://{$this->customerHost}/sites/{$this->site->id}/design")
        ->assertOk()
        ->assertSee('Design Brief')
        ->assertSee('Options')
        ->assertSee('Logo');
});

test('navigation section renders the nav-manager', function () {
    $this->actingAs($this->user)
        ->get("https://{$this->customerHost}/sites/{$this->site->id}/navigation")
        ->assertOk()
        ->assertSee('Navigation');
});

test('personalise section renders for the client', function () {
    $this->actingAs($this->user)
        ->get("https://{$this->customerHost}/sites/{$this->site->id}/personalise")
        ->assertOk()
        ->assertSee('Personalise');
});

test('chatbot section renders the chatbot manager', function () {
    $this->actingAs($this->user)
        ->get("https://{$this->customerHost}/sites/{$this->site->id}/chatbot")
        ->assertOk()
        ->assertSee('Chatbot');
});

test('history section renders when versioned renderer is enabled', function () {
    config(['site.use_versioned_renderer' => true]);

    $this->actingAs($this->user)
        ->get("https://{$this->customerHost}/sites/{$this->site->id}/history")
        ->assertOk()
        ->assertSee('Version History');
});

test('history section returns 404 when versioned renderer is disabled', function () {
    config(['site.use_versioned_renderer' => false]);

    $this->actingAs($this->user)
        ->get("https://{$this->customerHost}/sites/{$this->site->id}/history")
        ->assertNotFound();
});

test('business info section renders contact + scope editors', function () {
    \App\Models\BusinessProfile::factory()->create(['site_id' => $this->site->id]);

    $this->actingAs($this->user)
        ->get("https://{$this->customerHost}/sites/{$this->site->id}/business-info")
        ->assertOk()
        ->assertSee('Contact Details')
        ->assertSee('Geographic Scope');
});

test('domain section renders the domain manager', function () {
    $this->actingAs($this->user)
        ->get("https://{$this->customerHost}/sites/{$this->site->id}/domain")
        ->assertOk()
        ->assertSee('Domain');
});
