<?php

use App\Models\Shop\Customer;
use App\Models\Shop\Product;
use App\Models\Site;
use App\Models\SiteEnquiry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{site: Site, customer: Customer, host: string}
 */
function accountEnquiriesShop(string $host, string $shopMode = 'enquire', bool $verified = true): array
{
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'shop_mode' => $shopMode,
    ]);
    Product::factory()->published()->for($site)->create();
    $customer = Customer::create([
        'site_id' => $site->id,
        'email' => 'ava@'.$host,
        'email_verified_at' => $verified ? now() : null,
        'name' => 'Ava O\'Neil',
    ]);

    return compact('site', 'customer', 'host');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function accountEnquiriesRow(Site $site, array $overrides = []): SiteEnquiry
{
    return SiteEnquiry::create(array_merge([
        'site_id' => $site->id,
        'name' => 'Ava O\'Neil',
        'email' => 'ava@'.$site->custom_domain,
        'payload' => [
            'product' => 'Strawberry Conserve',
            'message' => 'I would like a wedding cake.',
        ],
        'page_type' => 'contact',
        'status' => 'new',
    ], $overrides));
}

test('enquire-mode account dashboard lists matching enquiries and links to the enquiries page', function () {
    ['site' => $site, 'customer' => $customer, 'host' => $host] = accountEnquiriesShop('enquire-dash.example');
    accountEnquiriesRow($site, [
        'payload' => ['product' => 'Lemon Drizzle', 'message' => 'Do you deliver?'],
    ]);
    auth('customer')->login($customer);

    $html = $this->get("http://{$host}/shop/account")
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Lemon Drizzle')
        ->and($html)->toContain('Do you deliver?')
        ->and($html)->toMatch('/href="[^"]*\/shop\/account\/enquiries"/')
        ->and($html)->toContain('Enquiries');
});

test('enquire-mode enquiries page lists date, product, status and message newest first', function () {
    ['site' => $site, 'customer' => $customer, 'host' => $host] = accountEnquiriesShop('enquire-list.example');
    $older = accountEnquiriesRow($site, [
        'payload' => ['product' => 'Older Cake', 'message' => 'Older message'],
    ]);
    $newer = accountEnquiriesRow($site, [
        'payload' => ['product' => 'Newer Cake', 'message' => 'Newer message'],
        'status' => 'closed',
    ]);
    $older->forceFill(['created_at' => now()->subDay()])->save();
    $newer->forceFill(['created_at' => now()])->save();
    auth('customer')->login($customer);

    $html = $this->get("http://{$host}/shop/account/enquiries")
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Older Cake')
        ->and($html)->toContain('Older message')
        ->and($html)->toContain('Newer Cake')
        ->and($html)->toContain('Newer message')
        ->and($html)->toContain('new')
        ->and($html)->toContain('closed')
        ->and(strpos($html, 'Newer Cake'))->toBeLessThan(strpos($html, 'Older Cake'));

    expect($newer->created_at->gt($older->created_at))->toBeTrue();
});

test('enquiries matching is case-insensitive on verified email', function () {
    ['site' => $site, 'customer' => $customer, 'host' => $host] = accountEnquiriesShop('enquire-case.example');
    $customer->update(['email' => 'Ava@Enquire-Case.Example']);
    accountEnquiriesRow($site, [
        'email' => 'ava@enquire-case.example',
        'payload' => ['product' => 'Case Cake', 'message' => 'Case match'],
    ]);
    auth('customer')->login($customer->fresh());

    $this->get("http://{$host}/shop/account/enquiries")
        ->assertOk()
        ->assertSee('Case Cake', false)
        ->assertSee('Case match', false);
});

