<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureClientUser
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = Auth::user();

        if ($user?->role !== null) {
            Auth::logout();

            return redirect()->away(
                'https://'.config('domains.agent_domain').'/login'
            );
        }

        return $next($request);
    }
}
