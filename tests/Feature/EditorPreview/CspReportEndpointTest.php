<?php

use App\Http\Requests\StoreCspReportRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

function validCspReport(array $overrides = []): array
{
    return [
        'csp-report' => array_merge([
            'document-uri' => 'https://editor-preview.domain.com/sites/1/pages/1',
            'violated-directive' => 'script-src',
            'blocked-uri' => 'https://evil.example/x.js',
            'source-file' => 'https://editor-preview.domain.com/app.js',
            'line-number' => 12,
        ], $overrides),
    ];
}

function cspReportHost(): string
{
    return (string) config('domains.agent_domain');
}

function postCspReport(array $payload, array $headers = [], ?string $ip = null)
{
    $host = cspReportHost();
    $server = [
        'HTTP_HOST' => $host,
    ];

    if ($ip !== null) {
        $server['REMOTE_ADDR'] = $ip;
    }

    return test()->withServerVariables($server)
        ->json('POST', 'http://'.$host.'/csp-report', $payload, array_merge([
            'CONTENT_TYPE' => 'application/csp-report',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_USER_AGENT' => 'TestBrowser/1.0',
        ], $headers));
}

beforeEach(function () {
    Cache::flush();
});

test('valid csp-report is accepted and logs only clipped fields', function () {
    Log::shouldReceive('channel')->with('csp')->andReturnSelf();
    Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context) {
        return $message === 'csp-violation'
            && ($context['document-uri'] ?? null) === 'https://editor-preview.domain.com/sites/1/pages/1'
            && ($context['violated-directive'] ?? null) === 'script-src'
            && ($context['blocked-uri'] ?? null) === 'https://evil.example/x.js'
            && ! array_key_exists('original-policy', $context)
            && strlen((string) ($context['user-agent'] ?? '')) <= 200;
    });

    postCspReport(validCspReport())->assertNoContent();
});

test('rejects a missing or non-array csp-report payload', function () {
    Log::spy();

    postCspReport(['csp-report' => 'not-an-array'])->assertStatus(422);
    postCspReport(['unrelated' => true])->assertStatus(422);

    Log::shouldNotHaveReceived('warning');
});

test('rejects overlong report fields', function () {
    Log::spy();

    postCspReport(validCspReport([
        'document-uri' => str_repeat('a', StoreCspReportRequest::MAX_FIELD_CHARS + 1),
    ]))->assertStatus(422);

    Log::shouldNotHaveReceived('warning');
});

test('rejects disallowed content types', function () {
    Log::spy();

    postCspReport(validCspReport(), [
        'CONTENT_TYPE' => 'text/plain',
    ])->assertStatus(415);

    Log::shouldNotHaveReceived('warning');
});

test('rejects bodies larger than the dedicated collector limit', function () {
    Log::spy();

    $host = cspReportHost();
    $body = json_encode(validCspReport([
        'document-uri' => str_repeat('b', StoreCspReportRequest::MAX_BODY_BYTES),
    ]), JSON_THROW_ON_ERROR);

    expect(strlen($body))->toBeGreaterThan(StoreCspReportRequest::MAX_BODY_BYTES);

    $this->call(
        'POST',
        'http://'.$host.'/csp-report',
        server: [
            'HTTP_HOST' => $host,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/csp-report',
            'CONTENT_LENGTH' => (string) strlen($body),
        ],
        content: $body,
    )->assertStatus(413);

    Log::shouldNotHaveReceived('warning');
});

test('registered csp-report limiter keys per IP and bounds the global outage to a minute', function () {
    $limiter = RateLimiter::limiter('csp-report');
    expect($limiter)->toBeCallable();

    $first = Request::create('/csp-report', 'POST', server: ['REMOTE_ADDR' => '203.0.113.10']);
    $second = Request::create('/csp-report', 'POST', server: ['REMOTE_ADDR' => '198.51.100.20']);

    $firstLimits = $limiter($first);
    $secondLimits = $limiter($second);

    expect($firstLimits)->toHaveCount(2)
        ->and($firstLimits[0]->key)->toBe('csp-ip:203.0.113.10')
        ->and($firstLimits[0]->maxAttempts)->toBe(30)
        ->and($firstLimits[0]->decaySeconds)->toBe(60)
        ->and($firstLimits[1]->key)->toBe('csp-global')
        ->and($firstLimits[1]->maxAttempts)->toBe(180)
        // An hourly ceiling means an exhausted bucket denies telemetry for up to an
        // hour. Same average rate, 60x the outage. Keep the drain short.
        ->and($firstLimits[1]->decaySeconds)->toBe(60)
        ->and($secondLimits[0]->key)->toBe('csp-ip:198.51.100.20')
        ->and($secondLimits[1]->key)->toBe('csp-global');
});

test('the per-IP key collapses an IPv6 /64 so a single subnet cannot multiply itself', function () {
    $limiter = RateLimiter::limiter('csp-report');

    // A routed /64 gives one attacker 2^64 addresses. Keyed on the full address the
    // per-IP cap never trips, and the global ceiling can be filled in seconds.
    $keyFor = fn (string $ip) => $limiter(
        Request::create('/csp-report', 'POST', server: ['REMOTE_ADDR' => $ip])
    )[0]->key;

    $a = $keyFor('2001:db8:1234:5678::1');
    $b = $keyFor('2001:db8:1234:5678:aaaa:bbbb:cccc:dddd');
    $other = $keyFor('2001:db8:1234:9999::1');

    expect($a)->toBe($b, 'Two addresses in the same /64 must share a rate-limit key.')
        ->and($a)->not->toBe($other, 'Different /64s are different callers.');

    // IPv4 keeps full-address keying — a /64 concept does not apply.
    expect($keyFor('203.0.113.10'))->toBe('csp-ip:203.0.113.10')
        ->and($keyFor('203.0.113.11'))->not->toBe('csp-ip:203.0.113.10');
});

