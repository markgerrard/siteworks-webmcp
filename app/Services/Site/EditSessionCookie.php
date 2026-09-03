<?php

namespace App\Services\Site;

use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * HMAC-signed edit_session cookie.
 *
 * Cookie value format (base64url): <json-payload>.<hmac-sha256-hex>
 *
 * The payload carries user_id, site_id, and expires_at.
 * Separate from EditSessionToken so token ↔ cookie lifecycle are independent.
 */
class EditSessionCookie
{
    private const ALGO = 'sha256';

    public const NAME = 'edit_session';

    /**
     * Build a signed Cookie object from the validated token payload.
     * Embeds a per-session random CSRF token in the payload for use by
     * the EditSessionAuth middleware (X-Edit-Csrf header check).
     *
     * @param  array{site_id: int, user_id: int, page_id: int, expires_at: int}  $tokenPayload
     */
    public function make(array $tokenPayload, string $host): Cookie
    {
        $expiresAt = $tokenPayload['expires_at'];

        $b64url = $this->toBase64Url(json_encode([
            'user_id' => $tokenPayload['user_id'],
            'site_id' => $tokenPayload['site_id'],
            'expires_at' => $expiresAt,
            'csrf' => $this->generateCsrf(),
        ]));

        $sig = $this->sign($b64url);
        $value = "{$b64url}.{$sig}";

        return new Cookie(
            name: self::NAME,
            value: $value,
            expire: $expiresAt,
            path: '/',
            domain: null,          // scoped to the current host only
            secure: true,
            httpOnly: true,
            raw: false,
            sameSite: 'Lax',
        );
    }

    /**
     * Validate a raw cookie value.
     *
     * @return array{user_id: int, site_id: int, expires_at: int, csrf: string}|null
     */
    public function validate(string $value): ?array
    {
        $parts = explode('.', $value, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$b64payload, $sig] = $parts;

        if (! hash_equals($this->sign($b64payload), $sig)) {
            return null;
        }

        $decoded = json_decode($this->fromBase64Url($b64payload), true);
        if (! is_array($decoded)) {
            return null;
        }

        if (($decoded['expires_at'] ?? 0) < time()) {
            return null;
        }

        return [
            'user_id' => (int) ($decoded['user_id'] ?? 0),
            'site_id' => (int) ($decoded['site_id'] ?? 0),
            'expires_at' => (int) ($decoded['expires_at'] ?? 0),
            'csrf' => (string) ($decoded['csrf'] ?? ''),
        ];
    }

    /**
     * Sign a base64url payload string using APP_KEY.
     * Returns a hex HMAC (safe as a second token segment after a single dot).
     */
    private function sign(string $b64urlPayload): string
    {
        return hash_hmac(self::ALGO, $b64urlPayload, $this->secret());
    }

    /**
     * Generate a cryptographically random CSRF token (22+ chars, base64url).
     */
    private function generateCsrf(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    private function toBase64Url(string $raw): string
    {
        return Str::replace(['+', '/', '='], ['-', '_', ''], base64_encode($raw));
    }

    private function fromBase64Url(string $b64url): string
    {
        return base64_decode(Str::replace(['-', '_'], ['+', '/'], $b64url));
    }

    /**
     * Derive a stable key from APP_KEY, distinct from the token key.
     *
     * @throws \RuntimeException if APP_KEY is empty or too short to be safe.
     */
    private function secret(): string
    {
        $key = config('app.key');
        if (str_starts_with((string) $key, 'base64:')) {
            $key = base64_decode(substr((string) $key, 7));
        }

        if (strlen((string) $key) < 16) {
            throw new \RuntimeException('APP_KEY is empty or too short; cannot derive a safe HMAC key.');
        }

        return hash_hmac('sha256', 'edit-session-cookie', (string) $key, true);
    }
}
