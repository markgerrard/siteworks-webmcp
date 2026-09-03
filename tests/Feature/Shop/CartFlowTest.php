<?php

use App\Models\Shop\Cart;
use App\Models\Shop\CartItem;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\VariantStock;
use App\Models\Site;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('POST /shop/cart/add adds item to cart and returns 302 to /shop/cart', function () {
    $site = Site::factory()->create(['custom_domain' => 'flowers.example', 'custom_domain_status' => 'active']);
    $product = Product::factory()->published()->for($site)->create();
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 3000]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 5]);

    $response = $this->post('http://flowers.example/shop/cart/add', [
        'product_slug' => $product->slug,
        'variant_id' => $variant->id,
        'qty' => 2,
    ]);

    $response->assertRedirect('http://flowers.example/shop/cart');
    expect(CartItem::where('variant_id', $variant->id)->first()->qty)->toBe(2);
});

test('GET /shop/cart shows items and subtotal', function () {
    $site = Site::factory()->create(['custom_domain' => 'flowers.example', 'custom_domain_status' => 'active']);
    $product = Product::factory()->published()->for($site)->create(['name' => 'Rose']);
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 3000]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 5]);

    // Use an explicit session cookie so both requests share the same cart
    $sessionId = 'test-session-abc';
    $this->withCookie(\App\Http\Controllers\Shop\CartController::COOKIE_NAME, $sessionId)
        ->post('http://flowers.example/shop/cart/add', [
            'product_slug' => $product->slug,
            'variant_id' => $variant->id,
            'qty' => 2,
        ]);

    $this->withCookie(\App\Http\Controllers\Shop\CartController::COOKIE_NAME, $sessionId)
        ->get('http://flowers.example/shop/cart')
        ->assertOk()
        ->assertSee('Rose')
        ->assertSee('£60.00');
});
