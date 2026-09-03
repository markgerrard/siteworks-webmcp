<?php

namespace App\Jobs\Shop;

use App\Mail\Shop\MerchantNewOrder;
use App\Models\Shop\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Mail;

class DispatchMerchantNewOrder implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public int $orderId) {}

    public function handle(): void
    {
        $order = Order::with('site.user')->find($this->orderId);
        if (! $order?->site?->user?->email) {
            return;
        }

        Mail::to($order->site->user->email)->queue(
            new MerchantNewOrder($order, $order->site->user->email)
        );
    }
}
