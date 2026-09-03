<?php

use App\Enums\Shop\ProductStatus;
use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Services\Shop\RenderContext;
use App\Services\Shop\SnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Five published products, two categories (cakes → wedding-cakes).
 *
 * @return array{site: Site, json: array<string, mixed>}
 */
function snapshotFacetsCatalogue(): array
{
    $site = Site::factory()->create(['shop_currency' => 'GBP']);
    $cakes = Category::factory()->create([
        'site_id' => $site->id,
        'slug' => 'cakes',
        'name' => 'Cakes',
        'path' => 'cakes',
        'depth' => 1,
        'is_anchor' => true,
        'sort_order' => 1,
    ]);
    $wedding = Category::factory()->create([
        'site_id' => $site->id,
        'parent_id' => $cakes->id,
        'slug' => 'wedding-cakes',
        'name' => 'Wedding Cakes',
        'path' => 'cakes/wedding-cakes',
        'depth' => 2,
        'is_anchor' => true,
        'sort_order' => 1,
    ]);

    $victoria = snapshotFacetsProduct($site, 'victoria', 1000, false, 4, ['6"', '8"']);
    $lemon = snapshotFacetsProduct($site, 'lemon', 2000, false, 4, ['6"', '8"']);
    $chocolate = snapshotFacetsProduct($site, 'chocolate', 3000, false, 4, ['6"', '8"']);
    $naked = snapshotFacetsProduct($site, 'naked', 4000, false, 2, ['8"', '10"']);
    $threeTier = snapshotFacetsProduct($site, 'three-tier', 8000, true, 0, ['8"', '10"']);

    $victoria->categories()->attach($cakes->id, ['is_primary' => true]);
    $lemon->categories()->attach($cakes->id, ['is_primary' => true]);
    $chocolate->categories()->attach($cakes->id, ['is_primary' => true]);
    $naked->categories()->attach($wedding->id, ['is_primary' => true]);
    $threeTier->categories()->attach($wedding->id, ['is_primary' => true]);

    $json = app(SnapshotBuilder::class)->build($site->id);

    return ['site' => $site, 'json' => $json];
}

/**
 * @param  list<string>  $labels
 */
function snapshotFacetsProduct(Site $site, string $slug, int $priceCents, bool $priceFrom, int $onHand, array $labels): Product
{
    $product = Product::factory()->for($site)->create([
        'slug' => $slug,
        'name' => ucfirst($slug),
        'status' => ProductStatus::Published,
        'price_from' => $priceFrom,
    ]);

    foreach ($labels as $i => $label) {
        $variant = ProductVariant::factory()->for($product)->create([
            'sku' => strtoupper($slug).'-'.$i,
            'label' => $label,
            'price_cents' => $priceCents + ($i * 50),
        ]);
        VariantStock::create(['variant_id' => $variant->id, 'on_hand' => $onHand]);
    }

    return $product;
}

test('snapshot includes site-level and per-category facets plus compact product f', function () {
    ['json' => $json] = snapshotFacetsCatalogue();

    expect($json)->toHaveKey('facets')
        ->and($json['facets']['category'])->toBe([
            ['slug' => 'cakes', 'name' => 'Cakes', 'count' => 5],
        ])
        ->and($json['facets']['price'])->toHaveCount(4)
        ->and($json['facets']['price'][0])->toMatchArray([
            'id' => 0,
            'min' => 0,
            'max' => 2000,
            'label' => 'Under £20.00',
            'count' => 1,
        ])
        ->and($json['facets']['price'][1])->toMatchArray([
            'id' => 1,
            'min' => 2000,
            'max' => 3000,
            'label' => '£20.00–£30.00',
            'count' => 1,
        ])
        ->and($json['facets']['price'][2])->toMatchArray([
            'id' => 2,
            'min' => 3000,
            'max' => 4000,
            'label' => '£30.00–£40.00',
            'count' => 1,
        ])
        ->and($json['facets']['price'][3])->toMatchArray([
            'id' => 3,
            'min' => 4000,
            'max' => null,
            'label' => '£40.00+',
            'count' => 2,
        ])
        ->and($json['facets']['availability'])->toBe([
            ['id' => 'in', 'label' => 'In stock', 'count' => 4],
            ['id' => 'mto', 'label' => 'Made to order', 'count' => 1],
        ])
        ->and($json['facets']['options'])->toBe([
            ['id' => '8in', 'label' => '8"', 'count' => 5],
            ['id' => '6in', 'label' => '6"', 'count' => 3],
        ]);

    expect($json['categories']['cakes']['facets']['category'])->toBe([
        ['slug' => 'wedding-cakes', 'name' => 'Wedding Cakes', 'count' => 2],
    ]);
    expect($json['categories']['wedding-cakes']['facets']['category'])->toBe([]);
    expect($json['categories']['wedding-cakes']['facets']['availability'])->toBe([
        ['id' => 'in', 'label' => 'In stock', 'count' => 1],
        ['id' => 'mto', 'label' => 'Made to order', 'count' => 1],
    ]);
    expect($json['categories']['wedding-cakes']['facets']['options'])->toBe([
        ['id' => '8in', 'label' => '8"', 'count' => 2],
    ]);

    expect($json['products']['victoria']['f'])->toBe([
        'c' => ['cakes'],
        'p' => 1000,
        'a' => 'in',
        'o' => ['6in', '8in'],
    ]);
    expect($json['products']['three-tier']['f'])->toBe([
        'c' => ['cakes', 'wedding-cakes'],
        'p' => 8000,
        'a' => 'mto',
        'o' => ['8in'],
    ]);
    expect($json['products']['naked']['f']['c'])->toBe(['cakes', 'wedding-cakes']);
});

