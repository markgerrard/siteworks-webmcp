<?php

namespace App\Http\Responses;

use App\Support\AuthLanding;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

/**
 * Custom Fortify login response — picks the post-login target based on
 * user role so staff land on the agent-domain dashboard and clients land
 * on the primary-domain client home. Without this, Fortify redirects to
 * config('fortify.home') = /dashboard, a staff-only route on the agent
 * subdomain; clients 404.
 *
 * url.intended is deliberately discarded. In a split-auth world with two
 * hosts and role-gated middleware, honouring a previously-stashed URL can
 * deliver a client to a staff route (or vice-versa) where they'll just
 * be rejected. Role-based landing is the authoritative post-login target.
 */
class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return response()->json(['two_factor' => false]);
        }

        $request->session()->forget('url.intended');

        return redirect()->to(AuthLanding::for($request->user()));
    }
}
