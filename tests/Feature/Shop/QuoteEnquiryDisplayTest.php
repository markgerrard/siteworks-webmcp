<?php

use App\Enums\AgentRole;
use App\Models\Client;
use App\Models\Shop\Customer;
use App\Models\Shop\Product;
use App\Models\Site;
use App\Models\SiteEnquiry;
use App\Models\User;
use Livewire\Livewire;

function quoteEnquiryDisplayRow(Site $site, array $overrides = []): SiteEnquiry
{
    return SiteEnquiry::create(array_merge([
        'site_id' => $site->id,
        'name' => 'Ava O\'Neil',
        'email' => 'ava@quote.example',
        'payload' => [
            'kind' => 'quote',
            'phone' => '01234 567890',
            'needed_by' => '2026-09-06',
            'message' => 'Saturday pickup.',
            'lines' => [
                [
                    'product_id' => 11,
                    'product_slug' => 'victoria',
                    'name' => 'Victoria Sponge',
                    'variant_id' => 21,
                    'variant_label' => 'Small',
                    'qty' => 3,
                    'unit_price_cents' => 1850,
                    'currency' => 'GBP',
                ],
            ],
        ],
        'field_labels' => [
            'kind' => 'Kind',
            'phone' => 'Phone',
            'needed_by' => 'When do you need it?',
            'message' => 'Message',
            'lines' => 'Items',
        ],
        'status' => 'new',
    ], $overrides));
}

test('agents and client-portal enquiry inboxes render quote lines', function () {
    $staff = User::factory()->staff(AgentRole::Admin)->create();
    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create([
        'created_by_user_id' => $staff->id,
        'client_id' => $tenant->id,
        'shop_mode' => 'quote',
    ]);
    quoteEnquiryDisplayRow($site);

    Livewire::actingAs($staff)
        ->test('enquiries-inbox', ['siteId' => $site->id])
        ->assertSee('Victoria Sponge')
        ->assertSee('Small')
        ->assertSee('× 3')
        ->assertSee('£18.50');

    Livewire::actingAs($client)
        ->test('client.enquiries-inbox', ['siteId' => $site->id])
        ->assertSee('Victoria Sponge')
        ->assertSee('Small')
        ->assertSee('× 3')
        ->assertSee('£18.50');
});

test('customer account enquiries show quote lines', function () {
    $site = Site::factory()->create([
        'custom_domain' => 'quote-acct-lines.example',
        'custom_domain_status' => 'active',
        'shop_mode' => 'quote',
    ]);
    Product::factory()->published()->for($site)->create();
    $customer = Customer::create([
        'site_id' => $site->id,
        'email' => 'ava@quote-acct-lines.example',
        'email_verified_at' => now(),
        'name' => 'Ava O\'Neil',
    ]);
    quoteEnquiryDisplayRow($site, [
        'email' => $customer->email,
        'customer_id' => $customer->id,
    ]);
    auth('customer')->login($customer);

    $html = test()->get('http://quote-acct-lines.example/shop/account/enquiries')
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Victoria Sponge')
        ->and($html)->toContain('Small')
        ->and($html)->toContain('× 3')
        ->and($html)->toContain('£18.50');
});

test('the owner mail renders quote lines as a readable list', function () {
    $site = Site::factory()->create(['business_name' => 'Quote Bakery']);
    $enquiry = quoteEnquiryDisplayRow($site);
    $enquiry->setRelation('site', $site);

    $html = view('mail.site-enquiry-received', ['enquiry' => $enquiry])->render();

    expect($html)->toContain('Victoria Sponge')
        ->and($html)->toContain('Small')
        ->and($html)->toContain('× 3')
        ->and($html)->toContain('£18.50')
        ->and($html)->not->toContain('unit_price_cents');
});
