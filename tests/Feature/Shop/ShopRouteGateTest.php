<?php

use App\Enums\Shop\ProductStatus;
use App\Enums\Shop\ShopSnapshotStatus;
use App\Enums\Shop\OrderStatus;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The initial reconcile gave EVERY site a ShopSnapshotCurrent row. ShopDomainResolver
 * gated the shop routes on a site resolving by host — never on that site actually being
 * a shop — so every customer site served /shop, /shop/cart and /shop/account/login off
 * an empty catalogue. Same root cause as the nav-entry defect: snapshot existence stopped
 * meaning anything once everything had one.
 */
function makeSiteWithSnapshot(string $host, int $productCount): Site
{
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
    ]);

    if ($productCount > 0) {
        $product = Product::factory()->for($site)->create(['status' => ProductStatus::Published]);
        $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 900]);
        VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 5]);
    }

    $snapshot = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'product_count' => $productCount,
        'json' => ['meta' => ['site_id' => $site->id], 'categories' => [], 'products' => [], 'featured_slugs' => []],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create([
        'site_id' => $site->id,
        'snapshot_id' => $snapshot->id,
        'updated_at' => now(),
    ]);

    return $site;
}

test('a site with a snapshot but an empty catalogue does not serve the shop', function () {
    makeSiteWithSnapshot('empty-shop.example', productCount: 0);

    // BROWSE surfaces close: there is nothing to sell.
    foreach (['/shop', '/shop/cart', '/shop/checkout', '/shop/search?q=x'] as $path) {
        $this->get('http://empty-shop.example'.$path)
            ->assertNotFound("{$path} must 404 on a site with nothing to sell");
    }

    // Customer surfaces close TOO, because a snapshot row is not a shop: a site can
    // have a snapshot row without ever having an actual shop. Keeping them open on
    // snapshot existence alone would leave an unauthenticated endpoint reachable on
    // every such site that creates a Customer and queues mail to an
    // attacker-supplied address.
    $this->get('http://empty-shop.example/shop/account/login')->assertNotFound();
});

test('a site with a real catalogue still serves the shop', function () {
    makeSiteWithSnapshot('real-shop.example', productCount: 1);

    $this->get('http://real-shop.example/shop')->assertOk();
    $this->get('http://real-shop.example/shop/cart')->assertOk();
    $this->get('http://real-shop.example/shop/account/login')->assertOk();
});

test('the Stripe webhook is NOT behind the shop gate', function () {
    // The webhook is registered outside the shop.domain group and resolves its order from
    // the Stripe session's metadata, not from the host. Gating it would silently drop
    // payment notifications for a shop whose last product was unpublished mid-flight —
    // the customer is charged and the order never completes.
    makeSiteWithSnapshot('gone-shop.example', productCount: 0);

    // In the testing env the controller skips signature verification, so assert the
    // property that actually matters — the route is REACHED, not 404'd by the gate.
    $this->post('http://gone-shop.example/shop/webhook/stripe', ['id' => 'evt_test', 'type' => 'ping'])
        ->assertOk();
});

test('a site holding only draft products is not a shop', function () {
    // Regression guard: the catalogue-rows
    // branch had no status filter, so a draft-only site opened /shop and grew shop
    // chrome while RenderContext stripped every draft at read time — an empty public
    // storefront, the very bug the predicate exists to prevent, by another door.
    $site = Site::factory()->create([
        'custom_domain' => 'draft-only.example',
        'custom_domain_status' => 'active',
    ]);
    $product = Product::factory()->for($site)->create(['status' => ProductStatus::Draft]);
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 500]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 1]);

    expect($site->fresh()->hasPurchasableShop())->toBeFalse();
    $this->get('http://draft-only.example/shop')->assertNotFound();

    // Publishing it makes the site a shop.
    $product->update(['status' => ProductStatus::Published]);
    expect($site->fresh()->hasPurchasableShop())->toBeTrue();
});

test('a draft-only catalogue does not become a shop once its snapshot rebuilds', function () {
    // The published-only filter covered the live-rows branch but the
    // snapshot branches trusted shop_snapshots.product_count, which SnapshotBuilder makes
    // draft-INCLUSIVE by construction. So a draft-only site was false before its rebuild
    // and true after — shop chrome and a 200 over a storefront RenderContext then emptied.
    // The earlier regression test passed only because it never rebuilt the snapshot.
    $site = Site::factory()->create([
        'custom_domain' => 'draft-rebuild.example',
        'custom_domain_status' => 'active',
    ]);
    $product = Product::factory()->for($site)->create(['status' => ProductStatus::Draft]);
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 500]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 2]);

    expect($site->fresh()->hasPurchasableShop())->toBeFalse();

    (new \App\Jobs\Shop\RebuildShopSnapshot($site->id))->handle(app(\App\Services\Shop\SnapshotBuilder::class));

    expect($site->fresh()->hasPurchasableShop())->toBeFalse('a rebuilt draft-only snapshot must not create a shop');
    $this->get('http://draft-rebuild.example/shop')->assertNotFound();
});

test('an established shop keeps its customer surfaces after the last product is archived', function () {
    // Current sellability was being used as durable shop identity, so
    // archiving the last product 404'd the return URL of a shopper who had just been
    // charged and locked existing customers out of their own order history.
    $site = Site::factory()->create([
        'custom_domain' => 'archived-shop.example',
        'custom_domain_status' => 'active',
    ]);
    $product = Product::factory()->published()->for($site)->create();
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 900]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 1]);
    (new \App\Jobs\Shop\RebuildShopSnapshot($site->id))->handle(app(\App\Services\Shop\SnapshotBuilder::class));

    // A real customer has bought something. This is what makes the shop ESTABLISHED —
    // the finding was about people who have PAID, and a shop that never sold anything
    // and now sells nothing has no customer to protect. A snapshot row is not evidence of
    // either, which is why it is no longer a disjunct.
    Order::create([
        'site_id' => $site->id, 'number' => 'ARCH-0001',
        'email' => 'buyer@example.com', 'name' => 'Buyer',
        'status' => OrderStatus::Paid->value, 'refund_status' => 'none',
        'subtotal_cents' => 900, 'shipping_cents' => 0, 'tax_cents' => 0,
        'shipping_tax_cents' => 0, 'total_cents' => 900,
        'tax_country_code' => 'GB', 'shipping_address_json' => [],
        'shipping_method_label' => 'Std', 'placed_at' => now(),
    ]);

    // The merchant archives their only product.
    $product->update(['status' => ProductStatus::Archived]);
    (new \App\Jobs\Shop\RebuildShopSnapshot($site->id))->handle(app(\App\Services\Shop\SnapshotBuilder::class));

    expect($site->fresh()->hasPurchasableShop())->toBeFalse()
        ->and($site->fresh()->hasEstablishedShop())->toBeTrue();

    // Browse is correctly closed...
    $this->get('http://archived-shop.example/shop')->assertNotFound();

    // ...but everything a paying customer is owed still works.
    $this->get('http://archived-shop.example/shop/checkout/success')->assertOk();
    $this->get('http://archived-shop.example/shop/checkout/cancel')->assertOk();
    $this->get('http://archived-shop.example/shop/account/login')->assertOk();
});
