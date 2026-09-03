<?php

use App\Models\Shop\Product;
use App\Models\Site;
use App\Support\Shop\ProductFacts;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('new sites default to zero fact groups and products default to null facts', function () {
    $site = Site::factory()->create();
    $product = Product::factory()->for($site)->create();

    expect($site->product_fact_groups)->toBeNull()
        ->and($product->facts)->toBeNull()
        ->and(ProductFacts::groups($site->product_fact_groups))->toBe([]);
});

test('groups and facts round-trip as json without backfill', function () {
    $site = Site::factory()->create();
    $groups = ProductFacts::validateGroups([
        ['slug' => 'specs', 'label' => 'Specs', 'kind' => 'pairs', 'show_on_card' => false, 'schema' => null],
        ['slug' => 'notes', 'label' => 'Notes', 'kind' => 'text', 'show_on_card' => false, 'schema' => null],
    ]);
    $site->update(['product_fact_groups' => $groups]);

    $product = Product::factory()->for($site)->create([
        'facts' => [
            'specs' => ['pairs' => [['label' => 'Width', 'value' => '12']]],
            'notes' => ['text' => 'Handle with care.'],
            'orphan' => ['text' => 'kept in data'],
        ],
    ]);

    $freshSite = $site->fresh();
    $freshProduct = $product->fresh();

    expect($freshSite->product_fact_groups)->toHaveCount(2)
        ->and($freshProduct->facts['orphan']['text'])->toBe('kept in data')
        ->and(ProductFacts::visibleTabs($freshSite->product_fact_groups, $freshProduct->facts))
        ->toHaveCount(2);

    $other = Site::factory()->create();
    expect($other->product_fact_groups)->toBeNull();
});

test('products with values in a slug are counted without deleting on group removal', function () {
    $site = Site::factory()->create([
        'product_fact_groups' => ProductFacts::presetGroups('generic-specifications'),
    ]);
    Product::factory()->for($site)->create([
        'facts' => ['specifications' => ['pairs' => [['label' => 'Width', 'value' => '12']]]],
    ]);
    Product::factory()->for($site)->create([
        'facts' => ['specifications' => ['pairs' => [['label' => 'Width', 'value' => '9']]]],
    ]);
    Product::factory()->for($site)->create([
        'facts' => ['details' => ['text' => 'only the other tab']],
    ]);
    Product::factory()->for($site)->create(['facts' => null]);

    expect(ProductFacts::productsWithValuesCount($site, 'specifications'))->toBe(2);

    $remaining = array_values(array_filter(
        $site->product_fact_groups,
        fn (array $group): bool => $group['slug'] !== 'specifications',
    ));
    $site->update(['product_fact_groups' => $remaining]);

    expect(Product::query()->where('site_id', $site->id)->whereNotNull('facts')->count())->toBe(3)
        ->and(Product::query()->where('site_id', $site->id)->get()->pluck('facts')->filter()->count())->toBe(3);
});
