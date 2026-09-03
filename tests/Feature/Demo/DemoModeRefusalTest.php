<?php

use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('demo.enabled', true);
    $this->withoutVite();
});

/**
 * @return array{0: Site, 1: User}
 */
function demoModePortalSite(): array
{
    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
        'email_verified_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);

    return [$site, $client];
}

it('hides AI generation buttons when demo mode is enabled', function () {
    [$site, $client] = demoModePortalSite();

    $this->actingAs($client)
        ->get(route('client.portal.chatbot', $site))
        ->assertOk()
        ->assertDontSee('Generate from profile');

    $this->actingAs($client)
        ->get(route('client.portal.design', $site))
        ->assertOk()
        ->assertDontSee('Regenerate brief')
        ->assertDontSee('Regenerate concepts');
});

it('flashes a user-visible demo notice from kept portal generation actions', function () {
    [$site, $client] = demoModePortalSite();

    Livewire::actingAs($client)
        ->test('chatbot-manager', ['siteId' => $site->id])
        ->call('regenerate')
        ->assertSee('Not available in this demo');

    Livewire::actingAs($client)
        ->test('design-panel', ['siteId' => $site->id])
        ->call('regenerateBrief')
        ->assertSee('Not available in this demo');

    Livewire::actingAs($client)
        ->test('logo-picker', ['siteId' => $site->id])
        ->call('regenerate')
        ->assertSee('Not available in this demo');

    Livewire::actingAs($client)
        ->test('nav-manager', ['siteId' => $site->id])
        ->call('rebuildNav')
        ->assertSee('Not available in this demo');

    Livewire::actingAs($client)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('regenerateHero', 'home')
        ->assertSee('Not available in this demo');
});

it('does not register generate_image, generate_logo_concepts, regenerate_hero, or manage_video', function () {
    $names = array_keys(app(\App\Services\Site\Editor\OperationRegistry::class)->all());

    expect($names)->not->toContain('generate_image')
        ->and($names)->not->toContain('generate_logo_concepts')
        ->and($names)->not->toContain('regenerate_hero')
        ->and($names)->not->toContain('manage_video');
});
