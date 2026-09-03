<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use App\Services\Shop\SnapshotReader;
use Illuminate\Support\Facades\Cache;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->reader = app(SnapshotReader::class);
});

test('returns current snapshot json for site', function () {
    $site = Site::factory()->create();
    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => ['meta' => ['site_id' => $site->id], 'products' => [], 'categories' => [], 'featured_slugs' => []],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snap->id, 'updated_at' => now()]);

    $json = $this->reader->forSite($site->id);
    expect($json['meta']['site_id'])->toBe($site->id);
});

test('returns null when no current snapshot exists', function () {
    $site = Site::factory()->create();
    expect($this->reader->forSite($site->id))->toBeNull();
});

test('caches snapshot json per site', function () {
    $site = Site::factory()->create();
    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => ['meta' => ['product_count' => 0], 'products' => [], 'categories' => [], 'featured_slugs' => []],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snap->id, 'updated_at' => now()]);

    $this->reader->forSite($site->id);

    // mutate DB but cache should still serve
    $snap->update(['json' => ['meta' => ['product_count' => 999], 'products' => [], 'categories' => [], 'featured_slugs' => []]]);
    $cached = $this->reader->forSite($site->id);
    expect($cached['meta']['product_count'])->toBe(0);
});

test('invalidate clears cached snapshot', function () {
    $site = Site::factory()->create();
    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => ['meta' => ['product_count' => 0], 'products' => [], 'categories' => [], 'featured_slugs' => []],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snap->id, 'updated_at' => now()]);

    $this->reader->forSite($site->id);
    $snap->update(['json' => ['meta' => ['product_count' => 777], 'products' => [], 'categories' => [], 'featured_slugs' => []]]);

    $this->reader->invalidate($site->id);

    $refreshed = $this->reader->forSite($site->id);
    expect($refreshed['meta']['product_count'])->toBe(777);
});
