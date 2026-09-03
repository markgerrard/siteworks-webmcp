<?php

namespace App\Services\Site;

use Illuminate\Support\Str;

/**
 * Stateless HMAC-signed token for cross-domain edit sessions.
 *
 * Token format (base64url):  <json-payload>.<hmac-sha256-hex>
 *
 * The payload carries site_id, user_id, page_id, and expires_at (unix
 * timestamp). No DB writes — tokens are validated purely by HMAC + TTL.
 */
class EditSessionToken
{
    private const ALGO = 'sha256';

    /**
     * Mint a signed token for the given site/user/page combination.
     */
    public function mint(int $siteId, int $userId, int $pageId, int $ttlSeconds = 1800): string
    {
        $b64url = $this->toBase64Url(json_encode([
            'site_id' => $siteId,
            'user_id' => $userId,
            'page_id' => $pageId,
            'expires_at' => time() + $ttlSeconds,
        ]));

        $sig = $this->sign($b64url);

        return "{$b64url}.{$sig}";
    }

    /**
     * Validate a token.
     *
     * @return array{site_id: int, user_id: int, page_id: int, expires_at: int}|null
     */
    public function validate(string $token): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$b64payload, $sig] = $parts;

        // Constant-time comparison to prevent timing attacks
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
            'site_id' => (int) ($decoded['site_id'] ?? 0),
            'user_id' => (int) ($decoded['user_id'] ?? 0),
            'page_id' => (int) ($decoded['page_id'] ?? 0),
            'expires_at' => (int) ($decoded['expires_at'] ?? 0),
        ];
    }

    /**
     * Validate and additionally assert the token belongs to the given site+page.
     *
     * @return array{site_id: int, user_id: int, page_id: int, expires_at: int}|null
     */
    public function validateForPage(string $token, int $siteId, int $pageId): ?array
    {
        $payload = $this->validate($token);
        if ($payload === null) {
            return null;
        }

        if ($payload['site_id'] !== $siteId || $payload['page_id'] !== $pageId) {
            return null;
        }

        return $payload;
    }

    /**
     * Sign a base64url payload string using APP_KEY.
     * Returns a hex HMAC (no base64 encoding to avoid delimiter collisions).
     */
    private function sign(string $b64urlPayload): string
    {
        return hash_hmac(self::ALGO, $b64urlPayload, $this->secret());
    }

    /**
     * Encode raw bytes/string to base64url (no padding).
     */
    private function toBase64Url(string $raw): string
    {
        return Str::replace(['+', '/', '='], ['-', '_', ''], base64_encode($raw));
    }

    /**
     * Decode a base64url string to raw bytes/string.
     */
    private function fromBase64Url(string $b64url): string
    {
        return base64_decode(Str::replace(['-', '_'], ['+', '/'], $b64url));
    }

    /**
     * Derive a stable 32-byte key from APP_KEY.
     *
     * APP_KEY may have a "base64:" prefix (set by key:generate). We strip it
     * and decode so we always work from the raw bytes.
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

        return hash_hmac('sha256', 'edit-session-token', (string) $key, true);
    }
}
