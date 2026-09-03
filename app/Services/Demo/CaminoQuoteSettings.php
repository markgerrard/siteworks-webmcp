<?php

namespace App\Services\Demo;

use App\Models\Site;
use App\Services\Shop\Fulfilment\FulfilmentConfig;

/**
 * Pickup and peninsula delivery so /shop/quote can render fulfilment
 * methods QuoteController reads from the site's fulfilment JSON.
 */
final class CaminoQuoteSettings
{
    /**
     * @return array<string, mixed>
     */
    public static function fulfilment(): array
    {
        return [
            'delivery' => [
                'enabled' => true,
                'label' => 'Peninsula delivery',
                'zones' => [
                    [
                        'name' => 'Palo Alto',
                        'prefixes' => ['943'],
                        'fee_cents' => 0,
                        'free_over_cents' => 7500,
                        'lead_time' => 'same day',
                        'min_order_cents' => 7500,
                    ],
                ],
            ],
            'collect' => [
                'enabled' => true,
                'label' => 'Pickup',
                'address' => '2180 California Ave, Palo Alto, CA 94306',
                'hours' => 'Tue–Sat 8:00 am – 5:00 pm',
                'lead_time' => 'next day',
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

    public function seed(Site $site): void
    {
        $canonical = self::fulfilment();
        $current = FulfilmentConfig::fromSite($site);
        if ($current !== null && $current->toArray() === $canonical) {
            return;
        }

        $site->forceFill(['fulfilment' => $canonical])->save();
    }
}