test('options facet skips Default and single-variant labels and caps at eight values', function () {
    $site = Site::factory()->create();
    $category = Category::factory()->create([
        'site_id' => $site->id,
        'slug' => 'cakes',
        'name' => 'Cakes',
        'path' => 'cakes',
        'depth' => 1,
    ]);

    foreach (range(1, 3) as $n) {
        $lonely = Product::factory()->for($site)->create([
            'slug' => 'lonely-'.$n,
            'status' => ProductStatus::Published,
        ]);
        $variant = ProductVariant::factory()->for($lonely)->create(['label' => 'Jar', 'price_cents' => 500]);
        VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 1]);
        $lonely->categories()->attach($category->id, ['is_primary' => true]);
    }

    foreach (range(1, 2) as $n) {
        $placeholder = Product::factory()->for($site)->create([
            'slug' => 'defaulted-'.$n,
            'status' => ProductStatus::Published,
        ]);
        foreach (['Default', 'Hidden'] as $i => $label) {
            $variant = ProductVariant::factory()->for($placeholder)->create([
                'label' => $label,
                'price_cents' => 600,
                'sku' => 'DEF-'.$n.'-'.$i,
            ]);
            VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 1]);
        }
        $placeholder->categories()->attach($category->id, ['is_primary' => true]);
    }

    foreach (range(1, 3) as $n) {
        $sized = Product::factory()->for($site)->create([
            'slug' => 'sized-'.$n,
            'status' => ProductStatus::Published,
        ]);
        foreach (range(1, 9) as $i) {
            $variant = ProductVariant::factory()->for($sized)->create([
                'label' => 'Size '.$i,
                'price_cents' => 1000 + $i,
                'sku' => 'SZ-'.$n.'-'.$i,
            ]);
            VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 1]);
        }
        $sized->categories()->attach($category->id, ['is_primary' => true]);
    }

    $json = app(SnapshotBuilder::class)->build($site->id);
    $ids = array_column($json['facets']['options'], 'id');

    expect($ids)->toHaveCount(8)
        ->and($ids)->not->toContain('jar')
        ->and($ids)->not->toContain('default')
        ->and($ids)->not->toContain('hidden')
        ->and($ids)->toBe(['size-1', 'size-2', 'size-3', 'size-4', 'size-5', 'size-6', 'size-7', 'size-8'])
        ->and($json['products']['sized-1']['f']['o'])->toBe([
            'size-1', 'size-2', 'size-3', 'size-4', 'size-5', 'size-6', 'size-7', 'size-8',
        ]);
});

test('price buckets never emit an empty Under £0.00 bucket', function () {
    $site = Site::factory()->create(['shop_currency' => 'GBP']);
    $category = Category::factory()->create([
        'site_id' => $site->id,
        'slug' => 'cakes',
        'name' => 'Cakes',
        'path' => 'cakes',
        'depth' => 1,
    ]);

    foreach ([0, 0, 2000, 4000] as $i => $cents) {
        $product = Product::factory()->for($site)->create([
            'slug' => 'zero-'.$i,
            'status' => ProductStatus::Published,
        ]);
        $variant = ProductVariant::factory()->for($product)->create([
            'label' => 'Std',
            'price_cents' => $cents,
        ]);
        VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 1]);
        $product->categories()->attach($category->id, ['is_primary' => true]);
    }

    $json = app(SnapshotBuilder::class)->build($site->id);
    $labels = array_column($json['facets']['price'], 'label');
    $bounds = array_map(
        fn (array $bucket): array => [$bucket['min'], $bucket['max']],
        $json['facets']['price'],
    );

    expect($labels)->not->toContain('Under £0.00')
        ->and($bounds)->not->toContain([0, 0]);
});

