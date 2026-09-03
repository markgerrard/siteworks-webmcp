<?php

use App\Enums\Shop\OrderStatus;
use App\Jobs\Shop\AttachExistingOrdersToCustomer;
use App\Models\Shop\Customer;
use App\Models\Shop\Order;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('retrofit job links historic orders by email', function () {
    $site = Site::factory()->create();
    Order::create([
        'site_id' => $site->id, 'number' => 'H-1',
        'email' => 'match@example.com', 'name' => 'M',
        'status' => OrderStatus::Paid->value, 'refund_status' => 'none',
        'subtotal_cents' => 100, 'shipping_cents' => 0, 'tax_cents' => 0,
        'shipping_tax_cents' => 0, 'total_cents' => 100,
        'tax_country_code' => 'GB', 'shipping_address_json' => [],
        'shipping_method_label' => 'Std', 'placed_at' => now(),
    ]);
    Order::create([
        'site_id' => $site->id, 'number' => 'H-2',
        'email' => 'nomatch@example.com', 'name' => 'N',
        'status' => OrderStatus::Paid->value, 'refund_status' => 'none',
        'subtotal_cents' => 100, 'shipping_cents' => 0, 'tax_cents' => 0,
        'shipping_tax_cents' => 0, 'total_cents' => 100,
        'tax_country_code' => 'GB', 'shipping_address_json' => [],
        'shipping_method_label' => 'Std', 'placed_at' => now(),
    ]);

    $customer = Customer::create([
        'site_id' => $site->id,
        'email' => 'match@example.com',
        'email_verified_at' => now(),
    ]);

    (new AttachExistingOrdersToCustomer($customer->id))->handle();

    expect(Order::where('email', 'match@example.com')->first()->customer_id)->toBe($customer->id);
    expect(Order::where('email', 'nomatch@example.com')->first()->customer_id)->toBeNull();
});

test('retrofit job attaches a guest order when the emails differ only by case', function () {
    $site = Site::factory()->create();
    $order = Order::create([
        'site_id' => $site->id, 'number' => 'H-CASE',
        'email' => 'Jane@x.com', 'name' => 'Jane',
        'status' => OrderStatus::Paid->value, 'refund_status' => 'none',
        'subtotal_cents' => 100, 'shipping_cents' => 0, 'tax_cents' => 0,
        'shipping_tax_cents' => 0, 'total_cents' => 100,
        'tax_country_code' => 'GB', 'shipping_address_json' => [],
        'shipping_method_label' => 'Std', 'placed_at' => now(),
    ]);
    $customer = Customer::create([
        'site_id' => $site->id,
        'email' => 'jane@x.com',
        'email_verified_at' => now(),
    ]);

    (new AttachExistingOrdersToCustomer($customer->id))->handle();

    expect($order->fresh()->customer_id)->toBe($customer->id);
});

test('retrofit job attaches nothing when the customer email is unverified', function () {
    $site = Site::factory()->create();
    $order = Order::create([
        'site_id' => $site->id, 'number' => 'H-UNVER',
        'email' => 'jane@x.com', 'name' => 'Jane',
        'status' => OrderStatus::Paid->value, 'refund_status' => 'none',
        'subtotal_cents' => 100, 'shipping_cents' => 0, 'tax_cents' => 0,
        'shipping_tax_cents' => 0, 'total_cents' => 100,
        'tax_country_code' => 'GB', 'shipping_address_json' => [],
        'shipping_method_label' => 'Std', 'placed_at' => now(),
    ]);
    $customer = Customer::create([
        'site_id' => $site->id,
        'email' => 'jane@x.com',
        'email_verified_at' => null,
    ]);

    (new AttachExistingOrdersToCustomer($customer->id))->handle();

    expect($order->fresh()->customer_id)->toBeNull();
});
