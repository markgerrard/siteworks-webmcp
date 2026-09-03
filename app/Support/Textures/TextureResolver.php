<?php

namespace App\Support\Textures;

use App\Models\Site;

final class TextureResolver
{
    /**
     * First-hit context map. Patterns are matched against a lowercase
     * haystack of business_type, business_name, archetype, and profile_data.
     *
     * @var list<array{pattern: string, key: string}>
     */
    public const CONTEXT_MAP = [
        ['pattern' => '/landscaping|garden|grounds|outdoor/', 'key' => 'topography'],
        ['pattern' => '/florist|flower|plant|nursery/', 'key' => 'sprig'],
        ['pattern' => '/bakery|cake|cafe|coffee|food|deli/', 'key' => 'dots'],
        ['pattern' => '/builder|groundwork|civil|construction|paving|excavat/', 'key' => 'diagonal-hatch'],
        ['pattern' => '/joiner|carpent|mason|furniture|craft/', 'key' => 'herringbone'],
        ['pattern' => '/engineer|survey|electrical|technical/', 'key' => 'grid'],
        ['pattern' => '/wellness|beauty|salon|spa|yoga/', 'key' => 'waves'],
        ['pattern' => '/finance|legal|account|property|acquisition|consult/', 'key' => 'noise'],
    ];

    public static function resolve(Site $site): ResolvedTexture
    {
        try {
            return self::resolveOrFallback($site);
        } catch (\Throwable) {
            return self::fromLibrary(TextureLibrary::FALLBACK_KEY, null);
        }
    }

    private static function resolveOrFallback(Site $site): ResolvedTexture
    {
        $explicit = self::normaliseKey($site->texture_key ?? null);

        if ($explicit === 'image') {
            $image = self::fromImage($site);
            if ($image !== null) {
                return $image;
            }
            $explicit = null;
        }

        if ($explicit === 'none') {
            return self::none();
        }

        if ($explicit !== null) {
            return self::fromLibrary($explicit, $site->texture_opacity);
        }

        if (! self::autoDefaultsEnabled()) {
            return self::fromLibrary(TextureLibrary::FALLBACK_KEY, $site->texture_opacity);
        }

        $matched = self::matchContext($site);
        if ($matched !== null) {
            return self::fromLibrary($matched, $site->texture_opacity);
        }

        return self::fromLibrary(self::seededKey($site), $site->texture_opacity);
    }

    public static function none(): ResolvedTexture
    {
        return new ResolvedTexture(
            key: 'none',
            opacity: 0.0,
            size: 0,
            height: 0,
            svgUri: null,
            mode: 'none',
        );
    }

    public static function fromImage(Site $site, ?string $path = null, mixed $opacity = null, string $imageMode = 'tile'): ?ResolvedTexture
    {
        $path ??= is_string($site->texture_image_path) ? $site->texture_image_path : null;
        $url = TextureImage::publicUrl($site, $path);
        if ($url === null) {
            return null;
        }

        if ($imageMode !== 'cover') {
            $imageMode = 'tile';
        }

        $plus = TextureLibrary::get(TextureLibrary::FALLBACK_KEY);

        return new ResolvedTexture(
            key: 'image',
            opacity: self::normaliseOpacity($opacity ?? $site->texture_opacity, $plus['default_opacity'] ?? 0.05),
            size: $plus['default_size'] ?? 60,
            height: $plus['default_height'] ?? 60,
            svgUri: $plus['svg'] ?? null,
            mode: 'image',
            imageMode: $imageMode,
            imageUrl: $url,
        );
    }

    public static function fromLibrary(string $key, mixed $opacity): ResolvedTexture
    {
        $entry = TextureLibrary::get($key) ?? TextureLibrary::get(TextureLibrary::FALLBACK_KEY);
        if ($entry === null || $entry['key'] === 'none') {
            return self::none();
        }

        return new ResolvedTexture(
            key: $entry['key'],
            opacity: self::normaliseOpacity($opacity, $entry['default_opacity']),
            size: $entry['default_size'],
            height: $entry['default_height'],
            svgUri: $entry['svg'],
            mode: 'svg',
        );
    }

    public static function autoDefaultsEnabled(): bool
    {
        return (bool) config('site-textures.auto', true);
    }

    private static function matchContext(Site $site): ?string
    {
        $haystack = self::haystack($site);
        if ($haystack === '') {
            return null;
        }

        foreach (self::CONTEXT_MAP as $row) {
            if (preg_match($row['pattern'], $haystack) === 1) {
                return $row['key'];
            }
        }

        return null;
    }

    private static function haystack(Site $site): string
    {
        $parts = [
            (string) ($site->business_name ?? ''),
            (string) ($site->business_type ?? ''),
        ];

        $profile = $site->businessProfile;
        if ($profile !== null) {
            $parts[] = $profile->archetype()->value;
            $data = $profile->profile_data ?? [];
            if (is_array($data)) {
                array_walk_recursive($data, function (mixed $value) use (&$parts): void {
                    if (is_string($value) || is_numeric($value)) {
                        $parts[] = (string) $value;
                    }
                });
            }
        }

        return strtolower(trim(implode(' ', $parts)));
    }

    private static function seededKey(Site $site): string
    {
        $pool = TextureLibrary::SEEDED_KEYS;
        $id = is_numeric($site->id) ? (int) $site->id : 0;
        $index = $id % count($pool);
        if ($index < 0) {
            $index = 0;
        }

        return $pool[$index];
    }

    private static function normaliseKey(mixed $key): ?string
    {
        if (! is_string($key)) {
            return null;
        }

        $key = trim($key);
        if ($key === '') {
            return null;
        }

        if ($key === 'image') {
            return 'image';
        }

        if ($key === 'none') {
            return 'none';
        }

        if (TextureLibrary::has($key)) {
            return $key;
        }

        return TextureLibrary::FALLBACK_KEY;
    }

    private static function normaliseOpacity(mixed $raw, float $default): float
    {
        if (! is_numeric($raw)) {
            return $default;
        }

        $value = (float) $raw;
        if ($value < 0.01 || $value > 0.5) {
            return $default;
        }

        return $value;
    }
}
