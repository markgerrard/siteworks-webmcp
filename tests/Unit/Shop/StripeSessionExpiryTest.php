<?php

use App\Services\Shop\StripeService;

// createCheckoutSession never set the session's expires_at, so Stripe applied
// its 24-hour default while the order's own TTL is 30 minutes. Paying at T+35min meant
// ExpirePendingOrders had already cancelled the order and released the stock; the
// webhook then matched nothing and returned a silent 200. Charged, no order, no alert.
test('the Stripe session carries an expires_at that matches the order deadline', function () {
    $captured = null;

    $stub = new class($captured)
    {
        public $checkout;

        public function __construct(&$captured)
        {
            $this->checkout = new class($captured)
            {
                private $captured;

                /** Property, not a method — mirrors CheckoutServiceFactory. */
                public object $sessions;

                public function __construct(&$captured)
                {
                    $this->captured = &$captured;
                    $ref = &$this->captured;

                    $this->sessions = new class($ref)
                    {
                        private $captured;

                        public function __construct(&$captured)
                        {
                            $this->captured = &$captured;
                        }

                        public function create(array $params)
                        {
                            $this->captured = $params;

                            return (object) ['id' => 'cs_x', 'url' => 'https://checkout.stripe.com/x'];
                        }
                    };
                }
            };
        }
    };

    $deadline = now()->addMinutes(35);

    (new StripeService($stub))->createCheckoutSession(
        orderId: 42,
        orderNumber: 'FLORIST-000042',
        totalCents: 4500,
        currency: 'gbp',
        customerEmail: 'buyer@example.com',
        successUrl: 'https://flowers.example/shop/checkout/success?order=42',
        cancelUrl: 'https://flowers.example/shop/checkout/cancel?order=42',
        lineDescriptor: '1 item',
        expiresAt: $deadline,
    );

    // toHaveKey's 2nd arg is an expected VALUE, not a message — assert separately.
    expect(array_key_exists('expires_at', $captured))
        ->toBeTrue('the Stripe session was created without an expires_at');
    expect($captured['expires_at'])->toBe($deadline->getTimestamp());
});
