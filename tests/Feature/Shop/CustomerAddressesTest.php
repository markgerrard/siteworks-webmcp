<?php

use App\Models\Shop\Customer;
use App\Models\Shop\CustomerAddress;
use App\Models\Shop\Product;
use App\Models\Site;
use App\Services\Shop\CustomerAddressService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{site: Site, customer: Customer, host: string}
 */
function addressBookShop(string $host = 'flowers.example'): array
{
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
    ]);
    Product::factory()->published()->for($site)->create();
    $customer = Customer::create([
        'site_id' => $site->id,
        'email' => 'ava@'.$host,
        'email_verified_at' => now(),
        'name' => 'Ava O\'Neil',
    ]);

    return compact('site', 'customer', 'host');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function addressBookPayload(array $overrides = []): array
{
    return array_merge([
        'label' => 'Home',
        'name' => 'Ava O\'Neil',
        'phone' => '01234 567890',
        'line1' => '14 Rose Lane',
        'line2' => 'Flat 2',
        'city' => 'Lancaster',
        'region' => 'Lancashire',
        'postcode' => 'LA1 1AA',
        'country_code' => 'GB',
    ], $overrides);
}

function addressBookLogin(Customer $customer): Customer
{
    auth('customer')->login($customer);

    return $customer;
}

test('the account dashboard links to Addresses', function () {
    ['customer' => $customer, 'host' => $host] = addressBookShop();
    addressBookLogin($customer);

    $this->get("http://{$host}/shop/account")
        ->assertOk()
        ->assertSee('Addresses', false)
        ->assertSee('/shop/account/addresses', false);
});

test('the addresses page lists existing addresses and an add form', function () {
    ['customer' => $customer, 'host' => $host] = addressBookShop();
    addressBookLogin($customer);

    app(CustomerAddressService::class)->create($customer, addressBookPayload([
        'label' => 'Studio',
        'line1' => '9 Market Street',
    ]));

    $html = $this->get("http://{$host}/shop/account/addresses")
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Studio')
        ->and($html)->toContain('9 Market Street')
        ->and($html)->toMatch('/<form\b[^>]*action="[^"]*\/shop\/account\/addresses"[^>]*>/i')
        ->and($html)->toMatch('/name="line1"/')
        ->and($html)->toMatch('/name="postcode"/')
        ->and($html)->toMatch('/name="country_code"/');
});

test('a signed-in customer can create an address', function () {
    ['customer' => $customer, 'host' => $host] = addressBookShop();
    addressBookLogin($customer);

    $this->post("http://{$host}/shop/account/addresses", addressBookPayload())
        ->assertRedirect();

    $address = CustomerAddress::query()->sole();
    expect($address->customer_id)->toBe($customer->id)
        ->and($address->site_id)->toBe($customer->site_id)
        ->and($address->line1)->toBe('14 Rose Lane')
        ->and($address->postcode)->toBe('LA1 1AA')
        ->and($address->country_code)->toBe('GB');
});

test('a signed-in customer can update an address', function () {
    ['customer' => $customer, 'host' => $host] = addressBookShop();
    addressBookLogin($customer);
    $address = app(CustomerAddressService::class)->create($customer, addressBookPayload());

    $this->post("http://{$host}/shop/account/addresses/{$address->id}", addressBookPayload([
        'line1' => '22 King Street',
        'postcode' => 'LA1 2BB',
    ]))->assertRedirect();

    expect($address->fresh()->line1)->toBe('22 King Street')
        ->and($address->fresh()->postcode)->toBe('LA1 2BB');
});

test('a signed-in customer can delete an address', function () {
    ['customer' => $customer, 'host' => $host] = addressBookShop();
    addressBookLogin($customer);
    $address = app(CustomerAddressService::class)->create($customer, addressBookPayload());

    $this->post("http://{$host}/shop/account/addresses/{$address->id}/delete")
        ->assertRedirect();

    expect(CustomerAddress::query()->count())->toBe(0);
});

test('exactly one default shipping address is kept per customer', function () {
    ['customer' => $customer] = addressBookShop();
    $svc = app(CustomerAddressService::class);

    $first = $svc->create($customer, addressBookPayload(['label' => 'A', 'line1' => '1 A Street']));
    $svc->setDefault($first, 'shipping');
    $second = $svc->create($customer, addressBookPayload(['label' => 'B', 'line1' => '2 B Street']));
    $svc->setDefault($second, 'shipping');

    expect($first->fresh()->is_default_shipping)->toBeFalse()
        ->and($second->fresh()->is_default_shipping)->toBeTrue()
        ->and(CustomerAddress::query()->where('customer_id', $customer->id)->where('is_default_shipping', true)->count())->toBe(1);
});

