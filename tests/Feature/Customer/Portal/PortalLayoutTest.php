<?php

use App\Models\Client;
use App\Models\Shop\Product;
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
        'business_name' => "O'Keefe & Sons",
    ]);
});

test('canonical site page renders the client layout sidebar', function () {
    // Overview was removed — /sites/{site} now serves Pages
    // directly and the sidebar's first item is Pages, not Overview.
    $this->actingAs($this->user)
        ->get("https://{$this->customerHost}/sites/{$this->site->id}")
        ->assertOk()
        ->assertSee($this->site->business_name)
        ->assertSee('Pages')
        ->assertSee('Design')
        ->assertSee('Navigation')
        ->assertSee('Personalise')
        ->assertSee('Chatbot')
        ->assertSee('History')
        ->assertSee('Business Info')
        ->assertSee('Domain')
        ->assertSee('Team')
        ->assertDontSee('Overview');
});

test('sidebar renders the active site in the header slot', function () {
    $response = $this->actingAs($this->user)
        ->get("https://{$this->customerHost}/sites/{$this->site->id}");

    $response->assertOk();
    // Site switcher header — name appears at the top of the sidebar above the section list.
    expect($response->getContent())->toContain(e($this->site->business_name));
});

test('client layout head script pins nav group keys from localStorage before first paint', function () {
    $html = $this->actingAs($this->user)
        ->get("https://{$this->customerHost}/sites/{$this->site->id}")
        ->assertOk()
        ->getContent();

    $script = clientPortalHeadPinScript($html);

    expect($script)->toContain("['content', 'shop']")
        ->and($script)->toContain('siteworks.nav.')
        ->and($script)->toContain('nav-closed-')
        ->and($script)->toContain('nav-open-')
        ->and($script)->toContain("localStorage.getItem('siteworks.client.sidebar.mini')");
});

test('current Content group panel is not gated by nav-closed', function () {
    Product::factory()->for($this->site)->published()->create();

    $html = $this->actingAs($this->user)
        ->get("https://{$this->customerHost}/sites/{$this->site->id}")
        ->assertOk()
        ->getContent();

    $content = clientPortalNavGroup($html, 'content');
    $panel = clientPortalNavGroupPanel($content);

    expect($content)->toContain('data-nav-current="1"')
        ->and($panel)->not->toContain('[html.nav-closed-content_&]:hidden')
        ->and($panel)->not->toContain('x-cloak');
});

test('non-current Shop group panel is CSS-gated by html.nav-closed-shop', function () {
    Product::factory()->for($this->site)->published()->create();

    $html = $this->actingAs($this->user)
        ->get("https://{$this->customerHost}/sites/{$this->site->id}")
        ->assertOk()
        ->getContent();

    $shop = clientPortalNavGroup($html, 'shop');
    $panel = clientPortalNavGroupPanel($shop);

    expect($shop)->toContain('data-nav-current="0"')
        ->and($panel)->toContain('[html.nav-closed-shop_&]:hidden')
        ->and($panel)->not->toContain('x-cloak');
});

test('current Shop group panel is not gated by nav-closed', function () {
    Product::factory()->for($this->site)->published()->create();

    $html = $this->actingAs($this->user)
        ->get("https://{$this->customerHost}/sites/{$this->site->id}/products")
        ->assertOk()
        ->getContent();

    $shop = clientPortalNavGroup($html, 'shop');
    $content = clientPortalNavGroup($html, 'content');

    expect($shop)->toContain('data-nav-current="1"')
        ->and(clientPortalNavGroupPanel($shop))->not->toContain('[html.nav-closed-shop_&]:hidden')
        ->and($content)->toContain('data-nav-current="0"')
        ->and(clientPortalNavGroupPanel($content))->toContain('[html.nav-closed-content_&]:hidden');
});

test('nav group markup does not read localStorage after hydration', function () {
    Product::factory()->for($this->site)->published()->create();

    $html = $this->actingAs($this->user)
        ->get("https://{$this->customerHost}/sites/{$this->site->id}")
        ->assertOk()
        ->getContent();

    $content = clientPortalNavGroup($html, 'content');
    $shop = clientPortalNavGroup($html, 'shop');

    foreach ([$content, $shop] as $group) {
        expect($group)->not->toContain('localStorage.getItem(key)')
            ->and($group)->not->toContain('localStorage.getItem(')
            ->and($group)->toContain('classList.contains')
            ->and($group)->toContain('nav-closed-')
            ->and($group)->toContain('nav-open-')
            ->and($group)->toContain('localStorage.setItem');
    }
});

/**
 * The synchronous <head> pin that stamps sidebar-mini (and, after T60, nav-group
 * open/closed classes) before first paint.
 */
function clientPortalHeadPinScript(string $html): string
{
    preg_match('/<script\b[^>]*>.*?siteworks\.client\.sidebar\.mini.*?<\/script>/s', $html, $matches);

    expect($matches[0] ?? '')->not->toBeEmpty('missing client layout head pin script');

    return $matches[0];
}

/**
 * Slice one client-portal sidebar nav group from the rendered page.
 */
function clientPortalNavGroup(string $html, string $key): string
{
    $needle = 'data-nav-persist="siteworks.nav.'.$key.'"';
    $attr = strpos($html, $needle);
    expect($attr)->not->toBeFalse("missing nav group {$key}");

    $divStart = strrpos(substr($html, 0, $attr), '<div');
    expect($divStart)->not->toBeFalse("missing opening div for nav group {$key}");

    $searchFrom = $attr + strlen($needle);
    $nextGroup = strpos($html, 'data-nav-persist="siteworks.nav.', $searchFrom);
    $restNav = strpos($html, '<ul class="space-y-0.5">', $searchFrom);

    $end = strlen($html);
    if ($nextGroup !== false) {
        $end = min($end, $nextGroup);
    }
    if ($restNav !== false) {
        $end = min($end, $restNav);
    }

    return substr($html, $divStart, $end - $divStart);
}

function clientPortalNavGroupPanel(string $group): string
{
    preg_match('/<ul\b[^>]*>/', $group, $matches);

    expect($matches[0] ?? '')->not->toBeEmpty('missing nav group panel ul');

    return $matches[0];
}
