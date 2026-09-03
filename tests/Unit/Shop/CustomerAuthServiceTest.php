<?php

use App\Exceptions\Shop\CustomerDeletedException;
use App\Exceptions\Shop\InvalidMagicLinkException;
use App\Models\Shop\Customer;
use App\Models\Shop\CustomerMagicLink;
use App\Models\Site;
use App\Services\Shop\CustomerAuthService;
use Illuminate\Support\Facades\Mail;


test('sendMagicLink creates link row and returns raw token', function () {
    $site = Site::factory()->create();
    $svc = app(CustomerAuthService::class);
    Mail::fake();

    $customer = $svc->requestLinkFor($site->id, 'new@example.com');

    expect($customer->email)->toBe('new@example.com');
    expect(CustomerMagicLink::where('customer_id', $customer->id)->count())->toBe(1);
});

test('consume validates unused + unexpired link', function () {
    $site = Site::factory()->create();
    $svc = app(CustomerAuthService::class);

    Mail::fake();
    $customer = $svc->requestLinkFor($site->id, 'new@example.com');

    $link = CustomerMagicLink::where('customer_id', $customer->id)->first();
    $rawToken = \Cache::get("magic_link_raw_{$link->id}");

    $result = $svc->consumeLink($site->id, $rawToken);
    expect($result->id)->toBe($customer->id);

    $link->refresh();
    expect($link->consumed_at)->not->toBeNull();
});

test('consume rejects already-consumed link', function () {
    $site = Site::factory()->create();
    $svc = app(CustomerAuthService::class);
    Mail::fake();

    $customer = $svc->requestLinkFor($site->id, 'new@example.com');
    $link = CustomerMagicLink::where('customer_id', $customer->id)->first();
    $rawToken = \Cache::get("magic_link_raw_{$link->id}");

    $svc->consumeLink($site->id, $rawToken);
    expect(fn () => $svc->consumeLink($site->id, $rawToken))
        ->toThrow(InvalidMagicLinkException::class);
});

test('consume rejects expired link', function () {
    $site = Site::factory()->create();
    $svc = app(CustomerAuthService::class);
    Mail::fake();

    $customer = $svc->requestLinkFor($site->id, 'new@example.com');
    $link = CustomerMagicLink::where('customer_id', $customer->id)->first();
    $link->update(['expires_at' => now()->subMinute()]);
    $rawToken = \Cache::get("magic_link_raw_{$link->id}");

    expect(fn () => $svc->consumeLink($site->id, $rawToken))
        ->toThrow(InvalidMagicLinkException::class);
});

test('soft-deleted customer cannot request a link', function () {
    $site = Site::factory()->create();
    $c = Customer::create(['site_id' => $site->id, 'email' => 'gone@example.com']);
    $c->delete();

    Mail::fake();
    expect(fn () => app(CustomerAuthService::class)->requestLinkFor($site->id, 'gone@example.com'))
        ->toThrow(CustomerDeletedException::class);
});