test('quartile edges round to sensible money', function () {
    $site = Site::factory()->create(['shop_currency' => 'GBP']);
    $category = Category::factory()->create([
        'site_id' => $site->id,
        'slug' => 'cakes',
        'name' => 'Cakes',
        'path' => 'cakes',
        'depth' => 1,
    ]);

    foreach ([1234, 1234, 4567, 4567, 8901, 8901, 23456, 23456] as $i => $cents) {
        $product = Product::factory()->for($site)->create([
            'slug' => 'p-'.$i,
            'status' => ProductStatus::Published,
        ]);
        $variant = ProductVariant::factory()->for($product)->create(['label' => 'Std', 'price_cents' => $cents]);
        VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 1]);
        $product->categories()->attach($category->id, ['is_primary' => true]);
    }

    $json = app(SnapshotBuilder::class)->build($site->id);
    $edges = array_map(fn (array $bucket) => $bucket['max'], array_slice($json['facets']['price'], 0, 3));

    expect($edges)->toBe([1200, 4500, 9000])
        ->and($json['facets']['price'][0]['label'])->toBe('Under £12.00')
        ->and($json['facets']['price'][3]['min'])->toBe(9000)
        ->and($json['facets']['price'][3]['max'])->toBeNull();
});

test('filterSnapshot recounts facet counts after dropping drafts', function () {
    ['json' => $json] = snapshotFacetsCatalogue();

    $json['products']['victoria']['status'] = 'draft';

    $filtered = (new RenderContext(includeDrafts: false))->filterSnapshot($json);

    expect($filtered['products'])->not->toHaveKey('victoria')
        ->and($filtered['facets']['category'][0]['count'])->toBe(4)
        ->and($filtered['facets']['availability'])->toBe([
            ['id' => 'in', 'label' => 'In stock', 'count' => 3],
            ['id' => 'mto', 'label' => 'Made to order', 'count' => 1],
        ])
        ->and($filtered['facets']['options'])->toBe([
            ['id' => '8in', 'label' => '8"', 'count' => 4],
            ['id' => '6in', 'label' => '6"', 'count' => 2],
        ])
        ->and($filtered['facets']['price'][0]['count'])->toBe(0)
        ->and($filtered['categories']['cakes']['facets']['category'][0]['count'])->toBe(2);
});

/**
 * @return array{before: int, after: int, json: array<string, mixed>}
 */
function snapshotFacetSizeReport(int $productCount): array
{
    $site = Site::factory()->create(['shop_currency' => 'GBP']);
    $cakes = Category::factory()->create([
        'site_id' => $site->id,
        'slug' => 'cakes',
        'name' => 'Cakes',
        'path' => 'cakes',
        'depth' => 1,
        'is_anchor' => true,
        'sort_order' => 1,
    ]);
    $patisserie = Category::factory()->create([
        'site_id' => $site->id,
        'slug' => 'patisserie',
        'name' => 'Patisserie',
        'path' => 'patisserie',
        'depth' => 1,
        'is_anchor' => true,
        'sort_order' => 2,
    ]);

    for ($i = 0; $i < $productCount; $i++) {
        $product = Product::factory()->for($site)->create([
            'slug' => 'item-'.$i,
            'status' => ProductStatus::Published,
            'price_from' => $i % 7 === 0,
        ]);
        foreach (['6"', '8"', '10"'] as $j => $label) {
            $variant = ProductVariant::factory()->for($product)->create([
                'sku' => 'IT-'.$i.'-'.$j,
                'label' => $label,
                'price_cents' => 800 + ($i * 40) + ($j * 100),
            ]);
            VariantStock::create([
                'variant_id' => $variant->id,
                'on_hand' => $i % 5 === 0 ? 0 : 4,
            ]);
        }
        $product->categories()->attach(($i % 2 === 0 ? $cakes : $patisserie)->id, ['is_primary' => true]);
    }

    $json = app(SnapshotBuilder::class)->build($site->id);
    $after = strlen((string) json_encode($json));
    $stripped = $json;
    unset($stripped['facets']);
    foreach ($stripped['categories'] as $slug => $cat) {
        unset($stripped['categories'][$slug]['facets']);
    }
    foreach ($stripped['products'] as $slug => $product) {
        unset($stripped['products'][$slug]['f']);
    }
    $before = strlen((string) json_encode($stripped));

    return ['before' => $before, 'after' => $after, 'json' => $json];
}

test('facet payload grows Camino-sized and Berry-sized snapshots by a bounded amount', function () {
    $berry = snapshotFacetSizeReport(5);
    $camino = snapshotFacetSizeReport(103);

    fwrite(STDERR, sprintf(
        "snapshot-size berry=%d->%d camino=%d->%d camino-facets=%s\n",
        $berry['before'],
        $berry['after'],
        $camino['before'],
        $camino['after'],
        json_encode($camino['json']['facets']),
    ));

    expect($berry['after'])->toBeGreaterThan($berry['before'])
        ->and($camino['after'])->toBeGreaterThan($camino['before'])
        ->and($camino['json']['facets']['options'])->toHaveCount(3)
        ->and(array_column($camino['json']['facets']['options'], 'id'))->toBe(['10in', '6in', '8in'])
        ->and($camino['after'] / $camino['before'])->toBeLessThan(1.25);
});
