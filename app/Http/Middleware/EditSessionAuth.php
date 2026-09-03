<?php

namespace App\Http\Middleware;

use App\Services\Site\EditSessionCookie;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates the edit_session cookie on /_edit/* routes.
 *
 * - Origin check: request Origin (or Referer host) must match the Host header
 *   exactly, blocking cross-subdomain CSRF that SameSite=Lax doesn't prevent.
 * - Per-session CSRF: X-Edit-Csrf header must match the csrf field embedded in
 *   the signed edit_session cookie payload.
 * - Reads and validates the HMAC-signed edit_session cookie.
 * - Authenticates the user for this request only (Auth::onceUsingId).
 * - Checks view policy against the site.
 * - Stores site_id in request attributes for downstream controllers.
 */
class EditSessionAuth
{
    public function __construct(protected EditSessionCookie $editSession) {}

    public function handle(Request $request, Closure $next): Response
    {
        // ── Origin / Referer check ──────────────────────────────────────────
        // SameSite=Lax does not block cross-subdomain POSTs that share an eTLD+1; Origin/Referer host must match Host exactly.
        //
        // GET requests (publish-summary) are read-only and carry no Origin —
        // skip the check for them. All state-mutating routes use POST.
        if ($request->isMethod('POST') || $request->isMethod('PUT') || $request->isMethod('PATCH') || $request->isMethod('DELETE')) {
            $this->assertSameOrigin($request);
        }

        $raw = $request->cookie('edit_session');
        if (! $raw) {
            abort(401, 'Edit session cookie missing.');
        }

        $payload = $this->editSession->validate((string) $raw);
        if ($payload === null) {
            abort(401, 'Edit session cookie is invalid or expired.');
        }

        // ── Per-session CSRF token check ────────────────────────────────────
        // Cookies minted before this field was added will have an empty csrf;
        // they are rejected to force a fresh token redemption.
        $this->assertEditCsrf($request, $payload['csrf'] ?? '');

        $user = Auth::onceUsingId($payload['user_id']);
        if (! $user) {
            abort(401, 'Edit session user not found.');
        }

        // Belt-and-braces: if User ever gains SoftDeletes, a deleted user's ID
        // still resolves via onceUsingId — reject them explicitly.
        if (method_exists($user, 'trashed') && $user->trashed()) {
            abort(401, 'Edit session user has been deleted.');
        }

        $site = \App\Models\Site::find($payload['site_id']);
        if (! $site) {
            abort(403, 'Site not found.');
        }

        if (! Gate::allows('update', $site)) {
            abort(403, 'You do not have permission to edit this site.');
        }

        $request->attributes->set('edit_site_id', $payload['site_id']);
        $request->attributes->set('edit_site', $site);

        return $next($request);
    }

    /**
     * Assert the request originates from the same host.
     *
     * Uses Origin header first (set by browsers on cross-origin requests and
     * on same-origin POSTs in most modern browsers). Falls back to the host
     * extracted from the Referer header (sent on same-origin navigations where
     * Origin may be absent). If neither header is present we allow through —
     * server-to-server or curl calls don't send Origin/Referer and are already
     * blocked by the missing cookie.
     */
    private function assertSameOrigin(Request $request): void
    {
        $host = strtolower($request->getHost());

        $origin = $request->header('Origin');
        if ($origin) {
            $originHost = strtolower((string) parse_url((string) $origin, PHP_URL_HOST));
            if ($originHost !== $host) {
                abort(403, 'Cross-origin request blocked.');
            }

            return;
        }

        $referer = $request->header('Referer');
        if ($referer) {
            $refererHost = strtolower((string) parse_url((string) $referer, PHP_URL_HOST));
            if ($refererHost !== $host) {
                abort(403, 'Cross-origin request blocked (referer mismatch).');
            }
        }
        // No Origin and no Referer → allow through (non-browser clients).
    }

    /**
     * Assert the X-Edit-Csrf header matches the csrf embedded in the cookie payload.
     * Constant-time comparison to prevent timing attacks.
     */
    private function assertEditCsrf(Request $request, string $expectedCsrf): void
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            // Read-only requests don't need the CSRF header.
            return;
        }

        $provided = (string) $request->header('X-Edit-Csrf', '');

        if ($expectedCsrf === '' || ! hash_equals($expectedCsrf, $provided)) {
            abort(403, 'Missing or invalid X-Edit-Csrf token.');
        }
    }
}
