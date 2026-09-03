<?php

use App\Enums\Shop\OrderStatus;
use App\Jobs\Shop\DispatchOrderConfirmation;
use App\Jobs\Shop\DispatchMerchantNewOrder;
use App\Mail\Shop\MerchantNewOrder;
use App\Mail\Shop\OrderReceipt;
use App\Models\Shop\Order;
use App\Models\Shop\OrderItem;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => Mail::fake());

test('DispatchOrderConfirmation sends OrderReceipt to customer email', function () {
    $user = User::factory()->create(['email' => 'owner@shop.test']);
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $product = Product::factory()->for($site)->create();
    $variant = ProductVariant::factory()->for($product)->create();
    $order = Order::create([
        'site_id' => $site->id,
        'number' => 'TEST-000001',
        'email' => 'buyer@example.com',
        'name' => 'Buyer',
        'status' => OrderStatus::Paid->value,
        'refund_status' => 'none',
        'subtotal_cents' => 1000, 'shipping_cents' => 200, 'tax_cents' => 200,
        'shipping_tax_cents' => 40, 'total_cents' => 1200,
        'tax_country_code' => 'GB',
        'shipping_address_json' => [], 'shipping_method_label' => 'Std',
        'placed_at' => now(),
    ]);
    OrderItem::create([
        'order_id' => $order->id, 'variant_id' => $variant->id, 'product_id' => $product->id,
        'product_name_snapshot' => 'X', 'variant_label_snapshot' => 'S', 'sku_snapshot' => 'X',
        'qty' => 1, 'unit_price_cents' => 1000,
        'tax_class_code' => 'standard', 'tax_rate_percent' => 20, 'tax_amount_cents' => 166, 'line_total_cents' => 1000,
    ]);

    (new DispatchOrderConfirmation($order->id))->handle();

    Mail::assertQueued(OrderReceipt::class, fn ($m) => $m->hasTo('buyer@example.com'));
});

test('DispatchMerchantNewOrder sends to merchant (site owner) email', function () {
    $user = User::factory()->create(['email' => 'owner@shop.test']);
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $order = Order::create([
        'site_id' => $site->id, 'number' => 'TEST-000002',
        'email' => 'buyer@example.com', 'name' => 'Buyer',
        'status' => OrderStatus::Paid->value, 'refund_status' => 'none',
        'subtotal_cents' => 500, 'shipping_cents' => 0, 'tax_cents' => 0,
        'shipping_tax_cents' => 0, 'total_cents' => 500,
        'tax_country_code' => 'GB', 'shipping_address_json' => [],
        'shipping_method_label' => 'Std', 'placed_at' => now(),
    ]);

    (new DispatchMerchantNewOrder($order->id))->handle();

    Mail::assertQueued(MerchantNewOrder::class, fn ($m) => $m->hasTo('owner@shop.test'));
});
