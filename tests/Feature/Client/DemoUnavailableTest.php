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
function demoUnavailablePortalSite(): array
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

it('the chatbot portal page does not offer prompt generation', function () {
    [$site, $client] = demoUnavailablePortalSite();

    $this->actingAs($client)
        ->get(route('client.portal.chatbot', $site))
        ->assertOk()
        ->assertDontSee('Generate from profile')
        ->assertDontSee('Regenerate from profile');
});

it('chatbot regenerate flashes the demo-unavailable notice', function () {
    [$site, $client] = demoUnavailablePortalSite();

    Livewire::actingAs($client)
        ->test('chatbot-manager', ['siteId' => $site->id])
        ->call('regenerate')
        ->assertSee('Not available in this demo');
});

it('the design portal page does not offer brief or logo generation', function () {
    [$site, $client] = demoUnavailablePortalSite();

    $this->actingAs($client)
        ->get(route('client.portal.design', $site))
        ->assertOk()
        ->assertDontSee('Regenerate brief')
        ->assertDontSee('Regenerate concepts')
        ->assertDontSee('Generate manually');
});

it('design brief regenerate flashes the demo-unavailable notice', function () {
    [$site, $client] = demoUnavailablePortalSite();

    Livewire::actingAs($client)
        ->test('design-panel', ['siteId' => $site->id])
        ->call('regenerateBrief')
        ->assertSee('Not available in this demo');
});

it('logo picker regenerate flashes the demo-unavailable notice', function () {
    [$site, $client] = demoUnavailablePortalSite();

    Livewire::actingAs($client)
        ->test('logo-picker', ['siteId' => $site->id])
        ->call('regenerate')
        ->assertSee('Not available in this demo');
});

it('the navigation portal page does not offer AI rebuild', function () {
    [$site, $client] = demoUnavailablePortalSite();

    $this->actingAs($client)
        ->get(route('client.portal.navigation', $site))
        ->assertOk()
        ->assertDontSee('Rebuild navigation?')
        ->assertDontSee('triggerLabel="Rebuild"');
});

it('nav rebuild flashes the demo-unavailable notice', function () {
    [$site, $client] = demoUnavailablePortalSite();

    Livewire::actingAs($client)
        ->test('nav-manager', ['siteId' => $site->id])
        ->call('rebuildNav')
        ->assertSee('Not available in this demo');
});

it('the pages portal page does not offer hero generation', function () {
    [$site, $client] = demoUnavailablePortalSite();

    $this->actingAs($client)
        ->get(route('client.portal.site', $site))
        ->assertOk()
        ->assertDontSee('Generate hero image');
});

it('page-manager hero regenerate flashes the demo-unavailable notice', function () {
    [$site, $client] = demoUnavailablePortalSite();

    Livewire::actingAs($client)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('regenerateHero', 'home')
        ->assertSee('Not available in this demo');
});

it('does not register the public chat-proxy route', function () {
    expect(app('router')->getRoutes()->getByName('chat.proxy'))->toBeNull();
});

it('the public page layout does not include the chat-proxy widget', function () {
    $page = file_get_contents(resource_path('views/site/page.blade.php'));
    $preview = file_get_contents(resource_path('views/preview/layout.blade.php'));

    expect($page)->not->toContain('site.partials.chatbot')
        ->and($page)->not->toContain('chat-proxy')
        ->and($preview)->not->toContain('preview.partials.chatbot')
        ->and($preview)->not->toContain('chat-proxy');
});

it('the portal has no Domain tab', function () {
    [$site, $client] = demoUnavailablePortalSite();

    $html = $this->actingAs($client)
        ->get(route('client.portal.site', $site))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('/sites/'.$site->id.'/domain')
        ->and(\Illuminate\Support\Facades\Route::has('client.portal.domain'))->toBeFalse();
});

it('the portal has no Personalise tab', function () {
    [$site, $client] = demoUnavailablePortalSite();

    $html = $this->actingAs($client)
        ->get(route('client.portal.site', $site))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('/sites/'.$site->id.'/personalise')
        ->and(\Illuminate\Support\Facades\Route::has('client.portal.personalise'))->toBeFalse();
});
