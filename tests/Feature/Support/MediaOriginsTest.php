<?php

use App\Support\MediaOrigins;
use Illuminate\Support\Facades\Storage;

/**
 * The CSP pins media to these origins, so they must match what the app actually
 * emits. `Storage::disk('s3')->url()` is what builds every hero, logo, face and
 * imported-media URL in the app, so that is the thing to agree with — deriving the
 * host by hand from config keys would understand only one config shape and return
 * nothing for a standard AWS setup, which would block every image and video on
 * both CSPs.
 */
function withS3Config(array $overrides): void
{
    config(['filesystems.disks.s3' => array_merge([
        'driver' => 's3',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'region' => 'eu-west-2',
        'bucket' => 'example-bucket',
        'url' => null,
        'endpoint' => null,
        'use_path_style_endpoint' => false,
        'throw' => false,
    ], $overrides)]);

    Storage::forgetDisk('s3');
    MediaOrigins::flush();
}

afterEach(function () {
    Storage::forgetDisk('s3');
    MediaOrigins::flush();
});

test('an explicit disk URL is pinned, with the DigitalOcean CDN alias alongside', function () {
    withS3Config(['url' => 'https://website-preview.lon1.digitaloceanspaces.com']);

    expect(MediaOrigins::all())->toBe([
        'https://website-preview.lon1.digitaloceanspaces.com',
        'https://website-preview.lon1.cdn.digitaloceanspaces.com',
    ]);
});

test('a standard AWS disk with no url or endpoint still yields its real origin', function () {
    withS3Config([]);

    // What the app emits for a stored object...
    $emitted = Storage::disk('s3')->url('sites/1/hero.jpg');

    expect(MediaOrigins::all())->toBe(['https://example-bucket.s3.eu-west-2.amazonaws.com'])
        // ...must be covered by what the CSP pins.
        ->and($emitted)->toStartWith(MediaOrigins::all()[0]);
});

test('a path-style endpoint pins the endpoint origin, not bucket-dot-endpoint', function () {
    withS3Config([
        'endpoint' => 'http://minio:9000',
        'use_path_style_endpoint' => true,
    ]);

    $emitted = Storage::disk('s3')->url('sites/1/hero.jpg');

    // The bucket is a path segment here, so `bucket.endpoint` would be the wrong
    // host entirely — and the scheme and port must survive.
    expect(MediaOrigins::all())->toBe(['http://minio:9000'])
        ->and($emitted)->toStartWith('http://minio:9000/');
});

test('a non-default port and scheme are preserved', function () {
    withS3Config(['url' => 'http://localhost:9444/bucket']);

    expect(MediaOrigins::all())->toBe(['http://localhost:9444']);
});

test('an unconfigured disk yields no origins rather than a wildcard', function () {
    withS3Config(['bucket' => '', 'region' => '', 'key' => '', 'secret' => '']);

    // Degrading to an empty source list breaks media visibly; degrading to a
    // wildcard would silently reopen the exfiltration channel this pinning closed.
    expect(MediaOrigins::asSourceList())->not->toContain('*');
});

test('the source list never contains a wildcard host', function () {
    withS3Config(['url' => 'https://website-preview.lon1.digitaloceanspaces.com']);

    expect(MediaOrigins::asSourceList())->not->toContain('*');
});

test('a protocol-relative disk URL is pinned, not dropped', function () {
    withS3Config(['url' => '//cdn.example.test/bucket']);

    // Storage::url() emits `//cdn.example.test/bucket/...` verbatim, so returning []
    // here would block the CSP from allowing media the app is actively linking to.
    // Every surface is forced to https, so that is the scheme to adopt.
    expect(MediaOrigins::all())->toBe(['https://cdn.example.test']);
});

test('a malformed host cannot inject a CSP directive', function () {
    withS3Config(['url' => 'https://ok.example.com; script-src *']);

    // parse_url() accepts the semicolon inside the host, and the result is
    // interpolated straight into the header. Config-controlled, but a host is a host.
    expect(MediaOrigins::asSourceList())->not->toContain('script-src')
        ->and(MediaOrigins::all())->toBe([]);
});

test('a CDN-form URL pins the origin host as well', function () {
    withS3Config(['url' => 'https://website-preview.lon1.cdn.digitaloceanspaces.com']);

    // The non-CDN form pinned both; the CDN form pinned only itself. Storage::url()
    // can emit either, so both are needed either way round.
    expect(MediaOrigins::all())->toBe([
        'https://website-preview.lon1.cdn.digitaloceanspaces.com',
        'https://website-preview.lon1.digitaloceanspaces.com',
    ]);
});

test('the derived origins are memoised', function () {
    withS3Config(['url' => 'https://website-preview.lon1.digitaloceanspaces.com']);

    $first = MediaOrigins::all();

    // Change the config underneath without flushing: a memoised value must persist,
    // which is what proves the second CSP build in the same response is free.
    config(['filesystems.disks.s3.url' => 'https://something-else.example']);

    expect(MediaOrigins::all())->toBe($first);
});
