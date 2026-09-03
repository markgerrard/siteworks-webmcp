<?php

use App\Enums\Shop\OrderStatus;
use App\Models\Shop\Order;
use App\Models\Site;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('lists paid orders by default, excludes pending', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);

    Order::create([
        'site_id' => $site->id, 'number' => 'P-1', 'email' => 'a@x.com', 'name' => 'A',
        'status' => OrderStatus::Paid->value, 'refund_status' => 'none',
        'subtotal_cents' => 100, 'shipping_cents' => 0, 'tax_cents' => 0,
        'shipping_tax_cents' => 0, 'total_cents' => 100,
        'tax_country_code' => 'GB', 'shipping_address_json' => [],
        'shipping_method_label' => 'Std', 'placed_at' => now(),
    ]);
    Order::create([
        'site_id' => $site->id, 'number' => 'P-2', 'email' => 'b@x.com', 'name' => 'B',
        'status' => OrderStatus::Pending->value, 'refund_status' => 'none',
        'subtotal_cents' => 100, 'shipping_cents' => 0, 'tax_cents' => 0,
        'shipping_tax_cents' => 0, 'total_cents' => 100,
        'tax_country_code' => 'GB', 'shipping_address_json' => [],
        'shipping_method_label' => 'Std', 'placed_at' => now(),
    ]);

    Livewire::test('shop.orders-list', ['siteId' => $site->id])
        ->assertSee('P-1')
        ->assertDontSee('P-2');
});

test('can filter paid orders to those containing a product', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $match = \App\Models\Shop\Product::factory()->for($site)->create();
    $other = \App\Models\Shop\Product::factory()->for($site)->create();
    $matchVariant = \App\Models\Shop\ProductVariant::factory()->for($match)->create();
    $otherVariant = \App\Models\Shop\ProductVariant::factory()->for($other)->create();

    $matchedOrder = Order::create([
        'site_id' => $site->id, 'number' => 'P-MATCH', 'email' => 'a@x.com', 'name' => 'A',
        'status' => OrderStatus::Paid->value, 'refund_status' => 'none',
        'subtotal_cents' => 100, 'shipping_cents' => 0, 'tax_cents' => 0,
        'shipping_tax_cents' => 0, 'total_cents' => 100,
        'tax_country_code' => 'GB', 'shipping_address_json' => [],
        'shipping_method_label' => 'Std', 'placed_at' => now(),
    ]);
    $otherOrder = Order::create([
        'site_id' => $site->id, 'number' => 'P-OTHER', 'email' => 'b@x.com', 'name' => 'B',
        'status' => OrderStatus::Paid->value, 'refund_status' => 'none',
        'subtotal_cents' => 100, 'shipping_cents' => 0, 'tax_cents' => 0,
        'shipping_tax_cents' => 0, 'total_cents' => 100,
        'tax_country_code' => 'GB', 'shipping_address_json' => [],
        'shipping_method_label' => 'Std', 'placed_at' => now(),
    ]);
    foreach ([[$matchedOrder, $match, $matchVariant], [$otherOrder, $other, $otherVariant]] as [$order, $product, $variant]) {
        \DB::table('shop_order_items')->insert([
            'order_id' => $order->id,
            'variant_id' => $variant->id,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->name,
            'sku_snapshot' => $variant->sku,
            'qty' => 1,
            'unit_price_cents' => 100,
            'tax_class_code' => 'standard',
            'tax_rate_percent' => 20,
            'tax_amount_cents' => 17,
            'line_total_cents' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    Livewire::test('shop.orders-list', ['siteId' => $site->id, 'productId' => $match->id])
        ->assertSee('P-MATCH')
        ->assertDontSee('P-OTHER');
});

test('can filter by shipped status', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);

    Order::create([
        'site_id' => $site->id, 'number' => 'S-1', 'email' => 'a@x.com', 'name' => 'A',
        'status' => OrderStatus::Shipped->value, 'refund_status' => 'none',
        'subtotal_cents' => 100, 'shipping_cents' => 0, 'tax_cents' => 0,
        'shipping_tax_cents' => 0, 'total_cents' => 100,
        'tax_country_code' => 'GB', 'shipping_address_json' => [],
        'shipping_method_label' => 'Std', 'placed_at' => now(),
    ]);

    Livewire::test('shop.orders-list', ['siteId' => $site->id])
        ->set('statusFilter', 'shipped')
        ->assertSee('S-1');
});
