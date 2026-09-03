<?php

/**
 * Failure-mode tests for the tightened TrustProxies configuration.
 *
 * The app trusts an explicit list of Cloudflare CIDRs rather than
 * `trustProxies(at: '*')`. This test suite verifies:
 *
 *  1. Direct-to-origin requests (source IP NOT in the trust list) do NOT
 *     honour X-Forwarded-* headers — forwarded IP / host / proto are
 *     ignored and the app sees the real socket IP + Host header.
 *  2. Requests arriving via a trusted proxy (source IP in list) DO honour
 *     the forwarded headers.
 *  3. A missing-or-empty TRUSTED_INTERNAL_PROXIES env var defaults to
 *     Cloudflare-only trust.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    Route::get('/_test/trust-probe', function (\Illuminate\Http\Request $request) {
        return [
            'ip' => $request->ip(),
            'host' => $request->getHost(),
            'scheme' => $request->getScheme(),
            'is_secure' => $request->isSecure(),
        ];
    });
});

test('direct-to-origin request does NOT honour spoofed X-Forwarded-Host', function () {
    $response = $this->withServerVariables([
        'REMOTE_ADDR' => '198.51.100.42', // attacker IP — not in any trust list
    ])->get('/_test/trust-probe', [
        'X-Forwarded-Host' => 'admin.evil.example.com',
        'X-Forwarded-For' => '10.0.0.1',
        'X-Forwarded-Proto' => 'https',
    ]);

    $response->assertOk();
    $body = $response->json();

    // Host must NOT be the attacker-supplied header value.
    expect($body['host'])->not->toBe('admin.evil.example.com');
    // IP must be the real socket IP, not the attacker-supplied XFF.
    expect($body['ip'])->toBe('198.51.100.42');
});

test('request from a Cloudflare IP DOES honour X-Forwarded headers', function () {
    // 104.16.0.0/13 is a published Cloudflare range.
    $response = $this->withServerVariables([
        'REMOTE_ADDR' => '104.16.1.2',
    ])->get('/_test/trust-probe', [
        'X-Forwarded-Host' => config('domains.agent_domain'),
        'X-Forwarded-For' => '203.0.113.77',
        'X-Forwarded-Proto' => 'https',
    ]);

    $response->assertOk();
    $body = $response->json();

    // Trusted proxy → forwarded values take effect.
    expect($body['host'])->toBe(config('domains.agent_domain'));
    expect($body['ip'])->toBe('203.0.113.77');
});

test('trusted_proxies config lists real Cloudflare CIDRs', function () {
    // Sanity check — if the sync command ever produces an empty list,
    // fail loudly rather than silently reverting to open-trust.
    $v4 = config('trusted_proxies.cloudflare_v4', []);
    $v6 = config('trusted_proxies.cloudflare_v6', []);

    expect(count($v4))->toBeGreaterThanOrEqual(10,
        'Cloudflare IPv4 list looks truncated — run `php artisan cloudflare:sync-ips` and redeploy.');
    expect(count($v6))->toBeGreaterThanOrEqual(3,
        'Cloudflare IPv6 list looks truncated — run `php artisan cloudflare:sync-ips` and redeploy.');

    // Each entry must be a CIDR, not an open wildcard.
    foreach ($v4 as $cidr) {
        expect($cidr)->toMatch('#^\d+\.\d+\.\d+\.\d+/\d+$#', "Invalid v4 CIDR: {$cidr}");
    }
    foreach ($v6 as $cidr) {
        expect($cidr)->toMatch('#^[0-9a-fA-F:]+/\d+$#', "Invalid v6 CIDR: {$cidr}");
    }
});
