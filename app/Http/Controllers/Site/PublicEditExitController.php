<?php

namespace App\Http\Controllers\Site;

use App\Services\Site\EditSessionCookie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Ends an edit session by forgetting the HMAC-signed edit_session cookie.
 *
 * The cookie is HttpOnly so JS can't clear it directly — this endpoint
 * is the only safe way out. Runs behind EditSessionAuth middleware so
 * it only fires for callers who already have a valid session to close.
 *
 * The client reloads after this call; with no cookie present the next
 * render falls back to public mode and the editor chrome vanishes.
 */
class PublicEditExitController
{
    /**
     * Clear the edit_session cookie + redirect to a safe same-host path.
     * Used by the admin "View Preview" button so clicking it always lands
     * on the live public version, even if the current browser has an
     * active edit_session cookie from a previous Edit Live session.
     *
     * GET because it's idempotent (cookie-clearing is safe to replay)
     * and a plain link from admin avoids form/CSRF wiring for the common
     * "show me what the public sees" action.
     *
     * Target path is validated same-host-relative — no open redirect.
     */
    public function viewLive(Request $request): \Illuminate\Http\RedirectResponse
    {
        $to = (string) $request->query('to', '/');
        $to = $this->safeSameHostPath($to);

        $response = redirect($to, 302);
        $response->headers->setCookie($this->forgetEditSessionCookie());

        return $response;
    }

    /**
     * Accept only same-origin paths. Rejects:
     *   - Empty
     *   - Anything not starting with /
     *   - Protocol-relative //host
     *   - Backslash (browsers normalise /\evil.com to //evil.com)
     *   - URL-encoded variants of the above (%5C = \, %2F%2F = //)
     *   - Control characters (NUL, tabs, newlines; some parsers split here)
     *
     * Falls back to "/" on any violation. Keeps the endpoint usable for
     * the happy case while refusing to become an open-redirect gadget.
     */
    protected function safeSameHostPath(string $to): string
    {
        if ($to === '' || ! str_starts_with($to, '/')) {
            return '/';
        }

        // Reject pre-decoded protocol-relative and backslash forms.
        if (str_starts_with($to, '//') || str_contains($to, '\\')) {
            return '/';
        }

        // Reject ASCII control chars (0x00 – 0x1F + 0x7F) anywhere.
        if (preg_match('/[\x00-\x1F\x7F]/', $to) === 1) {
            return '/';
        }

        // Decode percent-encodings and re-check — browsers resolve
        // Location headers AFTER decoding, so an encoded //evil or
        // /\\evil would otherwise sneak through.
        $decoded = rawurldecode($to);
        if (str_starts_with($decoded, '//')
            || str_contains($decoded, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1
        ) {
            return '/';
        }

        return $to;
    }

    public function __invoke(Request $request): JsonResponse
    {
        /** @var JsonResponse $response */
        $response = response()->json(['ok' => true]);
        $response->headers->setCookie($this->forgetEditSessionCookie());

        return $response;
    }

    /**
     * Build a forget cookie that matches the ORIGINAL edit_session
     * cookie's scope — host-only (no Domain attribute), same path,
     * same security flags. Must match exactly or the browser ignores
     * the forget and the session persists.
     *
     * Laravel's Cookie::forget helper stamps SESSION_DOMAIN onto every cookie
     * by default; the forget cookie must stay host-only to match the original.
     */
    protected function forgetEditSessionCookie(): Cookie
    {
        return Cookie::create(
            name: EditSessionCookie::NAME,
            value: '',
            expire: 1, // epoch + 1s — firmly in the past
            path: '/',
            domain: null, // host-only, matching EditSessionCookie::make
            secure: true,
            httpOnly: true,
            raw: false,
            sameSite: 'Lax',
        );
    }
}
