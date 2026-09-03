<?php

use App\Enums\Shop\OrderStatus;
use App\Enums\Shop\ProductStatus;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The success page loaded its order straight from ?order=<id>, scoped only to the site.
 * Any visitor could enumerate ids and read back another shopper's order number. Only the
 * number renders today, which is why this is not worse — but an order number is exactly
 * the identifier a refund-fraud or support-desk approach wants, and the shape invites
 * someone adding the email or total to that page later.
 */
function makeShopSiteWithOrder(string $host): array
{
    $site = Site::factory()->create(['custom_domain' => $host, 'custom_domain_status' => 'active']);
    Product::factory()->published()->for($site)->create();

    $order = Order::create([
        'site_id' => $site->id, 'number' => 'VICTIM-0001',
        'email' => 'victim@example.com', 'name' => 'Victim',
        'status' => OrderStatus::Paid->value, 'refund_status' => 'none',
        'subtotal_cents' => 1000, 'shipping_cents' => 0, 'tax_cents' => 0,
        'shipping_tax_cents' => 0, 'total_cents' => 1000,
        'tax_country_code' => 'GB', 'shipping_address_json' => [],
        'shipping_method_label' => 'Std', 'placed_at' => now(),
    ]);

    return [$site, $order];
}

test('a stranger cannot read an order number by guessing the id in the URL', function () {
    [$site, $order] = makeShopSiteWithOrder('idor.example');

    $this->get("http://idor.example/shop/checkout/success?order={$order->id}")
        ->assertOk()
        ->assertDontSee('VICTIM-0001');
});

test('the shopper who just placed the order still sees their number', function () {
    [$site, $order] = makeShopSiteWithOrder('idor.example');

    $this->withSession(['shop.last_order_id' => $order->id])
        ->get('http://idor.example/shop/checkout/success')
        ->assertOk()
        ->assertSee('VICTIM-0001');
});

test('a session order id from another site is not honoured', function () {
    [$siteA, $orderA] = makeShopSiteWithOrder('idor-a.example');
    $siteB = Site::factory()->create(['custom_domain' => 'idor-b.example', 'custom_domain_status' => 'active']);
    Product::factory()->published()->for($siteB)->create();

    $this->withSession(['shop.last_order_id' => $orderA->id])
        ->get('http://idor-b.example/shop/checkout/success')
        ->assertOk()
        ->assertDontSee('VICTIM-0001');
});
