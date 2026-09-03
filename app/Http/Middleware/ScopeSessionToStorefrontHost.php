<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Give storefront/preview hosts a host-only session cookie (no Domain attribute).
 * Configured first-party surfaces keep the shared SESSION_DOMAIN cookie.
 * Must run BEFORE StartSession.
 */
class ScopeSessionToStorefrontHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());

        if ($this->isSiteWorksSurface($host)) {
            return $next($request);
        }

        // Anything else reaching this app is a storefront/preview/custom host:
        // host-only cookie (no Domain attribute).
        //
        // A host-only cookie set on one storefront host is never sent to another, so
        // sibling tenants cannot see each other's session.
        config(['session.domain' => null]);

        return $next($request);
    }

    /**
     * True only for the exact configured first-party surface hosts (allow-list, not apex descent).
     * Cookie scoping must not depend on constructing an API client.
     */
    private function isSiteWorksSurface(string $host): bool
    {
        $surfaces = array_filter([
            (string) config('domains.agent_domain'),
            (string) config('domains.primary_domain'),
            (string) config('domains.customer_domain'),
            (string) config('domains.editor_preview_domain'),
        ]);

        foreach ($surfaces as $surface) {
            if ($host === strtolower($surface)) {
                return true;
            }
        }

        return false;
    }
}
