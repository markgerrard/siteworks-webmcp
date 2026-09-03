<?php

use App\Models\Shop\Category;
use App\Models\Shop\ShopHeroVersion;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use App\Services\Shop\ShopHeroGenerator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function fakeHeroResponse(): void
{
    $imageBase64 = base64_encode(str_repeat('x', 100));
    Http::fake([
        '*/generate-hero' => Http::response([
            'data' => [
                'image_base64' => $imageBase64,
                'placement' => [],
                'model' => 'demo-test',
                'prompt_used' => 'test prompt',
            ],
        ]),
    ]);
    Storage::fake('s3');
}

test('selectVersion flips current shop hero to a past version', function () {
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

    $pastVersion = ShopHeroVersion::create([
        'site_id' => $site->id,
        'scope' => 'shop',
        'scope_id' => null,
        'image_url' => 'https://example.com/old.png',
        'hero_alt' => 'old hero',
        'created_at' => now()->subDay(),
    ]);

    $generator = app(ShopHeroGenerator::class);
    $generator->selectVersion($pastVersion);

    $snapshot->refresh();
    expect($snapshot->hero_image_url)->toBe('https://example.com/old.png');
});

test('selectVersion flips current category-shared hero to a past version', function () {
    $site = Site::factory()->create();

    $snapshot = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => \App\Enums\Shop\ShopSnapshotStatus::Success,
        'json' => [],
        'built_at' => now(),
        'shared_category_hero' => [
            'image_url' => 'https://example.com/current-shared.png',
            'hero_alt' => 'current shared',
            'height' => 'medium',
        ],
    ]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snapshot->id]);

    $pastVersion = ShopHeroVersion::create([
        'site_id' => $site->id,
        'scope' => 'category-shared',
        'scope_id' => null,
        'image_url' => 'https://example.com/old-shared.png',
        'hero_alt' => 'old shared hero',
        'created_at' => now()->subDay(),
    ]);

    app(ShopHeroGenerator::class)->selectVersion($pastVersion);

    $snapshot->refresh();
    expect($snapshot->shared_category_hero['image_url'])->toBe('https://example.com/old-shared.png')
        ->and($snapshot->shared_category_hero['hero_alt'])->toBe('old shared hero')
        ->and($snapshot->shared_category_hero['height'])->toBe('medium');
});

test('selectVersion flips current category hero to a past version', function () {
    $site = Site::factory()->create();
    $category = Category::factory()->for($site)->create(['hero_image_url' => 'https://example.com/current.png']);

    $pastVersion = ShopHeroVersion::create([
        'site_id' => $site->id,
        'scope' => 'category',
        'scope_id' => $category->id,
        'image_url' => 'https://example.com/old-cat.png',
        'hero_alt' => 'old cat hero',
        'created_at' => now()->subDay(),
    ]);

    $generator = app(ShopHeroGenerator::class);
    $generator->selectVersion($pastVersion);

    $category->refresh();
    expect($category->hero_image_url)->toBe('https://example.com/old-cat.png');
});
