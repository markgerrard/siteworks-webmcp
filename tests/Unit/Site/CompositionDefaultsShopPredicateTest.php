<?php

// Pins the NEGATIVE side of CompositionDefaults' shop predicate (a ShopSnapshotCurrent row
// is not a shop; only something purchasable is) and the nav-order total ordering
// (sort_order, then id). The positive case lives in CompositionDefaultsTest but its fixture
// satisfies both the old and the new predicate, so it cannot discriminate on its own.

use App\Enums\PageStatus;
use App\Enums\Shop\ProductStatus;
use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\GeneratedPage;
use App\Models\Shop\Product;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use App\Services\Site\CompositionDefaults;


function shoplessSite(string $host): Site
{
    return Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
    ]);
}

/** A stale snapshot row: it exists, and its counter can even be non-zero. */
function staleSnapshotRow(Site $site, int $productCount, array $products = []): void
{
    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'product_count' => $productCount,
        'json' => [
            'meta' => ['site_id' => $site->id],
            'categories' => [],
            'products' => $products,
            'featured_slugs' => [],
        ],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create([
        'site_id' => $site->id,
        'snapshot_id' => $snap->id,
        'updated_at' => now(),
    ]);
}

function defaultNavItems( Site $site): array
{
    return app(CompositionDefaults::class)->forSite($site)['nav']['items'] ?? [];
}

test('a stale snapshot row on a shopless site does not buy a Shop nav item', function () {
    $site = shoplessSite('cd-stale-row.example');
    GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'sort_order' => 0]);
    GeneratedPage::factory()->for($site)->create(['page_type' => 'about', 'nav_label' => 'About', 'sort_order' => 1]);
    GeneratedPage::factory()->for($site)->create(['page_type' => 'contact', 'nav_label' => 'Contact', 'sort_order' => 99]);

    staleSnapshotRow($site, productCount: 0);

    expect($site->fresh()->hasPurchasableShop())->toBeFalse()
        ->and(ShopSnapshotCurrent::where('site_id', $site->id)->exists())->toBeTrue()
        ->and(array_column(defaultNavItems($site), 'type'))->not->toContain('shop');
});

test('a draft-inclusive product_count does not buy a Shop nav item', function () {
    $site = shoplessSite('cd-draft-count.example');
    GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'sort_order' => 0]);
    GeneratedPage::factory()->for($site)->create(['page_type' => 'contact', 'nav_label' => 'Contact', 'sort_order' => 99]);
    Product::factory()->for($site)->create(['status' => ProductStatus::Draft]);

    // Exactly what SnapshotBuilder writes for a draft-only catalogue: counter 1, every
    // product in the payload Draft.
    staleSnapshotRow($site, productCount: 1, products: [
        ['slug' => 'draft-thing', 'status' => 'draft', 'price_cents' => 500],
    ]);

    expect($site->fresh()->hasPurchasableShop())->toBeFalse()
        ->and(array_column(defaultNavItems($site), 'type'))->not->toContain('shop');
});

test('a published product does buy a Shop nav item (control)', function () {
    $site = shoplessSite('cd-real-shop.example');
    GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'sort_order' => 0]);
    GeneratedPage::factory()->for($site)->create(['page_type' => 'contact', 'nav_label' => 'Contact', 'sort_order' => 99]);
    Product::factory()->for($site)->create(['status' => ProductStatus::Published]);
    staleSnapshotRow($site, productCount: 1, products: [
        ['slug' => 'real-thing', 'status' => 'published', 'price_cents' => 500],
    ]);

    $labels = array_column(defaultNavItems($site), 'label');

    expect($site->fresh()->hasPurchasableShop())->toBeTrue()
        ->and($labels)->toContain('Shop')
        ->and(array_search('Shop', $labels, true))->toBeLessThan(array_search('Contact', $labels, true));
});

test('the pages query orders by sort_order then id, so ties are a total order', function () {
    // Postgres returns tied rows in heap order within one connection, so a two-call
    // in-process probe cannot see the old non-determinism (cross-connection plan
    // variance was how the flake surfaced). Pin the query shape instead: the ORDER BY
    // must carry the id tiebreaker. Dropping ->orderBy('id') turns this red.
    $site = shoplessSite('cd-order-shape.example');
    GeneratedPage::factory()->for($site)->create(['page_type' => 'about', 'nav_label' => 'About', 'sort_order' => 5]);
    GeneratedPage::factory()->for($site)->create(['page_type' => 'contact', 'nav_label' => 'Contact', 'sort_order' => 5]);

    \Illuminate\Support\Facades\DB::enableQueryLog();
    defaultNavItems($site);
    $orderClauses = collect(\Illuminate\Support\Facades\DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql): bool => str_contains($sql, 'generated_pages') && stripos($sql, 'order by') !== false)
        ->map(fn (string $sql): string => strtolower(substr($sql, stripos($sql, 'order by'))))
        ->values();
    \Illuminate\Support\Facades\DB::disableQueryLog();

    expect($orderClauses)->not->toBeEmpty();
    foreach ($orderClauses as $clause) {
        expect($clause)->toContain('sort_order')
            ->and(preg_match('/"?sort_order"?\s+asc\s*,\s*"?id"?\s+asc/', $clause))->toBe(1, "tiebreaker missing: {$clause}");
    }
});
