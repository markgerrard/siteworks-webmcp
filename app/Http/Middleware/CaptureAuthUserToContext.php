<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

/**
 * Stamps the authenticated user id into the request Context so that
 * LLM calls made from queued jobs dispatched by this request can be
 * attributed to the agent who triggered them.
 *
 * Context is propagated automatically into queued jobs by Laravel 11+,
 * so no thread-through-constructor work is needed.
 */
class CaptureAuthUserToContext
{
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->user()) {
            Context::add('triggered_by_user_id', $request->user()->id);
        }

        return $next($request);
    }
}
