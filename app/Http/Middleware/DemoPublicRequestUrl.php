<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Demo requests reach PHP-FPM as port 80 (nginx) on two hosts — the portal at
 * APP_URL and the storefront at DEMO_SITE_HOST — while the published origin
 * carries APP_URL's scheme and port. Every generated URL must belong to the
 * host that received the request: a storefront page that posts its cart to the
 * portal host is cross-origin and fails, and signed portal URLs verify against
 * the public port. Console and queue work, which have no request, keep APP_URL.
 */
class DemoPublicRequestUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('demo.enabled')) {
            return $next($request);
        }

        $root = parse_url((string) config('app.url'));
        $port = isset($root['port']) ? (int) $root['port'] : null;
        $host = $request->getHost();

        if ($port !== null && $port !== 80 && $port !== 443) {
            $request->server->set('SERVER_PORT', $port);
            $request->headers->set('HOST', $host.':'.$port);
        }

        $scheme = is_string($root['scheme'] ?? null) && $root['scheme'] !== '' ? $root['scheme'] : $request->getScheme();
        URL::forceRootUrl($scheme.'://'.$request->getHttpHost());

        return $next($request);
    }
}
