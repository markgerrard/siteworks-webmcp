<?php

use App\Enums\Shop\ProductStatus;
use App\Enums\Shop\ShopSnapshotStatus;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\Product;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('job creates success snapshot and updates current pointer', function () {
    $site = Site::factory()->create();
    Product::factory()->for($site)->count(2)->create();

    (new RebuildShopSnapshot($site->id))->handle(app(\App\Services\Shop\SnapshotBuilder::class));

    $snap = ShopSnapshot::where('site_id', $site->id)->first();
    expect($snap->status)->toBe(ShopSnapshotStatus::Success);
    expect($snap->size_bytes)->toBeGreaterThan(0);
    expect($snap->build_duration_ms)->toBeGreaterThanOrEqual(0);
    expect($snap->product_count)->toBe(2);

    $current = ShopSnapshotCurrent::where('site_id', $site->id)->first();
    expect($current->snapshot_id)->toBe($snap->id);
});

test('successive runs increment version and never regress current pointer on failure', function () {
    $site = Site::factory()->create();
    (new RebuildShopSnapshot($site->id))->handle(app(\App\Services\Shop\SnapshotBuilder::class));
    (new RebuildShopSnapshot($site->id))->handle(app(\App\Services\Shop\SnapshotBuilder::class));

    $versions = ShopSnapshot::where('site_id', $site->id)->orderBy('version')->pluck('version')->toArray();
    expect($versions)->toBe([1, 2]);

    $current = ShopSnapshotCurrent::where('site_id', $site->id)->first();
    expect($current->snapshot_id)->toBe(ShopSnapshot::where('version', 2)->value('id'));
});

test('failed build records status=failed and leaves current pointer untouched', function () {
    $site = Site::factory()->create();
    (new RebuildShopSnapshot($site->id))->handle(app(\App\Services\Shop\SnapshotBuilder::class));
    $successId = ShopSnapshotCurrent::where('site_id', $site->id)->value('snapshot_id');

    $failingBuilder = new class(app(\App\Services\Shop\StockService::class), app(\App\Services\Shop\AutoTagComputer::class)) extends \App\Services\Shop\SnapshotBuilder
    {
        public function build(int $siteId): array
        {
            throw new \RuntimeException('boom');
        }
    };

    try {
        (new RebuildShopSnapshot($site->id))->handle($failingBuilder);
    } catch (\Throwable $e) {
        // expected; job rethrows so queue retries can fire
    }

    $failed = ShopSnapshot::where('site_id', $site->id)->where('status', ShopSnapshotStatus::Failed)->first();
    expect($failed)->not->toBeNull();
    expect($failed->build_error)->toBe('boom');

    $current = ShopSnapshotCurrent::where('site_id', $site->id)->first();
    expect($current->snapshot_id)->toBe($successId);
});

test('does not purge Cloudflare when a draft-only rebuild leaves the public projection unchanged', function () {
    config([
        'services.cloudflare.enabled' => true,
        'services.cloudflare.zone_id' => 'test-zone',
        'services.cloudflare.token' => 'test-token',
    ]);
    Http::fake();
    $site = Site::factory()->create();
    Product::factory()->for($site)->create(['status' => ProductStatus::Draft]);
    (new RebuildShopSnapshot($site->id))->handle(app(\App\Services\Shop\SnapshotBuilder::class));
    Http::fake();

    Product::factory()->for($site)->create(['status' => ProductStatus::Draft]);
    (new RebuildShopSnapshot($site->id))->handle(app(\App\Services\Shop\SnapshotBuilder::class));

    Http::assertNothingSent();
});

test('purges Cloudflare when the published public projection changes', function () {
    config([
        'services.cloudflare.enabled' => true,
        'services.cloudflare.zone_id' => 'test-zone',
        'services.cloudflare.token' => 'test-token',
    ]);
    Http::fake();
    $site = Site::factory()->create();
    (new RebuildShopSnapshot($site->id))->handle(app(\App\Services\Shop\SnapshotBuilder::class));
    Http::fake();

    Product::factory()->for($site)->published()->create();
    (new RebuildShopSnapshot($site->id))->handle(app(\App\Services\Shop\SnapshotBuilder::class));

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'test-zone/purge_cache')
        && $request['tags'] === ['shop:'.$site->id]);
});
