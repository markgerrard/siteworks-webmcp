<?php

use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->client = Client::factory()->create();
    $this->user = User::factory()->create([
        'client_id' => $this->client->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
});

test('switcher lists only sites accessible to the user', function () {
    $mine = Site::factory()->create(['client_id' => $this->client->id, 'business_name' => 'My Plumbing']);
    $other = Site::factory()->create([
        'client_id' => Client::factory()->create()->id,
        'business_name' => 'Rival Plumbing',
    ]);

    $this->actingAs($this->user);

    Livewire::test(\App\Livewire\Client\SiteSwitcher::class, ['activeSiteId' => $mine->id])
        ->assertSee('My Plumbing')
        ->assertDontSee('Rival Plumbing');
});

test('single-site clients see a static label, no dropdown trigger', function () {
    $only = Site::factory()->create(['client_id' => $this->client->id, 'business_name' => 'Only Site']);

    $this->actingAs($this->user);

    Livewire::test(\App\Livewire\Client\SiteSwitcher::class, ['activeSiteId' => $only->id])
        ->assertSee('Only Site')
        ->assertDontSee('Switch site');
});

test('multi-site clients see the switcher dropdown trigger', function () {
    $a = Site::factory()->create(['client_id' => $this->client->id, 'business_name' => 'Site A']);
    Site::factory()->create(['client_id' => $this->client->id, 'business_name' => 'Site B']);

    $this->actingAs($this->user);

    Livewire::test(\App\Livewire\Client\SiteSwitcher::class, ['activeSiteId' => $a->id])
        ->assertSee('Switch site')
        ->assertSee('Site A')
        ->assertSee('Site B');
});

test('tampered activeSiteId pointing to another tenant does not leak that sites data', function () {
    // An unguarded fallback Site::find($this->activeSiteId) would leak a foreign
    // tenant's business_name / custom_domain via Livewire prop tampering.
    Site::factory()->create([
        'client_id' => $this->client->id,
        'business_name' => 'My Plumbing',
    ]);
    $foreign = Site::factory()->create([
        'client_id' => Client::factory()->create()->id,
        'business_name' => 'Foreign Tenant Co',
        'custom_domain' => 'foreign-tenant-secret.example.com',
        'preview_domain' => 'preview-foreign-secret.example.net',
    ]);

    $this->actingAs($this->user);

    // Tampered prop — pretend the client has flipped activeSiteId to the
    // foreign site's ID. The component must not render anything from it.
    Livewire::test(\App\Livewire\Client\SiteSwitcher::class, ['activeSiteId' => $foreign->id])
        ->assertDontSee('Foreign Tenant Co')
        ->assertDontSee('foreign-tenant-secret.example.com')
        ->assertDontSee('preview-foreign-secret.example.net');
});
