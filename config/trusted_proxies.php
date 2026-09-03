<?php

/**
 * Cloudflare-origin IP ranges.
 *
 * Source of truth (refresh via `php artisan cloudflare:sync-ips`):
 *   https://www.cloudflare.com/ips-v4/#  (IPv4)
 *   https://www.cloudflare.com/ips-v6/#  (IPv6)
 *
 * These are the only IPs whose X-Forwarded-* headers the app will honour.
 * Any request whose connection IP is NOT in this list is treated as a
 * direct hit — Laravel will ignore X-Forwarded-For / -Host / -Proto and
 * use the real socket IP + server Host header instead, so an attacker who
 * is not connecting through Cloudflare cannot spoof the forwarded host.
 *
 * When Cloudflare publishes a new range, run the sync command (which
 * rewrites this file) and redeploy.
 */
return [
    'cloudflare_v4' => [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
    ],
    'cloudflare_v6' => [
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ],

    // Private/internal proxy ranges. Extend this list in .env via
    // TRUSTED_INTERNAL_PROXIES (comma-separated) if your topology has
    // additional internal LBs in front of the app container.
    'internal' => array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_INTERNAL_PROXIES', ''))
    )),
];
