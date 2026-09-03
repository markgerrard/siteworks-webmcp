<?php

use App\Models\Shop\Customer;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('customer can set an optional password', function () {
    $site = Site::factory()->create();
    $customer = Customer::create(['site_id' => $site->id, 'email' => 'x@y.com', 'email_verified_at' => now()]);
    auth('customer')->login($customer);

    Livewire::test('shop.account-settings')
        ->set('newPassword', 'Secr3tPw!')
        ->call('savePassword');

    expect($customer->fresh()->password_hash)->not->toBeNull();
    expect(password_verify('Secr3tPw!', $customer->fresh()->password_hash))->toBeTrue();
});

test('marketing consent toggle persists', function () {
    $site = Site::factory()->create();
    $customer = Customer::create(['site_id' => $site->id, 'email' => 'x@y.com', 'email_verified_at' => now()]);
    auth('customer')->login($customer);

    Livewire::test('shop.account-settings')
        ->set('marketingConsent', true)
        ->call('saveConsent');

    expect($customer->fresh()->marketing_consent_at)->not->toBeNull();
});

test('account delete soft-deletes and blocks re-auth', function () {
    $site = Site::factory()->create();
    $customer = Customer::create(['site_id' => $site->id, 'email' => 'x@y.com', 'email_verified_at' => now()]);
    auth('customer')->login($customer);

    Livewire::test('shop.account-settings')->call('deleteAccount');

    expect($customer->fresh()->deleted_at)->not->toBeNull();
    expect(auth('customer')->check())->toBeFalse();
});
