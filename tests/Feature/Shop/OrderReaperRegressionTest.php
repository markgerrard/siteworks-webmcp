<?php

use App\Console\Commands\Shop\ExpirePendingOrders;
use App\Enums\Shop\OrderStatus;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShippingRate;
use App\Models\Shop\VariantStock;
use App\Models\Shop\WebhookEvent;
use App\Models\Site;
use App\Services\Shop\CartService;
use App\Services\Shop\CheckoutService;
use App\Services\Shop\StockService;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function reapedOrder(): \App\Models\Shop\Order
{
    test()->seed(\Database\Seeders\Shop\TaxClassSeeder::class);
    test()->seed(\Database\Seeders\Shop\TaxRateSeeder::class);
    $site = Site::factory()->create(['custom_domain' => 'flowers.example', 'custom_domain_status' => 'active']);
    ShippingRate::create(['site_id' => $site->id, 'strategy' => 'flat_with_free_threshold',
        'flat_amount_cents' => 500, 'free_threshold_cents' => null, 'method_label' => 'Std']);
    $product = Product::factory()->published()->for($site)->create();
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 2500]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 5]);
    $cart = app(CartService::class)->getOrCreate($site->id, 'r3-'.uniqid());
    app(CartService::class)->addItem($cart, $variant->id, 1);

    return app(CheckoutService::class)->start($cart, ['name' => 'A', 'email' => 'a@example.com',
        'line1' => '1 St', 'city' => 'L', 'postcode' => 'L1', 'country_code' => 'GB']);
}

// Ordering (a): the reaper selected this order as Pending, but by the time it acts the
// webhook has flipped it to Paid. The reaper must re-check under the lock and NOT overwrite.
test('the reaper does not overwrite an order that was paid after it was selected', function () {
    $order = reapedOrder();
    $order->update(['expires_at' => now()->subHours(2)]);

    // Simulate the webhook winning the race between the reaper\'s select and its write.
    $order->update(['status' => OrderStatus::Paid->value, 'paid_at' => now()]);

    $reaped = app(ExpirePendingOrders::class)->reapOrder($order->id, app(StockService::class));

    expect($reaped)->toBeFalse();
    expect($order->fresh()->status)->toBe(OrderStatus::Paid, 'a paid order was overwritten to cancelled');
});

// Ordering (b): a real payment arrives for an order already cancelled. It must be TERMINAL
// and ACKNOWLEDGED — throwing here makes Stripe retry the same failure forever.
test('a payment for a cancelled order is acknowledged, recorded, and marked terminal', function () {
    Queue::fake();
    $order = reapedOrder();
    $order->update(['status' => OrderStatus::Cancelled->value, 'cancelled_at' => now()]);

    $this->postJson('/shop/webhook/stripe', [
        'id' => 'evt_after_cancel', 'type' => 'checkout.session.completed',
        'data' => ['object' => ['id' => 'cs_x', 'payment_intent' => 'pi_after_cancel',
            'metadata' => ['order_id' => (string) $order->id]]],
    ], ['Stripe-Signature' => 't=0,v1=test'])->assertOk();

    $event = WebhookEvent::find('evt_after_cancel');
    expect($event->processed_at)->not->toBeNull('event was left non-terminal — Stripe would retry forever');
    expect($event->error)->toBe('payment_after_cancellation');
    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    expect($order->fresh()->stripe_payment_intent_id)->toBe('pi_after_cancel', 'payment identity not recorded for reconciliation');
});

// A blank Cloudflare token must not 500 a custom-domain storefront.
test('a custom-domain storefront still serves with a blank Cloudflare token', function () {
    // Site FIRST: Site::creating allocates a preview slug through the Cloudflare
    // service, which (correctly) needs a token. The defect under test is the REQUEST
    // path with an already-existing site.
    $site = Site::factory()->create(['custom_domain' => 'flowers.example', 'custom_domain_status' => 'active']);
    config(['services.cloudflare.token' => '']);

    // A bare factory site is no longer a shop — ShopDomainResolver now 404s shop routes
    // unless the site has something to sell, so give it a catalogue. The defect under
    // test is the CloudflareDomainService guard and the host-only session cookie, not
    // the shop gate.
    \App\Models\Shop\Product::factory()->published()->for($site)->create();
    $response = $this->get('http://flowers.example/shop/cart');
    $response->assertStatus(200);

    // Assert the session cookie is actually emitted, host-only, on this
    // storefront host — a missing cookie here means no usable session.
    $cookies = collect($response->headers->getCookies());
    expect($cookies)->not->toBeEmpty();
    foreach ($cookies as $c) {
        expect($c->getDomain())->toBeNull();
    }
});
