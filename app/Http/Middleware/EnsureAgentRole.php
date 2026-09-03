<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureAgentRole
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = Auth::user();

        if (! $user || ! $user->role) {
            Auth::logout();

            return redirect()->route('agent.login')
                ->withErrors(['auth' => 'Access denied. Contact IT.']);
        }

        if ($user->trashed()) {
            Auth::logout();

            return redirect()->route('agent.login')
                ->withErrors(['auth' => 'Your account has been revoked.']);
        }

        return $next($request);
    }
}
