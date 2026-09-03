<?php

namespace App\Support;

class FacebookUrlValidator
{
    /** @var array<int, string> */
    private const RESERVED_PATHS = [
        'login',
        'share',
        'photo',
        'photos',
        'watch',
        'groups',
        'events',
        'pages',
        'pg',
    ];

    public static function isImportablePageUrl(string $url): bool
    {
        return self::normalisePageUrl($url) !== null;
    }

    public static function normalisePageUrl(string $url, bool $allowHttp = false): ?string
    {
        $parts = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https' && (! $allowHttp || $scheme !== 'http')) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($host, ['facebook.com', 'www.facebook.com'], true)) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', (string) ($parts['path'] ?? ''))));
        if (count($segments) !== 1) {
            return null;
        }

        $handle = $segments[0];
        if (in_array(strtolower($handle), self::RESERVED_PATHS, true)) {
            return null;
        }

        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,127}$/', $handle)) {
            return null;
        }

        return 'https://www.facebook.com/'.$handle;
    }

    public static function extractFromScrapeData(mixed $scrapeData): ?string
    {
        $haystack = self::toSearchableString($scrapeData);
        if ($haystack === '') {
            return null;
        }

        preg_match_all(
            '/https?:\/\/(?:www\.)?facebook\.com\/[A-Za-z0-9][A-Za-z0-9._-]{2,127}(?:[\/?#][^\s<>"\']*)?/i',
            $haystack,
            $matches,
        );

        foreach ($matches[0] ?? [] as $candidate) {
            $normalised = self::normalisePageUrl(rtrim($candidate, ".,);]}'\""), allowHttp: true);
            if ($normalised !== null) {
                return $normalised;
            }
        }

        return null;
    }

    private static function toSearchableString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value) || is_object($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return is_string($encoded) ? $encoded : '';
        }

        return '';
    }
}