test('IPv4-mapped and NAT64 addresses key on the embedded IPv4, not the shared prefix', function () {
    $limiter = RateLimiter::limiter('csp-report');
    $keyFor = fn (string $ip) => $limiter(
        Request::create('/csp-report', 'POST', server: ['REMOTE_ADDR' => $ip])
    )[0]->key;

    // Every IPv4-mapped address shares the ::ffff:0:0/96 prefix, so a /64 collapse
    // puts every IPv4 client behind a dual-stack listener into ONE 30/min bucket —
    // one host then denies the collector to all of them.
    expect($keyFor('::ffff:203.0.113.10'))->toBe('csp-ip:203.0.113.10')
        ->and($keyFor('::ffff:8.8.8.8'))->toBe('csp-ip:8.8.8.8')
        ->and($keyFor('0:0:0:0:0:ffff:1.2.3.4'))->toBe('csp-ip:1.2.3.4')
        // The mapped form must land in the same bucket as the dotted form.
        ->and($keyFor('::ffff:203.0.113.10'))->toBe($keyFor('203.0.113.10'));

    // The NAT64 well-known prefix collapses identically.
    expect($keyFor('64:ff9b::8.8.8.8'))->toBe('csp-ip:8.8.8.8')
        ->and($keyFor('64:ff9b::1.1.1.1'))->not->toBe($keyFor('64:ff9b::8.8.8.8'));

    // Loopback and unspecified are single hosts, not subnets to be collapsed.
    expect($keyFor('::1'))->toBe('csp-ip:::1')
        ->and($keyFor('::1'))->not->toBe($keyFor('::ffff:8.8.8.8'));
});

test('every IPv4-carrying transition prefix keys on the embedded address', function () {
    $limiter = RateLimiter::limiter('csp-report');
    $keyFor = fn (string $ip) => $limiter(
        Request::create('/csp-report', 'POST', server: ['REMOTE_ADDR' => $ip])
    )[0]->key;

    // Handling only ::ffff:0:0/96 and 64:ff9b::/96 leaves the adjacent families
    // still collapsing: IPv4-compatible ::a.b.c.d and RFC 8215 local NAT64
    // 64:ff9b:1::/48. Without this, every client behind such a translator would
    // share one 30/min bucket — the same defect the embedded-address key is meant
    // to remove for every IPv4-carrying transition prefix.
    expect($keyFor('::192.0.2.1'))->toBe('csp-ip:192.0.2.1')
        ->and($keyFor('::198.51.100.2'))->toBe('csp-ip:198.51.100.2')
        ->and($keyFor('::192.0.2.1'))->not->toBe($keyFor('::198.51.100.2'));

    expect($keyFor('64:ff9b:1::192.0.2.1'))->toBe('csp-ip:192.0.2.1')
        ->and($keyFor('64:ff9b:1::198.51.100.2'))->toBe('csp-ip:198.51.100.2');

    // ...and the mapped form of one client still shares a bucket with its dotted form.
    expect($keyFor('::192.0.2.1'))->toBe($keyFor('192.0.2.1'));

    // Loopback must not be swept into ::/96 by the new check.
    expect($keyFor('::1'))->toBe('csp-ip:::1');
});

test('an unparseable address cannot mint unlimited rate-limit keys', function () {
    $limiter = RateLimiter::limiter('csp-report');
    $keyFor = fn (string $ip) => $limiter(
        Request::create('/csp-report', 'POST', server: ['REMOTE_ADDR' => $ip])
    )[0]->key;

    // Passing malformed input through verbatim makes every distinct garbage value a
    // fresh bucket, so anything able to influence $request->ip() bypasses the cap
    // entirely. Unparseable input shares one bucket instead.
    $garbage = [$keyFor('not-an-ip'), $keyFor('fe80::1%eth0'), $keyFor('203.0.113.10 '), $keyFor('')];

    expect(array_unique($garbage))->toHaveCount(1)
        ->and($garbage[0])->not->toContain('not-an-ip');
});

test('two REMOTE_ADDRs have independent per-IP collector buckets', function () {
    Log::shouldReceive('channel')->with('csp')->andReturnSelf();
    Log::shouldReceive('warning')->times(31);

    $ipA = '203.0.113.88';
    $ipB = '198.51.100.77';

    for ($i = 0; $i < 30; $i++) {
        postCspReport(validCspReport(), ip: $ipA)->assertNoContent();
    }

    postCspReport(validCspReport(), ip: $ipA)->assertStatus(429);
    postCspReport(validCspReport(), ip: $ipB)->assertNoContent();
});

test('csp-report does not mint a session cookie', function () {
    Log::shouldReceive('channel')->with('csp')->andReturnSelf();
    Log::shouldReceive('warning')->once();

    $response = postCspReport(validCspReport());
    $response->assertNoContent();

    $cookieNames = collect($response->headers->getCookies())->map->getName()->all();
    expect($cookieNames)->not->toContain((string) config('session.cookie'));
});
