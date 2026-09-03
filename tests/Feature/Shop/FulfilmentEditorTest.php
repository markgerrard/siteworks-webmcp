<?php

use App\Models\Client;
use App\Models\Shop\Product;
use App\Models\Site;
use App\Models\User;
use App\Services\Shop\Fulfilment\FulfilmentConfig;
use App\Services\Site\PublicPageCache;
use Livewire\Livewire;
use Tests\Support\FulfilmentFixtures;

test('storefront fulfilment editor saves zones, collect and widget and busts the public cache', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    config(['site.public_cache_enabled' => true]);
    $before = app(PublicPageCache::class)->generation($site);

    Livewire::test('shop.fulfilment-editor', ['siteId' => $site->id])
        ->set('deliveryEnabled', true)
        ->set('deliveryLabel', 'Local delivery')
        ->set('zones', [
            [
                'name' => 'Inner',
                'prefixesText' => 'sw1a, SW1',
                'fee_cents' => 400,
                'free_over_cents' => 4000,
                'lead_time' => 'next day',
                'min_order_cents' => '',
            ],
        ])
        ->set('collectEnabled', true)
        ->set('collectAddress', '12 High Street')
        ->set('collectLeadTime', 'same day')
        ->set('widgetPrompt', 'Check delivery to your postcode')
        ->call('save')
        ->assertHasNoErrors();

    $site->refresh();
    $config = FulfilmentConfig::fromSite($site);

    expect($config)->not->toBeNull()
        ->and($config->methodEnabled('delivery'))->toBeTrue()
        ->and($config->zones()[0]['prefixes'])->toBe(['SW1A', 'SW1'])
        ->and($config->collectAddress())->toBe('12 High Street')
        ->and(app(PublicPageCache::class)->generation($site))->toBeGreaterThan($before);
});

test('fulfilment editor rejects duplicate prefixes and negative fees', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test('shop.fulfilment-editor', ['siteId' => $site->id])
        ->set('deliveryEnabled', true)
        ->set('zones', [
            ['name' => 'A', 'prefixesText' => 'SW1', 'fee_cents' => 0, 'free_over_cents' => '', 'lead_time' => '', 'min_order_cents' => ''],
            ['name' => 'B', 'prefixesText' => 'SW1', 'fee_cents' => -5, 'free_over_cents' => '', 'lead_time' => '', 'min_order_cents' => ''],
        ])
        ->call('save')
        ->assertHasErrors(['delivery.zones.1.prefixes', 'delivery.zones.1.fee_cents']);

    expect($site->fresh()->fulfilment)->toBeNull();
});

test('fulfilment editor rejects a zone name longer than 80 characters', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test('shop.fulfilment-editor', ['siteId' => $site->id])
        ->set('deliveryEnabled', true)
        ->set('zones', [
            [
                'name' => str_repeat('Z', 120),
                'prefixesText' => 'SW1',
                'fee_cents' => 0,
                'free_over_cents' => '',
                'lead_time' => '',
                'min_order_cents' => '',
            ],
        ])
        ->call('save')
        ->assertHasErrors(['delivery.zones.0.name']);

    expect($site->fresh()->fulfilment)->toBeNull();
});

test('agents storefront and the client storefront page mount the fulfilment editor', function () {
    $this->withoutVite();
    $agent = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    Product::factory()->published()->for($site)->create();

    $this->actingAs($agent)
        ->get(route('sites.shop.storefront', $site))
        ->assertOk()
        ->assertSeeLivewire('shop.fulfilment-editor');

    $tenant = Client::factory()->create();
    $client = User::factory()->create(['client_id' => $tenant->id, 'role' => null, 'last_login_at' => now()]);
    $owned = Site::factory()->create(['client_id' => $tenant->id]);
    Product::factory()->published()->for($owned)->create();

    $this->actingAs($client)
        ->get(route('client.portal.shop.storefront', $owned))
        ->assertOk()
        ->assertSeeLivewire('shop.fulfilment-editor');
});

test('a saved camino fixture round-trips through the editor', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $user->id,
        'fulfilment' => FulfilmentFixtures::camino(),
    ]);
    $this->actingAs($user);

    $html = Livewire::test('shop.fulfilment-editor', ['siteId' => $site->id])->html();

    expect($html)->toContain('SW1A')
        ->and($html)->toContain('12 High Street');
});
