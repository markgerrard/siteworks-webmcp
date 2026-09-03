<?php

use Illuminate\Support\Facades\Route;

/**
 * `X-Forwarded-Host` and `X-Forwarded-Port` are deliberately untrusted, from any proxy:
 * the request host and authority must come from the origin, never from a client-settable header.
 */
function trustedProxyIp(): string
{
    // First Cloudflare range in config/trusted_proxies.php: 173.245.48.0/20.
    return '173.245.48.1';
}

test('a spoofed X-Forwarded-Host from a trusted proxy does not change the host', function () {
    $customerHost = (string) config('domains.customer_domain');

    $response = $this->withServerVariables([
        'HTTP_HOST' => $customerHost,
        'REMOTE_ADDR' => trustedProxyIp(),
        'HTTP_X_FORWARDED_HOST' => (string) config('domains.agent_domain'),
    ])->get('http://'.$customerHost.'/portal');

    // If the header were honoured the app would think it is on the agents host, the
    // domain-bound customer route would not match, and this would 404.
    expect($response->status())->not->toBe(404,
        'X-Forwarded-Host changed the apparent host, so a domain-bound route stopped matching.');
    $response->assertRedirect(route('login'));
});

test('an attacker host cannot be injected into generated absolute URLs', function () {
    $agentHost = (string) config('domains.agent_domain');

    // Assert the HOST THE APP SEES, not a domain-bound route's URL. route('agent.login')
    // is built from its bound domain now, so asserting on it would stay green even if
    // HEADER_X_FORWARDED_HOST were trusted again — a tautology a review caught.
    Route::get('/__host-probe', fn () => response()->json([
        'host' => request()->getHost(),
        'root' => request()->root(),
    ]))->middleware('web');

    $response = $this->withServerVariables([
        'HTTP_HOST' => $agentHost,
        'REMOTE_ADDR' => trustedProxyIp(),
        'HTTP_X_FORWARDED_HOST' => 'evil-phish.example',
    ])->getJson('http://'.$agentHost.'/__host-probe');

    $response->assertOk();

    expect($response->json('host'))->toBe($agentHost)
        ->and($response->json('root'))->not->toContain('evil-phish.example');
});

test('an attacker cannot inject a port into the request authority', function () {
    $agentHost = (string) config('domains.agent_domain');

    // The port is part of the authority: every generated absolute URL would carry it.
    Route::get('/__authority-probe', fn () => response()->json([
        'root' => request()->root(),
        'port' => request()->getPort(),
        'url' => url('/somewhere'),
    ]))->middleware('web');

    $response = $this->withServerVariables([
        'HTTP_HOST' => $agentHost,
        'REMOTE_ADDR' => trustedProxyIp(),
        'HTTP_X_FORWARDED_PORT' => '1337',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ])->getJson('http://'.$agentHost.'/__authority-probe');

    $response->assertOk();

    expect($response->json('root'))->not->toContain(':1337')
        ->and($response->json('url'))->not->toContain(':1337')
        ->and($response->json('port'))->not->toBe(1337);
});

test('agent.login is bound to the agent domain', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => $r->getName() === 'agent.login');

    expect($route)->not->toBeNull()
        ->and($route->getDomain())->toBe((string) config('domains.agent_domain'),
            'An unbound auth route generates its absolute URL from the request host.');
});

test('the client IP and scheme are still taken from the proxy', function () {
    // Do not over-fix: X-Forwarded-For and -Proto must keep working, or every
    // rate limiter keys on Cloudflare's IP and every generated URL goes http://.
    Route::get('/__forwarded-probe', fn () => response()->json([
        'ip' => request()->ip(),
        'secure' => request()->isSecure(),
    ]))->middleware('web');

    $host = (string) config('domains.agent_domain');

    $response = $this->withServerVariables([
        'HTTP_HOST' => $host,
        'REMOTE_ADDR' => trustedProxyIp(),
        'HTTP_X_FORWARDED_FOR' => '203.0.113.77',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ])->getJson('http://'.$host.'/__forwarded-probe');

    $response->assertOk();
    expect($response->json('ip'))->toBe('203.0.113.77')
        ->and($response->json('secure'))->toBeTrue();
});
