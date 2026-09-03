<?php

use App\Models\Site;
use App\Models\User;

/**
 * The demo bakery ships a starter catalogue, so the portal shop pages are
 * reachable because the shop is established — not because of the empty-shop
 * demo exception.
 */
beforeEach(function () {
    config()->set('demo.enabled', true);
    config()->set('demo.site_host', 'localhost');
    config()->set('demo.user_email', 'demo@camino.example');
    config()->set('demo.user_password', 'webmcp-demo');
    config()->set('app.url', 'http://app.localhost:8090');
    $this->artisan('demo:seed')->assertSuccessful();
    $this->site = Site::query()->findOrFail(64);
    $this->user = User::query()->where('email', 'demo@camino.example')->firstOrFail();
});

it('reaches the portal shop pages for the seeded demo catalogue', function () {
    expect($this->site->hasEstablishedShop())->toBeTrue()
        ->and($this->site->portalShopReachable())->toBeTrue();

    foreach (['client.portal.shop.products', 'client.portal.shop.categories', 'client.portal.shop.storefront'] as $name) {
        $this->actingAs($this->user)
            ->get(route($name, ['site' => $this->site]))
            ->assertOk();
    }
});

it('keeps the portal shop reachable outside demo mode once the catalogue is established', function () {
    config()->set('demo.enabled', false);

    expect($this->site->fresh()->portalShopReachable())->toBeTrue();
    $this->actingAs($this->user)
        ->get(route('client.portal.shop.products', ['site' => $this->site]))
        ->assertOk();
});
