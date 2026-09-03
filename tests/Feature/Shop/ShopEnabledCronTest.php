<?php

use App\Enums\Shop\OrderStatus;
use App\Enums\Shop\ShopSnapshotStatus;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

test('shop:expire-pending-orders reaps expired pending orders on flag-off sites', function () {
    $on = Site::factory()->create(['shop_enabled' => true]);
    $off = Site::factory()->create(['shop_enabled' => false]);

    $expired = [
        'email' => 'x@y.com',
        'name' => 'X',
        'status' => OrderStatus::Pending->value,
        'refund_status' => 'none',
        'subtotal_cents' => 100,
        'shipping_cents' => 0,
        'tax_cents' => 0,
        'shipping_tax_cents' => 0,
        'total_cents' => 100,
        'tax_country_code' => 'GB',
        'shipping_address_json' => [],
        'shipping_method_label' => 'Std',
        'placed_at' => now(),
        'expires_at' => now()->subHours(2),
    ];

    $onOrder = Order::create(['site_id' => $on->id, 'number' => 'ON-1'] + $expired);
    $offOrder = Order::create(['site_id' => $off->id, 'number' => 'OFF-1'] + $expired);

    $this->artisan('shop:expire-pending-orders')->assertSuccessful();

    expect($onOrder->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($offOrder->fresh()->status)->toBe(OrderStatus::Cancelled);
});

test('shop:prune-snapshots still prunes a disabled site', function () {
    $off = Site::factory()->shopDisabled()->create();

    foreach (range(1, 10) as $v) {
        ShopSnapshot::create([
            'site_id' => $off->id,
            'version' => $v,
            'status' => ShopSnapshotStatus::Success,
            'size_bytes' => 1000,
            'build_duration_ms' => 100,
            'product_count' => 1,
            'built_at' => now(),
        ]);
    }

    ShopSnapshotCurrent::create([
        'site_id' => $off->id,
        'snapshot_id' => ShopSnapshot::where('site_id', $off->id)->where('version', 10)->value('id'),
        'updated_at' => now(),
    ]);

    $this->artisan('shop:prune-snapshots')->assertSuccessful();

    expect(
        ShopSnapshot::where('site_id', $off->id)
            ->where('status', ShopSnapshotStatus::Success)
            ->orderBy('version')
            ->pluck('version')
            ->all()
    )->toBe([6, 7, 8, 9, 10]);
});

test('shop:monitor-snapshot-thresholds still logs a disabled site', function () {
    $off = Site::factory()->shopDisabled()->create();
    ShopSnapshot::create([
        'site_id' => $off->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'size_bytes' => 600_000,
        'build_duration_ms' => 200,
        'product_count' => 100,
        'built_at' => now(),
    ]);

    Log::spy();

    $this->artisan('shop:monitor-snapshot-thresholds')->assertSuccessful();

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn ($msg) => str_contains($msg, 'size_bytes') && str_contains($msg, (string) $off->id)
    );
});

test('shop:reconcile still skips sites whose flag is off', function () {
    Queue::fake();

    $off = Site::factory()->shopDisabled()->create();
    Product::factory()->for($off)->create();

    Queue::fake();

    $this->artisan('shop:reconcile')->assertSuccessful();

    Queue::assertNotPushed(RebuildShopSnapshot::class, fn ($job) => $job->siteId === $off->id);
});