test('exactly one default billing address is kept per customer', function () {
    ['customer' => $customer, 'host' => $host] = addressBookShop();
    addressBookLogin($customer);
    $svc = app(CustomerAddressService::class);
    $first = $svc->create($customer, addressBookPayload(['label' => 'A', 'line1' => '1 A Street']));
    $second = $svc->create($customer, addressBookPayload(['label' => 'B', 'line1' => '2 B Street']));

    $this->post("http://{$host}/shop/account/addresses/{$first->id}/default/billing")->assertRedirect();
    $this->post("http://{$host}/shop/account/addresses/{$second->id}/default/billing")->assertRedirect();

    expect($first->fresh()->is_default_billing)->toBeFalse()
        ->and($second->fresh()->is_default_billing)->toBeTrue()
        ->and(CustomerAddress::query()->where('is_default_billing', true)->count())->toBe(1);
});

test('deleting the default shipping address promotes the most recently updated remaining address', function () {
    ['customer' => $customer] = addressBookShop();
    $svc = app(CustomerAddressService::class);

    $older = $svc->create($customer, addressBookPayload(['label' => 'Older', 'line1' => '1 Old Street']));
    $newer = $svc->create($customer, addressBookPayload(['label' => 'Newer', 'line1' => '2 New Street']));
    $svc->setDefault($older, 'shipping');

    $newer->update(['city' => 'Preston']);
    expect($newer->fresh()->updated_at->gte($older->fresh()->updated_at))->toBeTrue();

    $svc->delete($older);

    expect(CustomerAddress::query()->find($older->id))->toBeNull()
        ->and($newer->fresh()->is_default_shipping)->toBeTrue();
});

test('foreign address ids 404 rather than 403 for another customer on the same site', function () {
    ['site' => $site, 'customer' => $owner, 'host' => $host] = addressBookShop();
    $stranger = Customer::create([
        'site_id' => $site->id,
        'email' => 'stranger@'.$host,
        'email_verified_at' => now(),
    ]);
    $address = app(CustomerAddressService::class)->create($owner, addressBookPayload());
    addressBookLogin($stranger);

    $this->post("http://{$host}/shop/account/addresses/{$address->id}", addressBookPayload(['line1' => 'Hijacked']))
        ->assertNotFound();
    $this->post("http://{$host}/shop/account/addresses/{$address->id}/delete")
        ->assertNotFound();
    $this->post("http://{$host}/shop/account/addresses/{$address->id}/default/shipping")
        ->assertNotFound();

    expect($address->fresh()->line1)->toBe('14 Rose Lane');
});

test('foreign address ids 404 across sites even when the email matches', function () {
    ['customer' => $customerA, 'host' => $hostA] = addressBookShop('site-a.example');
    ['customer' => $customerB, 'host' => $hostB] = addressBookShop('site-b.example');
    $customerB->update(['email' => $customerA->email]);

    $addressB = app(CustomerAddressService::class)->create($customerB, addressBookPayload([
        'line1' => 'Site B Lane',
    ]));
    addressBookLogin($customerA);

    $this->post("http://{$hostA}/shop/account/addresses/{$addressB->id}", addressBookPayload())
        ->assertNotFound();
    $this->post("http://{$hostA}/shop/account/addresses/{$addressB->id}/delete")
        ->assertNotFound();
    $this->get("http://{$hostA}/shop/account/addresses")
        ->assertOk()
        ->assertDontSee('Site B Lane');

    expect($addressB->fresh()->line1)->toBe('Site B Lane');
    expect($hostB)->not->toBe($hostA);
});

test('country_code must be size 2 and postcode must be at most 16 characters', function () {
    ['customer' => $customer, 'host' => $host] = addressBookShop();
    addressBookLogin($customer);

    $this->from("http://{$host}/shop/account/addresses")
        ->post("http://{$host}/shop/account/addresses", addressBookPayload([
            'country_code' => 'GBR',
            'postcode' => '12345678901234567',
        ]))
        ->assertRedirect("http://{$host}/shop/account/addresses")
        ->assertSessionHasErrors(['country_code', 'postcode']);

    expect(CustomerAddress::query()->count())->toBe(0);
});

test('address POST routes are throttled per customer, not per site', function () {
    ['customer' => $customer, 'host' => $host, 'site' => $site] = addressBookShop();
    addressBookLogin($customer);

    $statuses = [];
    for ($i = 0; $i < 32; $i++) {
        $statuses[] = $this->post("http://{$host}/shop/account/addresses", addressBookPayload([
            'label' => 'Addr '.$i,
            'line1' => $i.' Rose Lane',
        ]))->getStatusCode();
    }
    expect($statuses)->toContain(429);

    // A second customer on the SAME site is unaffected by the first customer's bucket (review).
    $other = Customer::create(['site_id' => $site->id, 'email' => 'other@'.$host, 'email_verified_at' => now(), 'name' => 'Other Customer']);
    addressBookLogin($other);
    $status = $this->post("http://{$host}/shop/account/addresses", addressBookPayload([
        'label' => 'Other',
        'line1' => '1 Other Street',
    ]))->getStatusCode();
    expect($status)->not->toBe(429);
});
