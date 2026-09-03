<?php

use App\Enums\Shop\OrderStatus;
use App\Models\Shop\Customer;
use App\Models\Shop\CustomerMagicLink;
use App\Models\Shop\Order;
use App\Models\Site;
use App\Services\Shop\CustomerAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('customer can request link and then consume it to log in', function () {
    Mail::fake();
    $site = Site::factory()->create(['custom_domain' => 'flowers.example', 'custom_domain_status' => 'active']);
    \App\Models\Shop\Product::factory()->published()->for($site)->create(); // the gate is 'is a shop', not 'resolves by host'

    $this->post('http://flowers.example/shop/account/login', ['email' => 'buyer@example.com'])
        ->assertRedirect();

    $customer = Customer::where('email', 'buyer@example.com')->first();
    expect($customer)->not->toBeNull();

    $link = CustomerMagicLink::where('customer_id', $customer->id)->first();
    $raw = Cache::get("magic_link_raw_{$link->id}");

    $this->get("http://flowers.example/shop/account/verify?token={$raw}")
        ->assertRedirect('/shop/account');

    expect(auth('customer')->check())->toBeTrue();
});

test('verifying a magic link queues the order attach exactly once and the job attaches the guest order', function () {
    Mail::fake();
    \Illuminate\Support\Facades\Queue::fake();
    $site = Site::factory()->create(['custom_domain' => 'flowers.example', 'custom_domain_status' => 'active']);
    \App\Models\Shop\Product::factory()->published()->for($site)->create();
    $order = Order::create([
        'site_id' => $site->id,
        'number' => 'H-1',
        'email' => 'buyer@example.com',
        'name' => 'Buyer',
        'status' => OrderStatus::Paid->value,
        'refund_status' => 'none',
        'subtotal_cents' => 100,
        'shipping_cents' => 0,
        'tax_cents' => 0,
        'shipping_tax_cents' => 0,
        'total_cents' => 100,
        'tax_country_code' => 'GB',
        'shipping_address_json' => [],
        'shipping_method_label' => 'Std',
        'placed_at' => now(),
    ]);

    $this->post('http://flowers.example/shop/account/login', ['email' => 'buyer@example.com'])->assertRedirect();
    $customer = Customer::where('email', 'buyer@example.com')->firstOrFail();
    $link = CustomerMagicLink::where('customer_id', $customer->id)->firstOrFail();
    $raw = Cache::get("magic_link_raw_{$link->id}");

    $this->get("http://flowers.example/shop/account/verify?token={$raw}")->assertRedirect('/shop/account');

    // The controller owns the (queued) attach; consumeLink must not run it inline as well.
    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\Shop\AttachExistingOrdersToCustomer::class, 1);
    expect($order->fresh()->customer_id)->toBeNull();

    (new \App\Jobs\Shop\AttachExistingOrdersToCustomer($customer->id))->handle();
    expect($order->fresh()->customer_id)->toBe($customer->id);
});

test('password login queues the guest-order attach exactly once', function () {
    Mail::fake();
    \Illuminate\Support\Facades\Queue::fake();
    $site = Site::factory()->create(['custom_domain' => 'flowers.example', 'custom_domain_status' => 'active']);
    \App\Models\Shop\Product::factory()->published()->for($site)->create();
    $svc = app(CustomerAuthService::class);
    $customer = $svc->requestLinkFor($site->id, 'buyer@example.com');
    $svc->setPassword($customer, 'correct horse battery staple');

    $this->post('http://flowers.example/shop/account/login', ['email' => 'buyer@example.com', 'password' => 'correct horse battery staple'])
        ->assertRedirect('/shop/account');

    expect(auth('customer')->check())->toBeTrue();
    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\Shop\AttachExistingOrdersToCustomer::class, 1);
});
