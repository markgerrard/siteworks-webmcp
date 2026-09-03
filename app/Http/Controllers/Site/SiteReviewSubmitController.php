<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\SiteReview;
use App\Services\Site\SiteHostResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public endpoint for the native on-site review form (native_reviews
 * section). Security posture:
 *  - 404 unless BOTH the platform flag and the site's toggle are on, so
 *    the endpoint is invisible while the feature is dark.
 *  - Site resolution: the request host must
 *    resolve to a real site; no site id is accepted from the client.
 *  - throttle:site-reviews (per IP + per host) + a honeypot field that
 *    pretends success without storing, so bots get no signal.
 *  - Submissions land as Pending; nothing renders publicly until an
 *    operator approves (site-reviews:moderate).
 */
class SiteReviewSubmitController extends Controller
{
    public function __construct(protected SiteHostResolver $hostResolver) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(config('site.native_reviews_enabled'), 404);

        $site = $this->hostResolver->resolve($request);
        abort_unless($site && $site->native_reviews_enabled, 404);

        $validated = $request->validate([
            'author_name' => ['required', 'string', 'max:80'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'text' => ['required', 'string', 'max:2000'],
            'website' => ['nullable', 'string', 'max:255'],
        ]);

        if (($validated['website'] ?? '') !== '') {
            return response()->json(['status' => 'ok']);
        }

        SiteReview::create([
            'site_id' => $site->id,
            'author_name' => $validated['author_name'],
            'rating' => $validated['rating'],
            'text' => $validated['text'],
            'ip_hash' => hash('sha256', (string) $request->ip()),
        ]);

        return response()->json(['status' => 'ok']);
    }
}
