<?php

use App\Enums\Shop\ProductStatus;
use App\Models\Shop\Product;
use App\Models\Site;
use App\Services\Shop\ProductSearchService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('full-text search matches on name', function () {
    $site = Site::factory()->create();
    Product::factory()->for($site)->create(['name' => 'Red Rose Bouquet', 'description' => 'Long-stemmed beauties', 'status' => ProductStatus::Published]);
    Product::factory()->for($site)->create(['name' => 'White Lily', 'status' => ProductStatus::Published]);

    $results = app(ProductSearchService::class)->search($site->id, 'rose');
    expect($results)->toHaveCount(1);
    expect($results[0]->name)->toBe('Red Rose Bouquet');
});

test('full-text search matches on description', function () {
    $site = Site::factory()->create();
    Product::factory()->for($site)->create(['name' => 'X', 'description' => 'perfect for birthdays', 'status' => ProductStatus::Published]);

    $results = app(ProductSearchService::class)->search($site->id, 'birthday');
    expect($results)->toHaveCount(1);
});

test('excludes archived products', function () {
    $site = Site::factory()->create();
    Product::factory()->for($site)->create(['name' => 'Ancient Tulip', 'status' => ProductStatus::Archived]);

    $results = app(ProductSearchService::class)->search($site->id, 'tulip');
    expect($results)->toBeEmpty();
});

test('excludes drafts when includeDrafts=false', function () {
    $site = Site::factory()->create();
    Product::factory()->for($site)->create(['name' => 'Hidden Draft', 'status' => ProductStatus::Draft]);

    $results = app(ProductSearchService::class)->search($site->id, 'hidden', includeDrafts: false);
    expect($results)->toBeEmpty();
});

test('includes drafts when includeDrafts=true', function () {
    $site = Site::factory()->create();
    Product::factory()->for($site)->create(['name' => 'Hidden Draft', 'status' => ProductStatus::Draft]);

    $results = app(ProductSearchService::class)->search($site->id, 'hidden', includeDrafts: true);
    expect($results)->toHaveCount(1);
});

test('includeDrafts false never returns an agent-created draft (G2)', function () {
    $site = Site::factory()->create();
    Product::factory()->for($site)->create([
        'name' => 'Agent Seeded Candle',
        'description' => 'Hand-poured soy',
        'status' => ProductStatus::Draft,
        'is_ai_seeded' => true,
        'is_ai_reviewed' => false,
        'ai_seed_source' => 'agent_tool',
    ]);
    Product::factory()->for($site)->published()->create([
        'name' => 'Published Candle',
        'description' => 'Hand-poured soy',
    ]);

    $hidden = app(ProductSearchService::class)->search($site->id, 'candle', includeDrafts: false);
    $withDrafts = app(ProductSearchService::class)->search($site->id, 'candle', includeDrafts: true);

    expect($hidden)->toHaveCount(1)
        ->and($hidden->first()->name)->toBe('Published Candle')
        ->and($withDrafts->pluck('name')->all())->toContain('Agent Seeded Candle', 'Published Candle');
});
