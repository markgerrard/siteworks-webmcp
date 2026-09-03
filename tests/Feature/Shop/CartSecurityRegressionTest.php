<?php

use App\Http\Controllers\Shop\CartController;
use App\Models\Shop\Cart;
use App\Models\Shop\CartItem;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\VariantStock;
use App\Models\Site;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// CartController::sessionId() was called twice per add() with no memoisation,
// so a first-time shopper (no cookie yet) got UUID-A for the cart row and UUID-B in
// the Set-Cookie. The cart was created under an id the browser never sends back, so
// the first add-to-cart of every new session was silently orphaned.
test('a first-time shopper: the cart is created under the SAME id that is set in the cookie', function () {
    $site = Site::factory()->create(['custom_domain' => 'flowers.example', 'custom_domain_status' => 'active']);
    $product = Product::factory()->published()->for($site)->create();
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 3000]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 5]);

    // No cookie on the way in — this is the first request of a new shopper.
    $response = $this->post('http://flowers.example/shop/cart/add', [
        'product_slug' => $product->slug,
        'variant_id' => $variant->id,
        'qty' => 1,
    ]);

    $cookie = collect($response->headers->getCookies())
        ->firstWhere(fn ($c) => $c->getName() === CartController::COOKIE_NAME);

    expect($cookie)->not->toBeNull('no cart cookie was set');

    // Laravel encrypts outgoing cookies; compare against the decrypted value.
    $cookieValue = decrypt($cookie->getValue(), false);
    if (is_string($cookieValue) && str_contains($cookieValue, '|')) {
        $cookieValue = explode('|', $cookieValue, 2)[1];  // strip the CookieValuePrefix
    }

    $carts = Cart::all();
    expect($carts)->toHaveCount(1);
    expect($carts->first()->session_cookie_id)->toBe(
        $cookieValue,
        'the cart row id and the cookie value diverged — the first add-to-cart is orphaned'
    );
});

// variant_id was validated as `integer` only. product_slug was checked against
// the site and the result thrown away, so a POST carrying site A's slug with site B's
// variant_id reserved B's stock and charged A's customer B's price.
test('a variant belonging to another site is rejected, and does not touch that site’s stock', function () {
    $siteA = Site::factory()->create(['custom_domain' => 'a.example', 'custom_domain_status' => 'active']);
    $siteB = Site::factory()->create(['custom_domain' => 'b.example', 'custom_domain_status' => 'active']);

    $productA = Product::factory()->for($siteA)->create();
    $variantA = ProductVariant::factory()->for($productA)->create(['price_cents' => 3000]);
    VariantStock::create(['variant_id' => $variantA->id, 'on_hand' => 5]);

    $productB = Product::factory()->for($siteB)->create();
    $variantB = ProductVariant::factory()->for($productB)->create(['price_cents' => 12]);
    VariantStock::create(['variant_id' => $variantB->id, 'on_hand' => 5]);

    $this->post('http://a.example/shop/cart/add', [
        'product_slug' => $productA->slug,   // legitimate for site A
        'variant_id' => $variantB->id,       // belongs to site B
        'qty' => 1,
    ]);

    expect(CartItem::where('variant_id', $variantB->id)->count())
        ->toBe(0, 'site B’s variant was added to a cart on site A');
    expect(VariantStock::where('variant_id', $variantB->id)->value('on_hand'))
        ->toBe(5, 'site B’s stock was decremented by a request to site A');
});

// Second half: a variant that exists on this SITE but belongs to a DIFFERENT
// product than the posted slug must also be rejected: the slug check is meaningless if
// the variant is not bound to it.
test('a variant belonging to a different product on the same site is rejected', function () {
    $site = Site::factory()->create(['custom_domain' => 'flowers.example', 'custom_domain_status' => 'active']);

    $cheap = Product::factory()->published()->for($site)->create();
    $cheapVariant = ProductVariant::factory()->for($cheap)->create(['price_cents' => 12]);
    VariantStock::create(['variant_id' => $cheapVariant->id, 'on_hand' => 5]);

    $dear = Product::factory()->published()->for($site)->create();
    $dearVariant = ProductVariant::factory()->for($dear)->create(['price_cents' => 9900]);
    VariantStock::create(['variant_id' => $dearVariant->id, 'on_hand' => 5]);

    $this->post('http://flowers.example/shop/cart/add', [
        'product_slug' => $dear->slug,        // the expensive product
        'variant_id' => $cheapVariant->id,    // the cheap product's variant
        'qty' => 1,
    ]);

    expect(CartItem::where('variant_id', $cheapVariant->id)->count())
        ->toBe(0, 'a variant not belonging to the posted product was accepted');
});
