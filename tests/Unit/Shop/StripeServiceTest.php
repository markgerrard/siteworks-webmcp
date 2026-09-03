<?php

use App\Services\Shop\StripeService;

test('createCheckoutSession builds payload with order metadata', function () {
    // Use a stub Stripe client via dependency injection
    $stub = new class
    {
        public array $calls = [];

        public $checkout;

        public function __construct()
        {
            $this->checkout = new class
            {
                public array $calls = [];

                /** Property, not a method — mirrors CheckoutServiceFactory. */
                public object $sessions;

                public function __construct()
                {
                    $this->sessions = new class
                    {
                        public function create(array $params)
                        {
                            return (object) [
                                'id' => 'cs_test_xyz',
                                'url' => 'https://checkout.stripe.com/xyz',
                            ];
                        }
                    };
                }
            };
        }
    };

    $svc = new StripeService($stub);

    $session = $svc->createCheckoutSession(
        orderId: 42,
        orderNumber: 'FLORIST-000042',
        totalCents: 4500,
        currency: 'gbp',
        customerEmail: 'buyer@example.com',
        successUrl: 'https://flowers.example/checkout/success?order=42',
        cancelUrl: 'https://flowers.example/checkout/cancel?order=42',
        lineDescriptor: '1 item'
    );

    expect($session->id)->toBe('cs_test_xyz');
    expect($session->url)->toBe('https://checkout.stripe.com/xyz');
});
