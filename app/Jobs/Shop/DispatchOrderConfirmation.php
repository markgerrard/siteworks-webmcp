<?php

namespace App\Jobs\Shop;

use App\Mail\Shop\OrderReceipt;
use App\Models\Shop\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Mail;

class DispatchOrderConfirmation implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public int $orderId) {}

    public function handle(): void
    {
        $order = Order::with('items')->find($this->orderId);
        if (! $order) {
            return;
        }

        Mail::to($order->email)->queue(new OrderReceipt($order));
    }
}
