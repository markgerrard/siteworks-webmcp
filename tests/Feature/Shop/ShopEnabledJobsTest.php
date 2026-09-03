<?php

use App\Enums\Shop\OrderStatus;
use App\Jobs\Shop\DispatchOrderConfirmation;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Mail\Shop\OrderReceipt;
use App\Models\Shop\Category;
use App\Models\Shop\Order;
use App\Models\Shop\OrderItem;
use App\Models\Shop\Product;
use App\Models\Shop\ProductImage;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopSnapshot;
use App\Models\Site;
use App\Observers\Shop\CatalogObserver;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

test('RebuildShopSnapshot handle is a no-op when the flag is off', function () {
    $site = Site::factory()->create(['shop_enabled' => true]);
    Product::factory()->for($site)->create();
    $site->update(['shop_enabled' => false]);

    (new RebuildShopSnapshot($site->id))->handle(app(\App\Services\Shop\SnapshotBuilder::class));

    expect(ShopSnapshot::query()->where('site_id', $site->id)->exists())->toBeFalse();
});

test('CatalogObserver does not dispatch a snapshot rebuild for a flag-off site', function () {
    Bus::fake([RebuildShopSnapshot::class]);
    $site = Site::factory()->shopDisabled()->create();

    CatalogObserver::$muted = false;
    Product::factory()->for($site)->create();

    Bus::assertNotDispatched(RebuildShopSnapshot::class);
});

test('receipt mail still sends after the shop flag is turned off', function () {
    Mail::fake();
    $site = Site::factory()->create(['shop_enabled' => true]);
    $product = Product::factory()->for($site)->create();
    $variant = ProductVariant::factory()->for($product)->create();
    $order = Order::create([
        'site_id' => $site->id,
        'number' => 'RCPT-OFF-1',
        'email' => 'buyer@example.com',
        'name' => 'Buyer',
        'status' => OrderStatus::Paid->value,
        'refund_status' => 'none',
        'subtotal_cents' => 1000,
        'shipping_cents' => 0,
        'tax_cents' => 0,
        'shipping_tax_cents' => 0,
        'total_cents' => 1000,
        'tax_country_code' => 'GB',
        'shipping_address_json' => [],
        'shipping_method_label' => 'Std',
        'placed_at' => now(),
    ]);
    OrderItem::create([
        'order_id' => $order->id,
        'variant_id' => $variant->id,
        'product_id' => $product->id,
        'product_name_snapshot' => 'X',
        'variant_label_snapshot' => 'S',
        'sku_snapshot' => 'X',
        'qty' => 1,
        'unit_price_cents' => 1000,
        'tax_class_code' => 'standard',
        'tax_rate_percent' => 20,
        'tax_amount_cents' => 166,
        'line_total_cents' => 1000,
    ]);

    $site->update(['shop_enabled' => false]);
    (new DispatchOrderConfirmation($order->id))->handle();

    Mail::assertQueued(OrderReceipt::class, fn ($m) => $m->hasTo('buyer@example.com'));
});
