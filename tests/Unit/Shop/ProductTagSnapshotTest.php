<?php

use App\Enums\Shop\ProductStatus;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Services\Shop\SnapshotBuilder;
use App\Support\Shop\AutoTagConfig;
use App\Support\Shop\ProductTagResolver;
use App\Support\Shop\ProductTagVocabulary;

it('orders snapshot tags manual-first then auto, deduped, using site vocabulary order', function () {
    $vocab = ProductTagVocabulary::parse([
        ['slug' => 'seasonal', 'label' => 'Seasonal', 'show_as_badge' => true, 'tone' => 'warning'],
        ['slug' => 'same-day', 'label' => 'Same day', 'show_as_badge' => true, 'tone' => 'accent'],
    ]);
    $auto = AutoTagConfig::parse([
        'new' => ['enabled' => true, 'label' => 'New', 'show_as_badge' => true, 'tone' => 'success'],
        'best-seller' => ['enabled' => true, 'label' => 'Best seller', 'show_as_badge' => true, 'tone' => 'accent'],
    ]);

    $resolved = ProductTagResolver::resolve(
        $vocab,
        ['same-day', 'ghost', 'new'],
        ['best-seller', 'new'],
        $auto,
    );

    expect($resolved)->toEqual([
        ['slug' => 'same-day', 'label' => 'Same day', 'badge' => true, 'tone' => 'accent'],
        ['slug' => 'best-seller', 'label' => 'Best seller', 'badge' => true, 'tone' => 'accent'],
        ['slug' => 'new', 'label' => 'New', 'badge' => true, 'tone' => 'success'],
    ]);
});

it('snapshot product payload includes resolved tags and ignores unknown stored slugs', function () {
    $site = Site::factory()->create(['shop_mode' => 'cart']);
    $site->forceFill([
        'product_tags' => [
            ['slug' => 'same-day', 'label' => 'Same day', 'show_as_badge' => true, 'tone' => 'accent'],
            ['slug' => 'seasonal', 'label' => 'Seasonal', 'show_as_badge' => false, 'tone' => 'neutral'],
        ],
        'auto_tags' => AutoTagConfig::parse([
            'made-to-order' => ['enabled' => true, 'label' => 'Made to order', 'show_as_badge' => true, 'tone' => 'neutral'],
        ]),
    ])->save();

    $product = Product::factory()->for($site)->create([
        'slug' => 'item',
        'status' => ProductStatus::Published,
        'price_from' => true,
        'tags' => ['seasonal', 'not-a-tag', 'same-day'],
    ]);
    $variant = ProductVariant::factory()->for($product)->create();
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 4]);

    $json = app(SnapshotBuilder::class)->build($site->id);
    $tags = $json['products']['item']['tags'];

    expect($tags)->toEqual([
        ['slug' => 'same-day', 'label' => 'Same day', 'badge' => true, 'tone' => 'accent'],
        ['slug' => 'seasonal', 'label' => 'Seasonal', 'badge' => false, 'tone' => 'neutral'],
        ['slug' => 'made-to-order', 'label' => 'Made to order', 'badge' => true, 'tone' => 'neutral'],
    ]);
});

it('snapshot products with no tags receive an empty tags list', function () {
    $site = Site::factory()->create();
    $product = Product::factory()->for($site)->create(['slug' => 'plain']);
    $variant = ProductVariant::factory()->for($product)->create();
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 1]);

    $json = app(SnapshotBuilder::class)->build($site->id);

    expect($json['products']['plain']['tags'])->toBe([]);
});