test('enquiries are site-scoped even when the email matches on another shop', function () {
    ['site' => $siteA, 'customer' => $customerA, 'host' => $hostA] = accountEnquiriesShop('enquire-a.example');
    ['site' => $siteB] = accountEnquiriesShop('enquire-b.example');
    accountEnquiriesRow($siteA, [
        'email' => $customerA->email,
        'payload' => ['product' => 'Site A Cake', 'message' => 'Mine'],
    ]);
    accountEnquiriesRow($siteB, [
        'email' => $customerA->email,
        'payload' => ['product' => 'Site B Cake', 'message' => 'Foreign'],
    ]);
    auth('customer')->login($customerA);

    $html = $this->get("http://{$hostA}/shop/account/enquiries")
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Site A Cake')
        ->and($html)->not->toContain('Site B Cake')
        ->and($html)->not->toContain('Foreign');
});

test('another customer on the same site cannot see someone else\'s enquiries', function () {
    ['site' => $site, 'customer' => $owner, 'host' => $host] = accountEnquiriesShop('enquire-idor.example');
    $stranger = Customer::create([
        'site_id' => $site->id,
        'email' => 'stranger@'.$host,
        'email_verified_at' => now(),
    ]);
    accountEnquiriesRow($site, [
        'email' => $owner->email,
        'payload' => ['product' => 'Owner Cake', 'message' => 'Private'],
    ]);
    auth('customer')->login($stranger);

    $html = $this->get("http://{$host}/shop/account/enquiries")
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('Owner Cake')
        ->and($html)->not->toContain('Private');
});

test('unverified customers do not see enquiries matched only by email', function () {
    ['site' => $site, 'customer' => $customer, 'host' => $host] = accountEnquiriesShop(
        'enquire-unverified.example',
        verified: false,
    );
    accountEnquiriesRow($site, [
        'email' => $customer->email,
        'payload' => ['product' => 'Unverified Cake', 'message' => 'Should stay hidden'],
    ]);
    auth('customer')->login($customer);

    $html = $this->get("http://{$host}/shop/account/enquiries")
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('Unverified Cake')
        ->and($html)->not->toContain('Should stay hidden')
        ->and($customer->email_verified_at)->toBeNull();
});

test('a customer_id match is preferred over the posted email', function () {
    ['site' => $site, 'customer' => $customer, 'host' => $host] = accountEnquiriesShop('enquire-id.example');
    accountEnquiriesRow($site, [
        'email' => 'someone-else@example.com',
        'customer_id' => $customer->id,
        'payload' => ['product' => 'Linked Cake', 'message' => 'Linked by id'],
    ]);
    auth('customer')->login($customer);

    $this->get("http://{$host}/shop/account/enquiries")
        ->assertOk()
        ->assertSee('Linked Cake', false)
        ->assertSee('Linked by id', false);
});

test('cart-mode enquiries page 404s and the dashboard has no enquiries nav', function () {
    ['customer' => $customer, 'host' => $host] = accountEnquiriesShop('cart-enq.example', shopMode: 'cart');
    auth('customer')->login($customer);

    $this->get("http://{$host}/shop/account/enquiries")->assertNotFound();

    $html = $this->get("http://{$host}/shop/account")->assertOk()->getContent();
    expect($html)->not->toMatch('/href="[^"]*\/shop\/account\/enquiries"/')
        ->and($html)->not->toContain('>Enquiries<');
});

test('a signed-in customer enquiry stores customer_id', function () {
    ['customer' => $customer, 'host' => $host] = accountEnquiriesShop('enquire-stamp.example');
    auth('customer')->login($customer);

    $this->postJson("http://{$host}/enquiries", [
        'name' => 'Ava O\'Neil',
        'email' => 'other@example.com',
        'message' => 'I would like a quote.',
        'product' => 'Strawberry Conserve',
        'page_type' => 'contact',
        'website' => '',
    ])->assertSuccessful();

    $enquiry = SiteEnquiry::query()->sole();
    expect($enquiry->customer_id)->toBe($customer->id)
        ->and($enquiry->payload['product'])->toBe('Strawberry Conserve')
        ->and($enquiry->email)->toBe('other@example.com');
});
