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
use Database\Seeders\Shop\TaxClassSeeder;
use Database\Seeders\Shop\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function makePendingOrder(): Order
{
    test()->seed(TaxClassSeeder::class);
    test()->seed(TaxRateSeeder::class);

    $site = Site::factory()->create(['custom_domain' => 'flowers.example']);
    ShippingRate::create([
        'site_id' => $site->id, 'strategy' => 'flat_with_free_threshold',
        'flat_amount_cents' => 500, 'free_threshold_cents' => null, 'method_label' => 'Std',
    ]);
    $product = Product::factory()->for($site)->create();
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 2500]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 10]);

    $cart = app(CartService::class)->getOrCreate($site->id, 'sess-1');
    app(CartService::class)->addItem($cart, $variant->id, 2);

    return app(CheckoutService::class)->start($cart, [
        'name' => 'Jane', 'email' => 'jane@example.com',
        'line1' => '1 High St', 'city' => 'L', 'postcode' => 'L1', 'country_code' => 'GB',
    ]);
}

test('paid webhook transitions order from pending to paid and commits stock', function () {
    Queue::fake();

    $order = makePendingOrder();

    $payload = json_encode([
        'id' => 'evt_test_1',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_test_xyz',
            'payment_intent' => 'pi_test_xyz',
            'metadata' => ['order_id' => (string) $order->id],
        ]],
    ]);

    config(['services.stripe.webhook_secret' => 'whsec_test']);

    // bypass signature by using test fake in controller
    $this->postJson('/shop/webhook/stripe', json_decode($payload, true), [
        'Stripe-Signature' => 't=0,v1=test',
    ])->assertOk();

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Paid);
    expect($order->stripe_payment_intent_id)->toBe('pi_test_xyz');
    expect(VariantStock::first()->on_hand)->toBe(8);
});

test('duplicate event ids are no-ops', function () {
    Queue::fake();

    $order = makePendingOrder();
    $payload = [
        'id' => 'evt_test_1',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_test_xyz',
            'payment_intent' => 'pi_test_xyz',
            'metadata' => ['order_id' => (string) $order->id],
        ]],
    ];

    $this->postJson('/shop/webhook/stripe', $payload, ['Stripe-Signature' => 't=0,v1=test'])->assertOk();
    $this->postJson('/shop/webhook/stripe', $payload, ['Stripe-Signature' => 't=0,v1=test'])->assertOk();

    expect(WebhookEvent::count())->toBe(1);
    expect(VariantStock::first()->on_hand)->toBe(8); // not decremented twice
});

test('paid webhook still marks the order paid when the site is in quote mode', function () {
    Queue::fake();

    $order = makePendingOrder();
    $order->site->update(['shop_mode' => 'quote']);

    $payload = [
        'id' => 'evt_quote_mode_1',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_quote_mode',
            'payment_intent' => 'pi_quote_mode',
            'metadata' => ['order_id' => (string) $order->id],
        ]],
    ];

    $this->postJson('/shop/webhook/stripe', $payload, [
        'Stripe-Signature' => 't=0,v1=test',
    ])->assertOk();

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->stripe_payment_intent_id)->toBe('pi_quote_mode')
        ->and($order->site->fresh()->shop_mode)->toBe('quote');
});
