<?php

namespace App\Support\Shop;

final class ProductBlockSource
{
    public const PATTERN = '/^(manual|featured|newest|tag:[a-z0-9]+(?:-[a-z0-9]+)*|category:[a-z0-9]+(?:-[a-z0-9]+)*)$/';

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * @return array{kind: string, slug: ?string}
     */
    public static function parse(mixed $value): array
    {
        $raw = is_string($value) && $value !== '' ? $value : 'featured';
        if ($raw === 'manual' || $raw === 'featured') {
            return ['kind' => 'featured', 'slug' => null];
        }
        if ($raw === 'newest') {
            return ['kind' => 'newest', 'slug' => null];
        }
        if (str_starts_with($raw, 'tag:')) {
            return ['kind' => 'tag', 'slug' => substr($raw, 4)];
        }
        if (str_starts_with($raw, 'category:')) {
            return ['kind' => 'category', 'slug' => substr($raw, 9)];
        }

        return ['kind' => 'featured', 'slug' => null];
    }

    public static function requiresPair(string $kind): bool
    {
        return in_array($kind, ['newest', 'tag', 'category'], true);
    }
}
