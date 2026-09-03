<?php

namespace App\Support;

final class NavCta
{
    public static function safeUrl(?string $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }
        // Fail closed: match (1) and preg_match error (false) both reject. Scan the
        // untrimmed input so C0/C1, format, and line/para separators cannot be trimmed away.
        if (preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $url) !== 0) {
            return null;
        }
        $url = trim($url);
        if ($url === '' || strlen($url) > 255) {
            return null;
        }
        if (preg_match('#^/(?![/\\\\])#', $url) === 1) {
            return $url;
        }
        if (stripos($url, 'https://') === 0) {
            $parts = parse_url($url);
            if (! is_array($parts)
                || strtolower($parts['scheme'] ?? '') !== 'https'
                || empty($parts['host'])
                || isset($parts['user'])
                || isset($parts['pass'])
                || str_contains($url, '\\')
                || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/i', $parts['host']) !== 1) {
                return null;
            }

            return $url;
        }
        if (preg_match('/^tel:\+?[0-9 ()-]{6,20}$/', $url) === 1) {
            return $url;
        }

        return null;
    }
}
