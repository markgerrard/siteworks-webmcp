<?php

use App\Enums\Shop\ProductStatus;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Site;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('bulk remove deletes only unreviewed seeded products with zero orders', function () {
    $user = User::factory()->admin()->create();
    $site = Site::factory()->create();
    $this->actingAs($user);

    $seededUnreviewed = Product::factory()->for($site)->create(['is_ai_seeded' => true, 'is_ai_reviewed' => false]);
    $seededReviewed = Product::factory()->for($site)->create(['is_ai_seeded' => true, 'is_ai_reviewed' => true]);
    $real = Product::factory()->for($site)->create(['is_ai_seeded' => false]);

    Livewire::test('shop.ai-seed-panel', ['siteId' => $site->id])
        ->call('bulkRemoveUnreviewed');

    expect(Product::find($seededUnreviewed->id))->toBeNull();
    expect(Product::find($seededReviewed->id))->not->toBeNull();
    expect(Product::find($real->id))->not->toBeNull();
});

test('bulk remove leaves a published unreviewed seeded product', function () {
    $user = User::factory()->admin()->create();
    $site = Site::factory()->create();
    $this->actingAs($user);

    $published = Product::factory()->for($site)->create([
        'is_ai_seeded' => true,
        'is_ai_reviewed' => false,
        'status' => ProductStatus::Published,
    ]);
    $draft = Product::factory()->for($site)->create([
        'is_ai_seeded' => true,
        'is_ai_reviewed' => false,
        'status' => ProductStatus::Draft,
    ]);

    Livewire::test('shop.ai-seed-panel', ['siteId' => $site->id])
        ->call('bulkRemoveUnreviewed');

    expect(Product::find($published->id))->not->toBeNull()
        ->and(Product::find($draft->id))->toBeNull();
});

test('bulk remove does NOT delete products with any order_items linked', function () {
    $user = User::factory()->admin()->create();
    $site = Site::factory()->create();
    $this->actingAs($user);

    $seededWithOrder = Product::factory()->for($site)->create(['is_ai_seeded' => true, 'is_ai_reviewed' => false]);
    $variant = ProductVariant::factory()->for($seededWithOrder)->create();

    \DB::table('shop_order_items')->insert([
        'order_id' => Order::create([
            'site_id' => $site->id,
            'number' => 'ORD-1',
            'email' => 'a@b.com',
            'name' => 'A',
            'status' => 'paid',
            'refund_status' => 'none',
            'subtotal_cents' => 0,
            'shipping_cents' => 0,
            'tax_cents' => 0,
            'shipping_tax_cents' => 0,
            'total_cents' => 0,
            'tax_country_code' => 'GB',
            'shipping_address_json' => json_encode([]),
            'shipping_method_label' => 'Std',
            'placed_at' => now(),
        ])->id,
        'variant_id' => $variant->id,
        'product_id' => $seededWithOrder->id,
        'product_name_snapshot' => 'X',
        'sku_snapshot' => 'X',
        'qty' => 1,
        'unit_price_cents' => 0,
        'tax_class_code' => 'standard',
        'tax_rate_percent' => 20.00,
        'tax_amount_cents' => 0,
        'line_total_cents' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test('shop.ai-seed-panel', ['siteId' => $site->id])
        ->call('bulkRemoveUnreviewed');

    expect(Product::find($seededWithOrder->id))->not->toBeNull();
});
