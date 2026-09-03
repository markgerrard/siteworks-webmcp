<?php

namespace App\Support\Shop;

final class SafeReturnPath
{
    /**
     * A same-origin storefront path, or null if the candidate is unsafe.
     *
     * Relative paths only, must start with `/shop/` after normalisation.
     */
    public static function shop(?string $candidate): ?string
    {
        if (! is_string($candidate) || $candidate === '') {
            return null;
        }
        if (str_contains($candidate, '\\') || str_contains($candidate, "\0")) {
            return null;
        }
        if (! str_starts_with($candidate, '/shop/')) {
            return null;
        }
        if (str_starts_with($candidate, '//')) {
            return null;
        }

        $parts = parse_url($candidate);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user'])) {
            return null;
        }

        $path = $parts['path'] ?? '';
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }
        $normalized = '/'.implode('/', $segments);
        if (! str_starts_with($normalized, '/shop/')) {
            return null;
        }

        $safe = $path;
        if (! empty($parts['query'])) {
            $safe .= '?'.$parts['query'];
        }

        return $safe;
    }
}
