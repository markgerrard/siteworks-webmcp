<?php

namespace App\Http\Middleware\Testing;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Browser-test shim, no-op outside the test suite.
 *
 * pest-plugin-browser pins the URL generator's asset origin to its own
 * 127.0.0.1:<port> address. When a browser test visits the app through a
 * mapped domain (customer.domain.com:<port> etc.), that makes every Vite
 * bundle a cross-origin module script — which the plugin's static file
 * server can't satisfy (no CORS headers) and script-src 'self' forbids.
 * Re-pinning the asset origin to the requesting origin keeps every asset
 * same-origin with whatever host the page was served on.
 */
class UseRequestAssetOrigin
{
    public function handle(Request $request, Closure $next)
    {
        if (app()->runningUnitTests()) {
            URL::useAssetOrigin($request->getSchemeAndHttpHost());
        }

        return $next($request);
    }
}
