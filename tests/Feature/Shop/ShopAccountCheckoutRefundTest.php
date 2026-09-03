<?php

use App\Enums\Shop\RefundStatus;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShippingRate;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Services\Shop\CartService;
use App\Services\Shop\CheckoutService;
use App\Services\Shop\RefundService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function refundSite(): Site
{
    test()->seed(\Database\Seeders\Shop\TaxClassSeeder::class);
    test()->seed(\Database\Seeders\Shop\TaxRateSeeder::class);

    $site = Site::factory()->create(['custom_domain' => 'flowers.example', 'custom_domain_status' => 'active']);
    ShippingRate::create([
        'site_id' => $site->id, 'strategy' => 'flat_with_free_threshold',
        'flat_amount_cents' => 500, 'free_threshold_cents' => null, 'method_label' => 'Std',
    ]);

    return $site;
}

// POST /shop/account/login sends an email to a caller-supplied address and its response
// can reveal whether that address is a registered customer, so without throttling the
// endpoint is both a mail relay and an account oracle.
test('the customer login endpoint is rate limited', function () {
    $site = refundSite();

    $statuses = [];
    for ($i = 0; $i < 8; $i++) {
        $statuses[] = $this->post('http://flowers.example/shop/account/login', [
            'email' => 'victim@example.com',
        ])->getStatusCode();
    }

    // NOTE: toContain's extra args are further NEEDLES, not a message.
    expect($statuses)->toContain(429);
});

// The checkout page rendered no figure at all, so the first sight of the amount
// was Stripe's own page: a £25 cart that becomes £30 with shipping was a surprise at
// the point of payment.
test('the checkout page shows the total the customer will be charged', function () {
    $site = refundSite();
    $product = Product::factory()->published()->for($site)->create();
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 2500]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 5]);

    $cart = app(CartService::class)->getOrCreate($site->id, 'sess-total');
    app(CartService::class)->addItem($cart, $variant->id, 1);

    $html = $this->withCookie(\App\Http\Controllers\Shop\CartController::COOKIE_NAME, 'sess-total')
        ->get('http://flowers.example/shop/checkout')
        ->getContent();

    expect($html)->toContain('£25.00');   // subtotal
    expect($html)->toContain('£5.00');    // shipping
    expect($html)->toContain('£30.00');   // total actually charged
});

// refundFull read refund_amount_cents without locking the row, so two concurrent
// refunds both passed the guard and both called Stripe: money out twice, recorded once.
// A second refund on an already-fully-refunded order must be refused.
test('a second full refund on an already refunded order is refused', function () {
    $site = refundSite();
    $product = Product::factory()->published()->for($site)->create();
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 2000]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 5]);
    $cart = app(CartService::class)->getOrCreate($site->id, 'sess-refund');
    app(CartService::class)->addItem($cart, $variant->id, 1);
    $order = app(CheckoutService::class)->start($cart, [
        'name' => 'Jane', 'email' => 'jane@example.com',
        'line1' => '1 High St', 'city' => 'L', 'postcode' => 'L1', 'country_code' => 'GB',
    ]);
    $order->update(['stripe_payment_intent_id' => 'pi_test_refund']);

    $calls = [];
    $gateway = new class($calls)
    {
        public array $seen = [];

        public function __construct(&$calls)
        {
            $this->seen = &$calls;
        }

        public function refund(string $pi, int $amount, ?string $key = null): void
        {
            $this->seen[] = ['amount' => $amount, 'key' => $key];
        }
    };

    $svc = new RefundService($gateway);
    $svc->refundFull($order->fresh());

    expect($gateway->seen)->toHaveCount(1);
    expect($gateway->seen[0]['key'])->not->toBeNull();

    // The second attempt must be refused, not silently refunded again.
    expect(fn () => $svc->refundFull($order->fresh()))
        ->toThrow(\App\Exceptions\Shop\OrderStateException::class);

    expect($gateway->seen)->toHaveCount(1);
});
