<?php

return [
    'stripe' => [
        'secret_key' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'cloudflare' => [
        'brands' => [
            'a' => [
                'zone_id' => env('CLOUDFLARE_A_ZONE_ID', ''),
                'apex' => env('CLOUDFLARE_A_APEX', ''),
                'subdomain' => env('CLOUDFLARE_A_SUBDOMAIN', ''),
                'fallback' => env('CLOUDFLARE_A_FALLBACK', ''),
            ],
            'b' => [
                'zone_id' => env('CLOUDFLARE_B_ZONE_ID', ''),
                'apex' => env('CLOUDFLARE_B_APEX', ''),
                'subdomain' => env('CLOUDFLARE_B_SUBDOMAIN', ''),
                'fallback' => env('CLOUDFLARE_B_FALLBACK', ''),
            ],
        ],
    ],
];
