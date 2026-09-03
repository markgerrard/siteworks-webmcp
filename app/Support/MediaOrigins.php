<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The exact object-store origins this deployment serves media from.
 *
 * `https://*.digitaloceanspaces.com` is never used as a source: Spaces bucket names
 * are self-service, so any attacker can register one and use it as a GET-based
 * exfiltration channel — a wildcard would let a request to an arbitrary Spaces
 * host pass the CSP, while connect-src 'self' correctly refuses the equivalent
 * fetch(). This class pins the exact deployed bucket origin(s) instead.
 *
 * Derived from the configured disk rather than hardcoded, so each deployment
 * pins its own bucket. Shared by both CSP middlewares so the two
 * policies cannot drift apart.
 */
class MediaOrigins
{
    /**
     * Memoised in the CONTAINER, not a static property: the container is rebuilt per
     * request in production and per test in the suite, so the value cannot leak
     * between tests the way a static would.
     */
    private const CACHE_KEY = 'media-origins.resolved';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        // Both CSP middlewares ask for this, and the strict policy asks twice per
        // response. Without memoisation that meant resolving an S3 adapter
        // repeatedly just to build a header.
        if (app()->bound(self::CACHE_KEY)) {
            return app()->make(self::CACHE_KEY);
        }

        $origin = self::originFromDisk();

        if ($origin === null) {
            app()->instance(self::CACHE_KEY, []);

            return [];
        }

        $origins = [$origin];

        // DigitalOcean serves the same bucket through a CDN alias on the same name
        // with `.cdn.` inserted; hero media URLs use both forms.
        $host = (string) parse_url($origin, PHP_URL_HOST);

        if (str_ends_with($host, '.digitaloceanspaces.com') && ! str_contains($host, '.cdn.')) {
            $origins[] = str_replace('.digitaloceanspaces.com', '.cdn.digitaloceanspaces.com', $origin);
        } elseif (str_contains($host, '.cdn.digitaloceanspaces.com')) {
            // A CDN-form URL previously pinned only the CDN host, while the non-CDN
            // form pinned both. Storage::url() can emit either, so pin both ways.
            $origins[] = str_replace('.cdn.digitaloceanspaces.com', '.digitaloceanspaces.com', $origin);
        }

        app()->instance(self::CACHE_KEY, $origins);

        return $origins;
    }

    /**
     * Forget the memoised value. Needed where config changes within one request.
     */
    public static function flush(): void
    {
        app()->forgetInstance(self::CACHE_KEY);
    }

    /**
     * Ask the disk what URL it would emit, and keep that URL's origin.
     *
     * Deriving the host from config keys by hand understood exactly one config
     * shape. A standard AWS disk (no `url`, no `endpoint`) yielded nothing, which
     * empties both CSPs' media allowlists and blocks every image and video the app
     * serves; a path-style endpoint yielded `bucket.endpoint`, which is the wrong
     * host entirely; and any non-default scheme or port was discarded. Since
     * `Storage::disk('s3')->url()` is what builds every media URL in the app, that
     * is the thing the CSP has to agree with.
     */
    private static function originFromDisk(): ?string
    {
        // An explicit disk URL is authoritative and costs nothing to read. Every
        // deployment sets one, so the common path never constructs an S3 client
        // just to compute a response header.
        $url = (string) config('filesystems.disks.s3.url');

        if ($url === '') {
            // No explicit URL: ask the disk what it would emit. This covers a
            // standard AWS disk (bucket.s3.region.amazonaws.com) and a path-style
            // endpoint, neither of which can be derived by concatenating config.
            try {
                $url = Storage::disk('s3')->url('csp-origin-probe');
            } catch (Throwable) {
                return null;
            }
        }

        // A protocol-relative URL (`//cdn.example/bucket`) is legal in the disk
        // config and Storage::url() emits it verbatim, but parse_url() yields no
        // scheme — so the origin came back null and the CSP blocked media the app
        // was still linking to. Every surface is forced to https, so adopt that.
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        // The origin is interpolated straight into a CSP header. parse_url() happily
        // accepts a semicolon inside a host, so a malformed disk URL could inject a
        // whole directive (`https://ok.example; script-src *`). Config-controlled
        // rather than attacker-controlled, but a host is a host.
        // DNS names (underscores occur in real internal hostnames), IPv4 literals, and
        // bracketed IPv6 literals. The first version accepted only [a-z0-9.-], which
        // rejects `[2001:db8::10]` — a host Storage::url() can legitimately emit, and
        // rejecting it silently drops every media origin from the CSP.
        $host = $parts['host'];
        $isBracketedIpv6 = str_starts_with($host, '[') && str_ends_with($host, ']')
            && filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        if (! $isBracketedIpv6 && ! preg_match('/^[a-z0-9._-]+$/i', $host)) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$host;

        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }

    public static function asSourceList(): string
    {
        return implode(' ', self::all());
    }
}
