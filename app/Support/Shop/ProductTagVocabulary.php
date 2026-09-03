<?php

namespace App\Support\Shop;

use InvalidArgumentException;

final class ProductTagVocabulary
{
    public const MAX = 40;

    public const TONES = ['accent', 'neutral', 'success', 'warning'];

    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * @return list<array{slug: string, label: string, show_as_badge: bool, tone: string}>
     */
    public static function parse(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (! is_array($raw) || ($raw !== [] && ! array_is_list($raw))) {
            throw new InvalidArgumentException('Product tags must be a list.');
        }

        if (count($raw) > self::MAX) {
            throw new InvalidArgumentException('A site may have at most 40 product tags.');
        }

        $seen = [];
        $out = [];

        foreach ($raw as $index => $row) {
            $tag = self::parseRow($row, $index);
            if (isset($seen[$tag['slug']])) {
                throw new InvalidArgumentException('Product tag slugs must be unique.');
            }
            $seen[$tag['slug']] = true;
            $out[] = $tag;
        }

        return $out;
    }

    /**
     * Lenient read path: drop invalid or duplicate entries.
     *
     * @return list<array{slug: string, label: string, show_as_badge: bool, tone: string}>
     */
    public static function normalize(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $seen = [];
        $out = [];

        foreach (array_values($raw) as $index => $row) {
            try {
                $tag = self::parseRow($row, $index);
            } catch (InvalidArgumentException) {
                continue;
            }
            if (isset($seen[$tag['slug']])) {
                continue;
            }
            $seen[$tag['slug']] = true;
            $out[] = $tag;
            if (count($out) >= self::MAX) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return array{slug: string, label: string, show_as_badge: bool, tone: string}
     */
    private static function parseRow(mixed $row, int $index): array
    {
        if (! is_array($row)) {
            throw new InvalidArgumentException("Tag at index {$index} must be an object.");
        }

        $slug = is_string($row['slug'] ?? null) ? trim($row['slug']) : '';
        if ($slug === '' || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            throw new InvalidArgumentException('Tag slug must be kebab-case.');
        }

        $label = is_string($row['label'] ?? null) ? trim($row['label']) : '';
        if ($label === '') {
            throw new InvalidArgumentException('Tag label is required.');
        }

        $tone = $row['tone'] ?? 'neutral';
        if (! is_string($tone) || ! in_array($tone, self::TONES, true)) {
            throw new InvalidArgumentException('Tag tone must be accent, neutral, success, or warning.');
        }

        return [
            'slug' => $slug,
            'label' => mb_substr($label, 0, 40),
            'show_as_badge' => (bool) ($row['show_as_badge'] ?? false),
            'tone' => $tone,
        ];
    }
}
