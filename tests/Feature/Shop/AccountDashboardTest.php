<?php

use App\Enums\Shop\OrderStatus;
use App\Models\Shop\Customer;
use App\Models\Shop\Order;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('logged-in customer sees only their own orders', function () {
    $site = Site::factory()->create(['custom_domain' => 'flowers.example', 'custom_domain_status' => 'active']);
    \App\Models\Shop\Product::factory()->published()->for($site)->create(); // the gate is 'is a shop', not 'resolves by host'
    $customer = Customer::create(['site_id' => $site->id, 'email' => 'me@x.com', 'email_verified_at' => now()]);
    $other = Customer::create(['site_id' => $site->id, 'email' => 'other@x.com', 'email_verified_at' => now()]);

    foreach ([$customer, $other] as $c) {
        Order::create([
            'site_id' => $site->id, 'number' => "O-{$c->id}",
            'email' => $c->email, 'name' => 'X', 'customer_id' => $c->id,
            'status' => OrderStatus::Paid->value, 'refund_status' => 'none',
            'subtotal_cents' => 100, 'shipping_cents' => 0, 'tax_cents' => 0,
            'shipping_tax_cents' => 0, 'total_cents' => 100,
            'tax_country_code' => 'GB', 'shipping_address_json' => [],
            'shipping_method_label' => 'Std', 'placed_at' => now(),
        ]);
    }

    auth('customer')->login($customer);

    $this->get('http://flowers.example/shop/account/orders')
        ->assertOk()
        ->assertSee("O-{$customer->id}")
        ->assertDontSee("O-{$other->id}");
});
