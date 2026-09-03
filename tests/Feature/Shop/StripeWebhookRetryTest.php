<?php

use App\Enums\Shop\OrderStatus;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShippingRate;
use App\Models\Shop\VariantStock;
use App\Models\Shop\WebhookEvent;
use App\Models\Site;
use App\Services\Shop\CartService;
use App\Services\Shop\CheckoutService;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function makeRetryOrder(): Order
{
    test()->seed(\Database\Seeders\Shop\TaxClassSeeder::class);
    test()->seed(\Database\Seeders\Shop\TaxRateSeeder::class);

    $site = Site::factory()->create(['custom_domain' => 'flowers.example']);
    ShippingRate::create([
        'site_id' => $site->id, 'strategy' => 'flat_with_free_threshold',
        'flat_amount_cents' => 500, 'free_threshold_cents' => null, 'method_label' => 'Std',
    ]);
    $product = Product::factory()->for($site)->create();
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 2500]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 10]);
    $cart = app(CartService::class)->getOrCreate($site->id, 'sess-retry');
    app(CartService::class)->addItem($cart, $variant->id, 2);

    return app(CheckoutService::class)->start($cart, [
        'name' => 'Jane', 'email' => 'jane@example.com',
        'line1' => '1 High St', 'city' => 'L', 'postcode' => 'L1', 'country_code' => 'GB',
    ]);
}

// The idempotency guard tested ROW EXISTENCE, and the row was written BEFORE
// processing. So a transient failure during dispatch left a row with processed_at NULL
// and returned 500; Stripe's retry then hit the guard, saw the row, and returned
// "OK (duplicate)" without ever processing the payment. The customer is charged, the
// order stays pending, and ExpirePendingOrders later cancels it.
test('a retry after a failed handler still processes the payment', function () {
    Queue::fake();
    $order = makeRetryOrder();

    // Simulate the first delivery having failed mid-handler: the row exists (written
    // before processing) but was never marked processed.
    WebhookEvent::create([
        'stripe_event_id' => 'evt_retry_1',
        'event_type' => 'checkout.session.completed',
        'payload_json' => ['id' => 'evt_retry_1'],
        'received_at' => now(),
        'error' => 'simulated transient failure',
    ]);

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);

    // Stripe retries the same event id.
    $this->postJson('/shop/webhook/stripe', [
        'id' => 'evt_retry_1',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_retry_1',
            'payment_intent' => 'pi_retry_1',
            'metadata' => ['order_id' => (string) $order->id],
        ]],
    ], ['Stripe-Signature' => 't=0,v1=test'])->assertOk();

    expect($order->fresh()->status)->toBe(
        OrderStatus::Paid,
        'the retry was short-circuited as a duplicate and the payment was never processed'
    );
    expect(WebhookEvent::find('evt_retry_1')->processed_at)->not->toBeNull();
});

// A genuinely already-processed event must still be a no-op.
test('an event that already completed is not processed twice', function () {
    Queue::fake();
    $order = makeRetryOrder();

    WebhookEvent::create([
        'stripe_event_id' => 'evt_done_1',
        'event_type' => 'checkout.session.completed',
        'payload_json' => ['id' => 'evt_done_1'],
        'received_at' => now(),
        'processed_at' => now(),
    ]);

    $this->postJson('/shop/webhook/stripe', [
        'id' => 'evt_done_1',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_done_1',
            'payment_intent' => 'pi_done_1',
            'metadata' => ['order_id' => (string) $order->id],
        ]],
    ], ['Stripe-Signature' => 't=0,v1=test'])->assertOk();

    // Untouched: the completed event must not re-run the handler.
    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});
