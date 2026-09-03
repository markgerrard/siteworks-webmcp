<?php

use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\Product;
use App\Models\Shop\ShopSlugRedirect;
use App\Models\Site;
use App\Services\Shop\CloudflarePurger;
use App\Services\Shop\SnapshotReader;
use App\Services\Site\PublicPageCache;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

test('dry-run reports seeded suffixed slugs without writing', function () {
    $site = Site::factory()->create();
    $seeded = Product::factory()->for($site)->create([
        'name' => 'Victoria Sponge',
        'slug' => 'victoria-sponge-rcax2r',
        'is_ai_seeded' => true,
    ]);
    $merchant = Product::factory()->for($site)->create([
        'name' => 'Merchant Cake',
        'slug' => 'merchant-cake-abc123',
        'is_ai_seeded' => false,
    ]);

    $this->artisan('shop:clean-slugs', ['site' => (string) $site->id, '--dry-run' => true])
        ->assertSuccessful();

    expect($seeded->fresh()->slug)->toBe('victoria-sponge-rcax2r')
        ->and($merchant->fresh()->slug)->toBe('merchant-cake-abc123')
        ->and(ShopSlugRedirect::query()->count())->toBe(0);
});

test('clean-slugs renames seeder-minted suffixes, records redirects, and leaves merchant slugs', function () {
    Bus::fake();
    $site = Site::factory()->create();
    $seeded = Product::factory()->for($site)->create([
        'name' => 'Victoria Sponge',
        'slug' => 'victoria-sponge-rcax2r',
        'is_ai_seeded' => true,
    ]);
    $merchant = Product::factory()->for($site)->create([
        'name' => 'Merchant Cake',
        'slug' => 'merchant-cake-abc123',
        'is_ai_seeded' => false,
    ]);

    $this->artisan('shop:clean-slugs', ['site' => (string) $site->id])
        ->assertSuccessful();

    expect($seeded->fresh()->slug)->toBe('victoria-sponge')
        ->and($merchant->fresh()->slug)->toBe('merchant-cake-abc123');

    $redirect = ShopSlugRedirect::query()->where('site_id', $site->id)->first();
    expect($redirect)->not->toBeNull()
        ->and($redirect->kind)->toBe('product')
        ->and($redirect->old_slug)->toBe('victoria-sponge-rcax2r')
        ->and($redirect->slug)->toBe('victoria-sponge');

    Bus::assertDispatched(RebuildShopSnapshot::class, fn (RebuildShopSnapshot $job): bool => $job->siteId === $site->id);
});

test('clean-slugs appends -2 when the clean slug is already taken', function () {
    Bus::fake();
    $site = Site::factory()->create();
    Product::factory()->for($site)->create([
        'name' => 'Victoria Sponge',
        'slug' => 'victoria-sponge',
        'is_ai_seeded' => false,
    ]);
    $seeded = Product::factory()->for($site)->create([
        'name' => 'Victoria Sponge',
        'slug' => 'victoria-sponge-rcax2r',
        'is_ai_seeded' => true,
    ]);

    $this->artisan('shop:clean-slugs', ['site' => (string) $site->id])
        ->assertSuccessful();

    expect($seeded->fresh()->slug)->toBe('victoria-sponge-2');
    expect(ShopSlugRedirect::query()->where('old_slug', 'victoria-sponge-rcax2r')->value('slug'))
        ->toBe('victoria-sponge-2');
});

test('clean-slugs --all walks every shop-enabled site', function () {
    $one = Site::factory()->create();
    $two = Site::factory()->create();
    Product::factory()->for($one)->create([
        'name' => 'Rose',
        'slug' => 'rose-aaaaaa',
        'is_ai_seeded' => true,
    ]);
    Product::factory()->for($two)->create([
        'name' => 'Lily',
        'slug' => 'lily-bbbbbb',
        'is_ai_seeded' => true,
    ]);

    Bus::fake();

    $this->artisan('shop:clean-slugs', ['--all' => true])
        ->assertSuccessful();

    expect(Product::query()->where('site_id', $one->id)->value('slug'))->toBe('rose')
        ->and(Product::query()->where('site_id', $two->id)->value('slug'))->toBe('lily');

    Bus::assertDispatched(RebuildShopSnapshot::class, 2);
});

test('dry-run allocates colliding bases sequentially like a real run', function () {
    $site = Site::factory()->create();
    Product::factory()->for($site)->create([
        'name' => 'Victoria Sponge',
        'slug' => 'victoria-sponge-aaaaaa',
        'is_ai_seeded' => true,
    ]);
    Product::factory()->for($site)->create([
        'name' => 'Victoria Sponge',
        'slug' => 'victoria-sponge-bbbbbb',
        'is_ai_seeded' => true,
    ]);

    $expected = [
        [(string) $site->id, 'victoria-sponge-aaaaaa', 'victoria-sponge'],
        [(string) $site->id, 'victoria-sponge-bbbbbb', 'victoria-sponge-2'],
    ];

    $this->artisan('shop:clean-slugs', ['site' => (string) $site->id, '--dry-run' => true])
        ->expectsTable(['site', 'old_slug', 'slug'], $expected)
        ->assertSuccessful();

    expect(Product::query()->where('site_id', $site->id)->orderBy('id')->pluck('slug')->all())
        ->toBe(['victoria-sponge-aaaaaa', 'victoria-sponge-bbbbbb']);

    Bus::fake();

    $this->artisan('shop:clean-slugs', ['site' => (string) $site->id])
        ->expectsTable(['site', 'old_slug', 'slug'], $expected)
        ->assertSuccessful();

    expect(Product::query()->where('site_id', $site->id)->orderBy('id')->pluck('slug')->all())
        ->toBe(['victoria-sponge', 'victoria-sponge-2']);
});

test('clean-slugs busts the snapshot cache for rewritten sites', function () {
    $site = Site::factory()->create();
    Product::factory()->for($site)->create([
        'name' => 'Rose',
        'slug' => 'rose-cccccc',
        'is_ai_seeded' => true,
    ]);

    $reader = Mockery::mock(SnapshotReader::class);
    $reader->shouldReceive('invalidate')->once()->with($site->id);
    $this->app->instance(SnapshotReader::class, $reader);

    $purger = Mockery::mock(CloudflarePurger::class);
    $purger->shouldReceive('purgeShop')->once()->with($site->id);
    $this->app->instance(CloudflarePurger::class, $purger);

    Bus::fake();

    $this->artisan('shop:clean-slugs', ['site' => (string) $site->id])
        ->assertSuccessful();
});

test('clean-slugs invalidates the public sitemap cache for rewritten sites', function () {
    config(['site.public_cache_enabled' => true]);

    $site = Site::factory()->create();
    Product::factory()->for($site)->create([
        'name' => 'Rose',
        'slug' => 'rose-dddddd',
        'is_ai_seeded' => true,
    ]);

    $pageCache = app(PublicPageCache::class);
    $suffix = 'sitemap:http://clean-slugs-map.example';
    $staleKey = $pageCache->namespacedKey($site, $suffix);
    Cache::put($staleKey, 'STALE-SITEMAP', 3600);

    Bus::fake();

    $this->artisan('shop:clean-slugs', ['site' => (string) $site->id])
        ->assertSuccessful();

    $freshKey = $pageCache->namespacedKey($site, $suffix);
    expect($freshKey)->not->toBe($staleKey)
        ->and(Cache::get($freshKey))->toBeNull();
});
