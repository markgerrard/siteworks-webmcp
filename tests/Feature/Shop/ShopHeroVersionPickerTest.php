<?php

use App\Models\Shop\Category;
use App\Models\Shop\ShopHeroVersion;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use App\Services\Shop\ShopHeroGenerator;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('selectVersion for shop scope updates the current snapshot row', function () {
    $site = Site::factory()->create();
    $snapshot = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => \App\Enums\Shop\ShopSnapshotStatus::Success,
        'json' => [],
        'built_at' => now(),
        'hero_image_url' => 'https://example.com/current.png',
    ]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snapshot->id]);

    $v1 = ShopHeroVersion::create([
        'site_id' => $site->id,
        'scope' => 'shop',
        'scope_id' => null,
        'image_url' => 'https://example.com/v1.png',
        'hero_alt' => 'v1',
        'created_at' => now()->subHours(2),
    ]);

    app(ShopHeroGenerator::class)->selectVersion($v1);

    $snapshot->refresh();
    expect($snapshot->hero_image_url)->toBe('https://example.com/v1.png');
    expect($snapshot->hero_alt)->toBe('v1');
});

test('selectVersion for category-shared scope updates the current snapshot shared block', function () {
    $site = Site::factory()->create();
    $snapshot = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => \App\Enums\Shop\ShopSnapshotStatus::Success,
        'json' => [],
        'built_at' => now(),
        'shared_category_hero' => [
            'image_url' => 'https://example.com/current-shared.png',
            'hero_alt' => 'current',
            'width' => 'full',
        ],
    ]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snapshot->id]);

    $v1 = ShopHeroVersion::create([
        'site_id' => $site->id,
        'scope' => 'category-shared',
        'scope_id' => null,
        'image_url' => 'https://example.com/shared-v1.png',
        'hero_alt' => 'shared v1',
        'created_at' => now()->subHours(2),
    ]);

    app(ShopHeroGenerator::class)->selectVersion($v1);

    $snapshot->refresh();
    expect($snapshot->shared_category_hero['image_url'])->toBe('https://example.com/shared-v1.png')
        ->and($snapshot->shared_category_hero['hero_alt'])->toBe('shared v1')
        ->and($snapshot->shared_category_hero['width'])->toBe('full');
});

test('selectVersion for category scope updates the category row', function () {
    $site = Site::factory()->create();
    $category = Category::factory()->for($site)->create([
        'hero_image_url' => 'https://example.com/cat-current.png',
        'hero_alt' => 'current',
    ]);

    $v1 = ShopHeroVersion::create([
        'site_id' => $site->id,
        'scope' => 'category',
        'scope_id' => $category->id,
        'image_url' => 'https://example.com/cat-v1.png',
        'hero_alt' => 'cat v1',
        'created_at' => now()->subHours(2),
    ]);

    app(ShopHeroGenerator::class)->selectVersion($v1);

    $category->refresh();
    expect($category->hero_image_url)->toBe('https://example.com/cat-v1.png');
    expect($category->hero_alt)->toBe('cat v1');
});

test('multiple selectVersion calls correctly flip between versions', function () {
    $site = Site::factory()->create();
    $snapshot = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => \App\Enums\Shop\ShopSnapshotStatus::Success,
        'json' => [],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snapshot->id]);

    $v1 = ShopHeroVersion::create([
        'site_id' => $site->id,
        'scope' => 'shop',
        'scope_id' => null,
        'image_url' => 'https://example.com/v1.png',
        'hero_alt' => 'v1',
        'created_at' => now()->subHour(),
    ]);

    $v2 = ShopHeroVersion::create([
        'site_id' => $site->id,
        'scope' => 'shop',
        'scope_id' => null,
        'image_url' => 'https://example.com/v2.png',
        'hero_alt' => 'v2',
        'created_at' => now(),
    ]);

    $generator = app(ShopHeroGenerator::class);
    $latest = fn () => ShopSnapshot::where('site_id', $site->id)->orderByDesc('version')->first();

    $generator->selectVersion($v1);
    expect($latest()->hero_image_url)->toBe('https://example.com/v1.png');

    $generator->selectVersion($v2);
    expect($latest()->hero_image_url)->toBe('https://example.com/v2.png');
});
