<?php

use App\Mail\Shop\CustomerMagicLinkMail;
use App\Models\Shop\Customer;
use App\Models\Site;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// The magic-link email built its URL from config('app.url'), which is the
// AGENTS surface. The shop is served from
// the site's own public host. The sign-in link therefore pointed at a host that does
// not serve that site's shop: it 404s, and the recipient has no way to correct it.
test('the magic-link email points at the site public host, not the agents domain', function () {
    config(['app.url' => 'https://agents.example.test']);

    $site = Site::factory()->create([
        'custom_domain' => 'flowers.example',
        'custom_domain_status' => 'active',
    ]);
    $customer = Customer::create(['site_id' => $site->id, 'email' => 'buyer@example.com', 'name' => 'Buyer']);

    $html = (new CustomerMagicLinkMail($customer, 'raw-token-123'))->render();

    // NOTE: toContain's extra args are further NEEDLES, not a failure message.
    expect($html)->toContain('https://flowers.example/shop/account/verify?token=raw-token-123');
    expect($html)->not->toContain('agents.example.test');
});

// A site with no active custom domain falls back to its branded preview host.
test('a site without a custom domain uses its preview host', function () {
    config(['app.url' => 'https://agents.example.test']);

    $site = Site::factory()->create([
        'custom_domain' => null,
        'preview_domain' => 'petals',
        'preview_brand' => 'a',
    ]);
    $customer = Customer::create(['site_id' => $site->id, 'email' => 'buyer@example.com', 'name' => 'Buyer']);

    $html = (new CustomerMagicLinkMail($customer, 'tok'))->render();

    expect($html)->toContain($site->publicHost().'/shop/account/verify?token=tok');
    expect($html)->not->toContain('agents.example.test');
});
