<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('keeps last 5 success versions per site and prunes older', function () {
    $site = Site::factory()->create();

    foreach (range(1, 10) as $v) {
        ShopSnapshot::create([
            'site_id' => $site->id,
            'version' => $v,
            'status' => ShopSnapshotStatus::Success,
            'size_bytes' => 1000,
            'build_duration_ms' => 100,
            'product_count' => 1,
            'built_at' => now(),
        ]);
    }

    // pin current to version 10
    ShopSnapshotCurrent::create([
        'site_id' => $site->id,
        'snapshot_id' => ShopSnapshot::where('version', 10)->value('id'),
        'updated_at' => now(),
    ]);

    $this->artisan('shop:prune-snapshots')->assertSuccessful();

    $remaining = ShopSnapshot::where('site_id', $site->id)
        ->where('status', ShopSnapshotStatus::Success)
        ->orderBy('version')
        ->pluck('version')
        ->toArray();

    expect($remaining)->toBe([6, 7, 8, 9, 10]);
});

test('never deletes the current snapshot', function () {
    $site = Site::factory()->create();

    $old = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'built_at' => now(),
    ]);
    // current pinned to old, with 10 newer success rows — prune should keep old anyway
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $old->id, 'updated_at' => now()]);

    foreach (range(2, 11) as $v) {
        ShopSnapshot::create([
            'site_id' => $site->id,
            'version' => $v,
            'status' => ShopSnapshotStatus::Success,
            'built_at' => now(),
        ]);
    }

    $this->artisan('shop:prune-snapshots')->assertSuccessful();

    expect(ShopSnapshot::find($old->id))->not->toBeNull();
});

test('deletes failed snapshots older than 7 days', function () {
    $site = Site::factory()->create();

    $old = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Failed,
        'built_at' => now()->subDays(10),
    ]);
    $recent = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 2,
        'status' => ShopSnapshotStatus::Failed,
        'built_at' => now()->subDays(3),
    ]);

    $this->artisan('shop:prune-snapshots')->assertSuccessful();

    expect(ShopSnapshot::find($old->id))->toBeNull();
    expect(ShopSnapshot::find($recent->id))->not->toBeNull();
});
