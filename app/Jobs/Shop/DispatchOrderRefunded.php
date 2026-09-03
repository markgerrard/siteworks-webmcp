<?php

namespace App\Jobs\Shop;

use App\Mail\Shop\OrderRefunded;
use App\Models\Shop\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Mail;

class DispatchOrderRefunded implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public int $orderId) {}

    public function handle(): void
    {
        $order = Order::find($this->orderId);
        if (! $order) {
            return;
        }

        Mail::to($order->email)->queue(new OrderRefunded($order));
    }
}
