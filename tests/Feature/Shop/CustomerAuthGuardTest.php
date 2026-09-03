<?php

use App\Models\Shop\Customer;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('customer guard can auth a customer instance', function () {
    $site = Site::factory()->create();
    $customer = Customer::create([
        'site_id' => $site->id,
        'email' => 'test@example.com',
        'email_verified_at' => now(),
    ]);

    auth('customer')->login($customer);
    expect(auth('customer')->user()?->id)->toBe($customer->id);
});
