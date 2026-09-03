<?php

namespace Tests\Support;

final class FulfilmentFixtures
{
    /**
     * Camino bakery: delivery zones + collect. No nationwide shipping.
     *
     * @return array<string, mixed>
     */
    public static function camino(): array
    {
        return [
            'delivery' => [
                'enabled' => true,
                'label' => 'Local delivery',
                'zones' => [
                    [
                        'name' => 'Inner',
                        'prefixes' => ['SW1A', 'SW1'],
                        'fee_cents' => 400,
                        'free_over_cents' => 4000,
                        'lead_time' => 'next day',
                        'min_order_cents' => null,
                    ],
                    [
                        'name' => 'Outer',
                        'prefixes' => ['SW'],
                        'fee_cents' => 600,
                        'free_over_cents' => null,
                        'lead_time' => '2 days',
                        'min_order_cents' => 1500,
                    ],
                ],
            ],
            'collect' => [
                'enabled' => true,
                'label' => 'Click & collect',
                'address' => '12 High Street',
                'hours' => 'Tue–Sat 8–4',
                'lead_time' => 'same day',
            ],
            'shipping' => [
                'enabled' => false,
                'label' => 'Shipping',
                'note' => '',
            ],
            'widget' => [
                'prompt' => 'Check delivery to your postcode',
                'remember_days' => 30,
            ],
        ];
    }

    /**
     * Florist: delivery zones + same-day lead time. No collect.
     *
     * @return array<string, mixed>
     */
    public static function florist(): array
    {
        return [
            'delivery' => [
                'enabled' => true,
                'label' => 'Local delivery',
                'zones' => [
                    [
                        'name' => 'Town',
                        'prefixes' => ['M1', 'M2'],
                        'fee_cents' => 500,
                        'free_over_cents' => null,
                        'lead_time' => 'same day before 12:00',
                        'min_order_cents' => null,
                    ],
                ],
            ],
            'collect' => [
                'enabled' => false,
                'label' => 'Click & collect',
                'address' => '',
                'hours' => '',
                'lead_time' => '',
            ],
            'shipping' => [
                'enabled' => true,
                'label' => 'Shipping',
                'note' => 'Nationwide next-day',
            ],
            'widget' => [
                'prompt' => 'Check delivery to your postcode',
                'remember_days' => 30,
            ],
        ];
    }

    /**
     * Shipping-only: no delivery, no collect.
     *
     * @return array<string, mixed>
     */
    public static function shippingOnly(): array
    {
        return [
            'delivery' => [
                'enabled' => false,
                'label' => 'Local delivery',
                'zones' => [],
            ],
            'collect' => [
                'enabled' => false,
                'label' => 'Click & collect',
                'address' => '',
                'hours' => '',
                'lead_time' => '',
            ],
            'shipping' => [
                'enabled' => true,
                'label' => 'Shipping',
                'note' => 'Ships in 3–5 days',
            ],
            'widget' => [
                'prompt' => 'Check delivery to your postcode',
                'remember_days' => 30,
            ],
        ];
    }
}
