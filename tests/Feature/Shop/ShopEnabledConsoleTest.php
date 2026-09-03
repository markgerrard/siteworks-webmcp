<?php

use App\Enums\AgentRole;
use App\Enums\Shop\OrderStatus;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Shop\Order;
use App\Models\Site;
use App\Models\User;
use Livewire\Livewire;

/**
 * @return array{0: Site, 1: User}
 */
function shopFlagConsoleSite(AgentRole $role, bool $shopEnabled): array
{
    $actor = User::factory()->staff($role)->create();
    $site = Site::factory()
        ->{$shopEnabled ? 'shopEnabled' : 'shopDisabled'}()
        ->create([
            'created_by_user_id' => $actor->id,
            'preview_domain' => 'flag-console-'.uniqid(),
            'preview_brand' => 'a',
            'business_name' => 'Flag Console Site',
        ]);
    GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    Preview::factory()->for($site)->create(['slug' => 'flag-console-'.uniqid()]);

    return [$site, $actor];
}

it('404s the shop and orders sections when the flag is off', function () {
    [$site, $agent] = shopFlagConsoleSite(AgentRole::Agent, shopEnabled: false);

    $this->actingAs($agent)
        ->get(route('sites.shop.products', $site))
        ->assertNotFound();
    $this->actingAs($agent)
        ->get(route('sites.shop.orders', $site))
        ->assertNotFound();
});

it('still serves shop and orders sections when the flag is on', function () {
    [$site, $agent] = shopFlagConsoleSite(AgentRole::Agent, shopEnabled: true);

    $this->actingAs($agent)
        ->get(route('sites.shop.products', $site))
        ->assertOk();
    $this->actingAs($agent)
        ->get(route('sites.shop.orders', $site))
        ->assertOk();
});

it('hides Shop and Orders sidebar items when the flag is off', function () {
    [$site, $agent] = shopFlagConsoleSite(AgentRole::Agent, shopEnabled: false);

    $html = $this->actingAs($agent)
        ->get(route('sites.section', ['site' => $site, 'section' => 'pages']))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain(route('sites.shop.products', $site))
        ->and($html)->not->toContain(route('sites.shop.orders', $site));
});

it('shows Shop and Orders sidebar items when the flag is on', function () {
    [$site, $agent] = shopFlagConsoleSite(AgentRole::Agent, shopEnabled: true);

    $html = $this->actingAs($agent)
        ->get(route('sites.section', ['site' => $site, 'section' => 'pages']))
        ->assertOk()
        ->getContent();

    expect($html)->toContain(route('sites.shop.products', $site))
        ->and($html)->toContain(route('sites.shop.orders', $site));
});

it('settings mounts the enable-shop toggle', function () {
    [$site, $admin] = shopFlagConsoleSite(AgentRole::Admin, shopEnabled: false);

    $this->actingAs($admin)
        ->get(route('sites.section', ['site' => $site, 'section' => 'settings']))
        ->assertOk()
        ->assertSeeLivewire('site-shop-enabled');
});

it('lets an admin enable and disable the shop flag', function () {
    [$site, $admin] = shopFlagConsoleSite(AgentRole::Admin, shopEnabled: false);

    Livewire::actingAs($admin)
        ->test('site-shop-enabled', ['siteId' => $site->id])
        ->call('toggle');

    expect($site->refresh()->shopEnabled())->toBeTrue();

    Livewire::actingAs($admin)
        ->test('site-shop-enabled', ['siteId' => $site->id])
        ->call('toggle');

    expect($site->refresh()->shopEnabled())->toBeFalse();
});

it('lets a manager flip the shop flag', function () {
    [$site, $manager] = shopFlagConsoleSite(AgentRole::Manager, shopEnabled: false);

    Livewire::actingAs($manager)
        ->test('site-shop-enabled', ['siteId' => $site->id])
        ->call('toggle');

    expect($site->refresh()->shopEnabled())->toBeTrue();
});

it('does not let an agent flip the shop flag', function () {
    [$site, $agent] = shopFlagConsoleSite(AgentRole::Agent, shopEnabled: false);

    Livewire::actingAs($agent)
        ->test('site-shop-enabled', ['siteId' => $site->id])
        ->call('toggle')
        ->assertForbidden();

    expect($site->refresh()->shopEnabled())->toBeFalse();
});

it('refuses to disable the shop while pending unexpired orders exist', function () {
    [$site, $admin] = shopFlagConsoleSite(AgentRole::Admin, shopEnabled: true);
    Order::create([
        'site_id' => $site->id,
        'number' => 'PEND-0001',
        'email' => 'buyer@example.com',
        'name' => 'Buyer',
        'status' => OrderStatus::Pending->value,
        'refund_status' => 'none',
        'subtotal_cents' => 900,
        'shipping_cents' => 0,
        'tax_cents' => 0,
        'shipping_tax_cents' => 0,
        'total_cents' => 900,
        'tax_country_code' => 'GB',
        'shipping_address_json' => [],
        'shipping_method_label' => 'Std',
        'placed_at' => now(),
        'expires_at' => now()->addHour(),
    ]);

    Livewire::actingAs($admin)
        ->test('site-shop-enabled', ['siteId' => $site->id])
        ->call('toggle')
        ->assertHasErrors(['enabled' => 'Cannot disable the shop while 1 pending order(s) are unpaid.']);

    expect($site->refresh()->shopEnabled())->toBeTrue();
});
