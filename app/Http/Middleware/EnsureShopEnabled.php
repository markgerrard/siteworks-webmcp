<?php

namespace App\Http\Middleware;

use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureShopEnabled
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $mode = 'enabled'): Response
    {
        $site = $request->route('site');
        if (! $site instanceof Site) {
            $site = is_numeric($site) ? Site::query()->find((int) $site) : null;
        }

        // 'established' keeps owed-after-payment surfaces (order fulfilment, refunds) reachable
        // for a site that has taken orders even after the flag is turned off.
        $allowed = $site instanceof Site && ($mode === 'established' ? ($site->shopEnabled() || $site->hasEstablishedShop()) : $site->shopEnabled());
        abort_unless($allowed, 404);

        return $next($request);
    }
}
