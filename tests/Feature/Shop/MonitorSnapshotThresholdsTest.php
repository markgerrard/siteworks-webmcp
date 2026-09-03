<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\ShopSnapshot;
use App\Models\Site;
use Illuminate\Support\Facades\Log;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('logs warning when snapshot size exceeds 500KB', function () {
    $site = Site::factory()->create();
    ShopSnapshot::create([
        'site_id' => $site->id,
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
        fn ($msg) => str_contains($msg, 'size_bytes')
    );
});

test('logs warning when rebuild p95 exceeds 500ms', function () {
    $site = Site::factory()->create();
    foreach (range(1, 20) as $v) {
        ShopSnapshot::create([
            'site_id' => $site->id,
            'version' => $v,
            'status' => ShopSnapshotStatus::Success,
            'size_bytes' => 100_000,
            'build_duration_ms' => $v === 20 ? 600 : 300,
            'product_count' => 50,
            'built_at' => now(),
        ]);
    }

    Log::spy();

    $this->artisan('shop:monitor-snapshot-thresholds')->assertSuccessful();

    Log::shouldHaveReceived('warning')->atLeast()->once();
});

test('logs advisory when product_count exceeds 500', function () {
    $site = Site::factory()->create();
    ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'size_bytes' => 100_000,
        'build_duration_ms' => 200,
        'product_count' => 600,
        'built_at' => now(),
    ]);

    Log::spy();

    $this->artisan('shop:monitor-snapshot-thresholds')->assertSuccessful();

    Log::shouldHaveReceived('info')->once()->withArgs(
        fn ($msg) => str_contains($msg, 'product_count')
    );
});
