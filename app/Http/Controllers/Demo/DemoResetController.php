<?php

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use App\Services\Demo\DemoSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /demo/reset?token=…            → restore the seed snapshot, return the state (portal host)
 * GET /demo/reset?token=…&assert=1   → state only, changes nothing
 *
 * 404 unless DEMO_MODE and DEMO_RESET_TOKEN are both set; 403 on a wrong token.
 * Exists so the between-takes reset can be a bookmark during filming.
 */
class DemoResetController extends Controller
{
    public function __invoke(Request $request, DemoSnapshot $snapshot): JsonResponse
    {
        $expected = (string) config('demo.reset_token', '');
        if (! (bool) config('demo.enabled', false) || $expected === '') {
            abort(404);
        }
        $given = (string) $request->query('token', '');
        if ($given === '' || ! hash_equals($expected, $given)) {
            abort(403);
        }
        if ($request->boolean('assert')) {
            return response()->json(['ok' => true, 'action' => 'assert'] + $snapshot->state());
        }
        if (! $snapshot->hasSnapshot()) {
            return response()->json(['ok' => false, 'error' => 'NO-SNAPSHOT'], 409);
        }

        return response()->json(['ok' => true, 'action' => 'reset'] + $snapshot->reset());
    }
}
