<?php

use App\Services\Shop\StripeService;
use Stripe\Service\Checkout\CheckoutServiceFactory;
use Stripe\StripeClient;

/**
 * These tests exist because every other StripeService test hand-rolls a fake
 * client, and those fakes were written from the same misunderstanding as the
 * production code: they exposed `sessions()` as a METHOD. The suite then proved
 * the code matched the fake, while the real SDK exposes `sessions` as a
 * PROPERTY (via StripeClient::__get). Result: createCheckoutSession threw
 * "Call to undefined method CheckoutServiceFactory::sessions()" the first time
 * it ever met the real SDK — i.e. checkout could not take a payment at all.
 *
 * A fake is only evidence if its shape is pinned to the real thing. These tests
 * pin it.
 */
test('the real Stripe SDK exposes checkout sessions as a property, not a method', function () {
    // The exact drift that shipped: if someone reintroduces `sessions()`, this fails.
    expect(method_exists(CheckoutServiceFactory::class, 'sessions'))->toBeFalse();

    $client = new StripeClient('sk_test_placeholder');

    expect($client->checkout)->toBeInstanceOf(CheckoutServiceFactory::class);
    expect($client->checkout->sessions)->toBeObject();
    expect(method_exists($client->checkout->sessions, 'create'))->toBeTrue();
});

test('createCheckoutSession calls the SDK the way the real SDK is shaped', function () {
    $captured = null;

    // Fake mirroring the REAL client: `sessions` reached as a property.
    $sessions = new class
    {
        public array $seen = [];

        public function create(array $params): object
        {
            $this->seen[] = $params;

            return (object) ['id' => 'cs_test_123', 'url' => 'https://checkout.stripe.test/cs_test_123'];
        }
    };

    $client = new class($sessions)
    {
        public object $checkout;

        public function __construct(object $sessions)
        {
            $this->checkout = new class($sessions)
            {
                public function __construct(public object $sessions) {}
            };
        }
    };

    $session = (new StripeService($client))->createCheckoutSession(
        orderId: 42,
        orderNumber: 'SITE1-000042',
        totalCents: 2245,
        currency: 'gbp',
        customerEmail: 'shopper@example.com',
        successUrl: 'https://shop.example/shop/checkout/success?order=42',
        cancelUrl: 'https://shop.example/shop/checkout/cancel?order=42',
        lineDescriptor: 'Order SITE1-000042',
        expiresAt: new DateTimeImmutable('+35 minutes'),
    );

    expect($session->id)->toBe('cs_test_123');

    $params = $sessions->seen[0];
    expect($params['line_items'][0]['price_data']['unit_amount'])->toBe(2245);
    expect($params['metadata']['order_id'])->toBe('42');
    expect($params)->toHaveKey('expires_at');
});
