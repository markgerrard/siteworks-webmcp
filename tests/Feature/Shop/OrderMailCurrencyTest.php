<?php

use App\Enums\Shop\OrderStatus;
use App\Models\Shop\Order;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders order emails in the site currency, not a hard-coded pound sign', function () {
    $site = Site::factory()->create(['shop_currency' => 'USD', 'shop_mode' => 'cart']);
    $order = Order::create([
        'site_id' => $site->id,
        'number' => 'CAMINO-000001',
        'email' => 'ava@example.com',
        'name' => 'Ava',
        'status' => OrderStatus::Paid->value,
        'refund_status' => 'none',
        'subtotal_cents' => 4250,
        'shipping_cents' => 0,
        'tax_cents' => 0,
        'shipping_tax_cents' => 0,
        'total_cents' => 4250,
        'tax_country_code' => 'US',
        'shipping_method_label' => 'Local delivery',
        'shipping_address_json' => ['line1' => '1 Main St', 'city' => 'Palo Alto', 'postcode' => '94301', 'country_code' => 'US'],
        'refund_amount_cents' => 1000,
    ]);
    $order->load('site');

    expect(view('mail.shop.merchant-new-order', ['order' => $order])->render())->toContain('$42.50')->not->toContain('£');
    expect(view('mail.shop.order-refunded', ['order' => $order])->render())->toContain('$10')->not->toContain('£');
});
