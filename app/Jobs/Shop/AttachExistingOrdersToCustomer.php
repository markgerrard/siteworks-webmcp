<?php

namespace App\Jobs\Shop;

use App\Models\Shop\Customer;
use App\Models\Shop\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class AttachExistingOrdersToCustomer implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public int $customerId) {}

    public function handle(): void
    {
        $customer = Customer::find($this->customerId);
        if (! $customer || $customer->email_verified_at === null) {
            return;
        }

        Order::where('site_id', $customer->site_id)
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($customer->email)])
            ->whereNull('customer_id')
            ->update(['customer_id' => $customer->id]);
    }
}
