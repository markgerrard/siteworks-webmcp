<?php

namespace App\Services\Shop;

use Stripe\StripeClient;
use Stripe\Webhook;

class StripeService
{
    public function __construct(protected ?object $client = null) {}

    protected function client(): object
    {
        return $this->client ??= new StripeClient(config('services.stripe.secret_key'));
    }

    public function createCheckoutSession(
        int $orderId,
        string $orderNumber,
        int $totalCents,
        string $currency,
        string $customerEmail,
        string $successUrl,
        string $cancelUrl,
        string $lineDescriptor,
        ?\DateTimeInterface $expiresAt = null,
    ): object {
        $params = [
            'payment_method_types' => ['card'],
            'mode' => 'payment',
            'customer_email' => $customerEmail,
            'line_items' => [[
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $totalCents,
                    'product_data' => ['name' => $lineDescriptor],
                ],
                'quantity' => 1,
            ]],
            'metadata' => [
                'order_id' => (string) $orderId,
                'order_number' => $orderNumber,
            ],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ];

        // Bound the payment window to the order's own deadline. Without this Stripe
        // applies its 24-hour default, so the payment URL outlived the 30-minute order
        // TTL by 23.5 hours: ExpirePendingOrders cancelled the order and released the
        // stock, then a late payment produced a webhook that matched nothing and
        // returned a silent 200 — customer charged, no order, no alert.
        if ($expiresAt !== null) {
            $params['expires_at'] = $expiresAt->getTimestamp();
        }

        // `sessions` is a PROPERTY on CheckoutServiceFactory (resolved through
        // StripeClient::__get), not a method. Calling it as `sessions()` threw
        // "Call to undefined method" on every real request — checkout could not
        // create a session at all. It survived review because the test fakes
        // declared `sessions()` as a method too, so the suite only ever proved
        // the code agreed with the fake. See tests/Unit/Shop/StripeSdkShapeTest.
        return $this->client()->checkout->sessions->create($params);
    }

    public function constructEvent(string $payload, string $sigHeader): object
    {
        return Webhook::constructEvent(
            $payload,
            $sigHeader,
            config('services.stripe.webhook_secret')
        );
    }
}
