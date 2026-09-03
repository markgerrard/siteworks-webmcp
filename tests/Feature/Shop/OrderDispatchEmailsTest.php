<?php

use App\Enums\Shop\OrderStatus;
use App\Jobs\Shop\DispatchOrderCancelled;
use App\Jobs\Shop\DispatchOrderRefunded;
use App\Jobs\Shop\DispatchOrderShipped;
use App\Mail\Shop\OrderCancelled;
use App\Mail\Shop\OrderRefunded;
use App\Mail\Shop\OrderShipped;
use App\Models\Shop\Order;
use App\Models\Site;
use Illuminate\Support\Facades\Mail;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => Mail::fake());

foreach (['Shipped', 'Cancelled', 'Refunded'] as $type) {
    test("dispatch{$type} sends to customer email", function () use ($type) {
        $order = Order::create([
            'site_id' => Site::factory()->create()->id,
            'number' => "X-$type",
            'email' => 'buyer@example.com', 'name' => 'B',
            'status' => 'paid', 'refund_status' => 'none',
            'subtotal_cents' => 500, 'shipping_cents' => 0, 'tax_cents' => 0,
            'shipping_tax_cents' => 0, 'total_cents' => 500,
            'tax_country_code' => 'GB', 'shipping_address_json' => [],
            'shipping_method_label' => 'Std', 'placed_at' => now(),
        ]);

        $jobClass = "App\\Jobs\\Shop\\DispatchOrder{$type}";
        $mailClass = "App\\Mail\\Shop\\Order{$type}";
        (new $jobClass($order->id))->handle();

        Mail::assertQueued($mailClass, fn ($m) => $m->hasTo('buyer@example.com'));
    });
}
