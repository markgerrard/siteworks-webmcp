<?php

namespace App\Support\Shop;

use App\Exceptions\Shop\UnknownProductTagsException;
use InvalidArgumentException;

final class ProductTagAssignment
{
    public const MAX = 5;

    /**
     * @param  list<array{slug: string, label: string, show_as_badge: bool, tone: string}>  $vocabulary
     * @return list<string>
     */
    public static function parse(mixed $raw, array $vocabulary): array
    {
        $allowed = self::allowedSlugs($vocabulary);
        $slugs = self::stringList($raw, strict: true);

        if (count($slugs) > self::MAX) {
            throw new UnknownProductTagsException('A product may have at most 5 tags.');
        }

        $unknown = [];
        $out = [];
        $seen = [];
        foreach ($slugs as $slug) {
            if (! isset($allowed[$slug])) {
                $unknown[] = $slug;

                continue;
            }
            if (isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $out[] = $slug;
        }

        if ($unknown !== []) {
            $valid = implode(', ', array_keys($allowed));
            throw new UnknownProductTagsException(
                'Unknown tag slugs: '.implode(', ', $unknown).'. Valid slugs: '.$valid.'.',
            );
        }

        return $out;
    }

    /**
     * @param  list<array{slug: string, label: string, show_as_badge: bool, tone: string}>  $vocabulary
     * @return list<string>
     */
    public static function normalize(mixed $raw, array $vocabulary): array
    {
        $allowed = self::allowedSlugs($vocabulary);
        $out = [];
        $seen = [];

        foreach (self::stringList($raw, strict: false) as $slug) {
            if (! isset($allowed[$slug]) || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $out[] = $slug;
            if (count($out) >= self::MAX) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  list<array{slug: string, label: string, show_as_badge: bool, tone: string}>  $vocabulary
     * @return array<string, true>
     */
    private static function allowedSlugs(array $vocabulary): array
    {
        $allowed = [];
        foreach ($vocabulary as $tag) {
            if (is_string($tag['slug'] ?? null) && $tag['slug'] !== '') {
                $allowed[$tag['slug']] = true;
            }
        }

        return $allowed;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $raw, bool $strict): array
    {
        if ($raw === null) {
            return [];
        }

        if (! is_array($raw)) {
            if ($strict) {
                throw new UnknownProductTagsException('Product tags must be a list of slugs.');
            }

            return [];
        }

        $out = [];
        foreach ($raw as $value) {
            if (! is_string($value) || $value === '') {
                if ($strict) {
                    throw new UnknownProductTagsException('Product tags must be a list of slugs.');
                }

                continue;
            }
            $out[] = $value;
        }

        return $out;
    }
}
