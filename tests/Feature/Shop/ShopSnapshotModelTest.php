<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('can create snapshot with status and json', function () {
    $site = Site::factory()->create();
    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => ['meta' => ['product_count' => 0]],
        'built_at' => now(),
    ]);

    expect($snap->status)->toBe(ShopSnapshotStatus::Success);
    expect($snap->json['meta']['product_count'])->toBe(0);
});

test('shop_snapshot_current points to a snapshot per site', function () {
    $site = Site::factory()->create();
    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snap->id, 'updated_at' => now()]);

    expect($site->fresh()->currentShopSnapshot->snapshot_id)->toBe($snap->id);
});
