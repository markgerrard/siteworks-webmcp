<?php

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
});

test('client with one site is redirected from / to that sites overview', function () {
    $site = Site::factory()->create(['client_id' => $this->client->id]);

    $this->actingAs($this->user)
        ->get("https://{$this->customerHost}/")
        ->assertRedirect("https://{$this->customerHost}/sites/{$site->id}");
});

test('client with multiple sites is redirected from / to the chooser', function () {
    Site::factory()->count(2)->create(['client_id' => $this->client->id]);

    $this->actingAs($this->user)
        ->get("https://{$this->customerHost}/")
        ->assertRedirect("https://{$this->customerHost}/sites");
});

test('client with zero sites lands on the empty-state route', function () {
    $this->actingAs($this->user)
        ->get("https://{$this->customerHost}/")
        ->assertRedirect("https://{$this->customerHost}/portal");
});

test('AuthLanding::for sends a client with one site to that sites overview', function () {
    $site = Site::factory()->create(['client_id' => $this->client->id]);

    expect(\App\Support\AuthLanding::for($this->user))
        ->toBe("https://{$this->customerHost}/sites/{$site->id}");
});

test('AuthLanding::for sends a client with multiple sites to the chooser', function () {
    Site::factory()->count(2)->create(['client_id' => $this->client->id]);

    expect(\App\Support\AuthLanding::for($this->user))
        ->toBe("https://{$this->customerHost}/sites");
});

test('AuthLanding::for sends a client with no sites to the portal landing', function () {
    expect(\App\Support\AuthLanding::for($this->user))
        ->toBe("https://{$this->customerHost}/portal");
});

test('the sites chooser renders all of the clients sites as cards', function () {
    $a = Site::factory()->create(['client_id' => $this->client->id, 'business_name' => 'Alpha Roofing']);
    $b = Site::factory()->create(['client_id' => $this->client->id, 'business_name' => 'Beta Builders']);

    $this->actingAs($this->user)
        ->get("https://{$this->customerHost}/sites")
        ->assertOk()
        ->assertSee('Alpha Roofing')
        ->assertSee('Beta Builders')
        ->assertSee(route('client.portal.site', $a))
        ->assertSee(route('client.portal.site', $b));
});
