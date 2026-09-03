<?php

namespace App\Http\Responses;

use App\Support\AuthLanding;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;

/**
 * Custom Fortify logout response — redirects to the customer-domain root
 * (post-9A surface split). Default Fortify returns relative '/' which
 * resolves against an inconsistent host depending on where the logout
 * POST originated; AuthLanding::for(null) pins it to the customer surface
 * so logged-out users always land on the customer-facing home.
 */
class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request)
    {
        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : redirect()->to(AuthLanding::for(null));
    }
}
