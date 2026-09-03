<?php

use App\Models\Site;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// SESSION_DOMAIN is .example.test so the agents/app/editor-preview surfaces can
// share an admin session. The storefront is served from other apexes entirely, and
// a browser rejects a cookie whose Domain is not a suffix of the request host, so a
// session cookie scoped to `.example.test` would never be stored on a storefront
// host: the cart could not persist and every CSRF-protected shop POST would fail.
test('a storefront host gets a host-only session cookie (no Domain attribute)', function () {
    config(['session.domain' => '.example.test']);

    $site = Site::factory()->create([
        'custom_domain' => 'flowers.example',
        'custom_domain_status' => 'active',
    ]);

    $response = $this->get('http://flowers.example/shop');

    $cookies = collect($response->headers->getCookies());

    expect($cookies)->not->toBeEmpty();

    // EVERY cookie on a storefront host must be host-only, or the browser drops it.
    foreach ($cookies as $c) {
        expect($c->getDomain())->toBeNull();
    }
});

test('a SiteWorks surface keeps its shared cross-subdomain session cookie', function () {
    // phpunit.xml force-pins the surface domains, so use those rather than the
    // deployment hostnames; the branch under test is "host is under session.domain".
    config(['session.domain' => '.domain.com']);

    $response = $this->get('http://agents.domain.com/login');

    $cookies = collect($response->headers->getCookies());
    expect($cookies)->not->toBeEmpty('no cookies were set on the agents surface');

    // The shared admin session must KEEP its Domain, or agents/app/editor-preview stop
    // sharing a session — which is the whole reason SESSION_DOMAIN is set.
    foreach ($cookies as $c) {
        expect($c->getDomain())->toBe('.domain.com');
    }
});
