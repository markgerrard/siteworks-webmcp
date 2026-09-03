<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\Cart;
use App\Models\Shop\CartItem;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('shop chrome renders a cart link with the current item count', function () {
    $site = Site::factory()->create([
        'custom_domain' => 'cart-chrome.example',
        'custom_domain_status' => 'active',
    ]);
    Product::factory()->published()->for($site)->create();
    $snapshot = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'product_count' => 1, // paired with a real published product below — the counter alone is not trusted
        'json' => ['meta' => ['site_id' => $site->id], 'categories' => [], 'products' => [], 'featured_slugs' => []],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create([
        'site_id' => $site->id,
        'snapshot_id' => $snapshot->id,
        'updated_at' => now(),
    ]);
    $product = Product::factory()->for($site)->create();
    $variant = ProductVariant::factory()->for($product)->create();
    $cart = Cart::create([
        'site_id' => $site->id,
        'session_cookie_id' => 'cart-chrome-session',
        'last_active_at' => now(),
    ]);
    CartItem::create([
        'cart_id' => $cart->id,
        'variant_id' => $variant->id,
        'qty' => 3,
        'unit_price_cents' => $variant->price_cents,
    ]);

    $html = $this->withCookie('shop_session', 'cart-chrome-session')
        ->get('http://cart-chrome.example/shop')
        ->assertSuccessful()
        ->getContent();

    expect($html)->toContain('data-shop-cart-control')
        ->and($html)->toContain('href="/shop/cart"')
        ->and($html)->toMatch('/data-shop-cart-count[^>]*>\s*3\s*</');
});
