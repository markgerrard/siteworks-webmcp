<?php

namespace App\Support;

use App\Models\User;

/**
 * Resolves the post-login landing URL for a user based on role.
 *
 * Staff go to the agent-domain dashboard (Cloudflare Access + agent.only
 * middleware gate the request). Clients go to the customer-domain account
 * stub (post-9A surface split — `/` and `/account` now live on the
 * customer surface, not the primary). Unauthenticated / unknown falls
 * back to the customer-domain root for the same reason.
 *
 * Used by FortifyServiceProvider's LoginResponse binding and the
 * customer-domain home route so both paths agree on where a given user
 * belongs.
 */
class AuthLanding
{
    public static function for(?User $user): string
    {
        if ($user === null) {
            return self::origin((string) config('domains.customer_domain')).'/';
        }

        if ($user->isStaff()) {
            return self::origin((string) config('domains.agent_domain')).'/dashboard';
        }

        $customer = self::origin((string) config('domains.customer_domain'));

        if ($user->isClientUser()) {
            // Pull just id + total count — earlier code hydrated every
            // accessible site row only to read $sites->count() and the
            // first id. For a client with N sites that was N rows + the
            // overhead of model boot per row.
            $accessibleSites = $user->accessibleSites();
            $count = (clone $accessibleSites)->count();

            if ($count === 0) {
                return $customer.'/portal';
            }

            if ($count === 1) {
                return $customer.'/sites/'.$accessibleSites->value('id');
            }

            return $customer.'/sites';
        }

        return $customer.'/account';
    }

    private static function origin(string $domain): string
    {
        if (config('demo.enabled')) {
            return rtrim((string) config('app.url'), '/');
        }

        return 'https://'.$domain;
    }
}
