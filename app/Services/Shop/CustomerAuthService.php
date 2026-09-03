<?php

namespace App\Services\Shop;

use App\Exceptions\Shop\CustomerDeletedException;
use App\Exceptions\Shop\InvalidMagicLinkException;
use App\Mail\Shop\CustomerMagicLinkMail;
use App\Models\Shop\Customer;
use App\Models\Shop\CustomerMagicLink;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class CustomerAuthService
{
    public const LINK_TTL_MINUTES = 15;

    public function requestLinkFor(int $siteId, string $email, ?string $ip = null): Customer
    {
        $soft = Customer::withTrashed()->where('site_id', $siteId)->where('email', $email)->first();
        if ($soft?->trashed()) {
            throw new CustomerDeletedException('Account is deleted.');
        }

        $customer = Customer::firstOrCreate(
            ['site_id' => $siteId, 'email' => $email],
            []
        );

        $rawToken = Str::random(48);
        $link = CustomerMagicLink::create([
            'customer_id' => $customer->id,
            'token_hash' => hash('sha256', $rawToken),
            'requested_ip' => $ip,
            'expires_at' => now()->addMinutes(self::LINK_TTL_MINUTES),
        ]);

        // Test hook so tests can retrieve the raw token
        if (app()->environment('testing')) {
            Cache::put("magic_link_raw_{$link->id}", $rawToken, 60);
        }

        Mail::to($customer->email)->queue(new CustomerMagicLinkMail($customer, $rawToken));

        return $customer;
    }

    public function consumeLink(int $siteId, string $rawToken, ?string $ip = null): Customer
    {
        $hash = hash('sha256', $rawToken);

        $link = CustomerMagicLink::whereHas('customer', fn ($q) => $q->where('site_id', $siteId))
            ->where('token_hash', $hash)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $link) {
            throw new InvalidMagicLinkException('Invalid or expired link');
        }

        $customer = Customer::find($link->customer_id);
        if ($customer->trashed()) {
            throw new CustomerDeletedException('Account is deleted');
        }

        $link->update(['consumed_at' => now(), 'consumed_ip' => $ip]);
        $customer->update([
            'email_verified_at' => $customer->email_verified_at ?? now(),
            'last_login_at' => now(),
        ]);

        return $customer;
    }

    public function loginWithPassword(int $siteId, string $email, string $password): ?Customer
    {
        $customer = Customer::where('site_id', $siteId)->where('email', $email)->first();

        if (! $customer || $customer->trashed() || ! $customer->password_hash) {
            return null;
        }

        if (! password_verify($password, $customer->password_hash)) {
            return null;
        }

        $customer->update(['last_login_at' => now()]);

        return $customer;
    }

    public function setPassword(Customer $customer, string $password): void
    {
        $customer->update(['password_hash' => password_hash($password, PASSWORD_BCRYPT)]);
    }
}
