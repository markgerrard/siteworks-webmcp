<?php

namespace App\Support\Shop;

use InvalidArgumentException;

final class ShopIndexBlocks
{
    public const MAX = 8;

    /**
     * @return list<array<string, mixed>>
     */
    public static function parse(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (! is_array($raw) || ($raw !== [] && ! array_is_list($raw))) {
            throw new InvalidArgumentException('Shop index blocks must be a list.');
        }
        if (count($raw) > self::MAX) {
            throw new InvalidArgumentException('A shop index may have at most 8 product blocks.');
        }

        $out = [];
        foreach ($raw as $index => $row) {
            $out[] = self::parseRow($row, $index, strict: true);
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function normalize(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach (array_values($raw) as $index => $row) {
            try {
                $out[] = self::parseRow($row, $index, strict: false);
            } catch (InvalidArgumentException) {
                continue;
            }
            if (count($out) >= self::MAX) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseRow(mixed $row, int $index, bool $strict): array
    {
        if (! is_array($row)) {
            throw new InvalidArgumentException("Block at index {$index} must be an object.");
        }

        $type = $row['type'] ?? 'featured_products';
        if (! in_array($type, ['featured_products', 'trust_strip'], true)) {
            throw new InvalidArgumentException('Block type must be featured_products or trust_strip.');
        }

        if ($type === 'trust_strip') {
            return self::parseTrustRow($row, $strict);
        }

        $source = $row['source'] ?? 'newest';
        if (! ProductBlockSource::isValid($source)) {
            if ($strict) {
                throw new InvalidArgumentException('Block source is invalid.');
            }
            $source = 'newest';
        }

        $layout = $row['layout'] ?? 'grid';
        if (! in_array($layout, ['grid', 'carousel'], true)) {
            if ($strict) {
                throw new InvalidArgumentException('Block layout must be grid or carousel.');
            }
            $layout = 'grid';
        }

        $heading = is_string($row['heading'] ?? null) ? trim($row['heading']) : '';
        if ($heading === '' && $strict) {
            throw new InvalidArgumentException('Block heading is required.');
        }

        return [
            'source' => (string) $source,
            'limit' => max(4, min(12, (int) ($row['limit'] ?? 4))),
            'layout' => $layout,
            'heading' => mb_substr($heading !== '' ? $heading : 'Products', 0, 80),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function parseTrustRow(array $row, bool $strict): array
    {
        $sources = $row['sources'] ?? 'both';
        if (! in_array($sources, ['site', 'product', 'both'], true)) {
            if ($strict) {
                throw new InvalidArgumentException('Trust block sources must be site, product, or both.');
            }
            $sources = 'both';
        }

        $layout = $row['layout'] ?? 'strip';
        if (! in_array($layout, ['strip', 'carousel'], true)) {
            if ($strict) {
                throw new InvalidArgumentException('Trust block layout must be strip or carousel.');
            }
            $layout = 'strip';
        }

        $heading = is_string($row['heading'] ?? null) ? trim($row['heading']) : '';
        if ($strict && ($heading === '' || mb_strlen($heading) > 60)) {
            throw new InvalidArgumentException('Trust block heading is required and may not exceed 60 characters.');
        }

        $reviewsLabel = is_string($row['reviews_label'] ?? null) ? trim($row['reviews_label']) : '';
        if ($strict && ($reviewsLabel === '' || mb_strlen($reviewsLabel) > 30)) {
            throw new InvalidArgumentException('Trust block reviews label is required and may not exceed 30 characters.');
        }

        $minimum = filter_var($row['min_reviews'] ?? 3, FILTER_VALIDATE_INT);
        if ($strict && ($minimum === false || $minimum < 1 || $minimum > 1000)) {
            throw new InvalidArgumentException('Trust block minimum reviews must be between 1 and 1000.');
        }

        return [
            'type' => 'trust_strip',
            'sources' => $sources,
            'layout' => $layout,
            'heading' => mb_substr($heading !== '' ? $heading : 'What customers say', 0, 60),
            'reviews_label' => mb_substr($reviewsLabel !== '' ? $reviewsLabel : 'reviews', 0, 30),
            'min_reviews' => max(1, min(1000, $minimum === false ? 3 : $minimum)),
            'external' => self::parseExternal($row['external'] ?? null, $strict),
        ];
    }

    /**
     * @return array{label: string, url: string, rating: float, count: int}|null
     */
    private static function parseExternal(mixed $external, bool $strict): ?array
    {
        if ($external === null || $external === [] || (is_array($external) && collect($external)->every(
            fn (mixed $value): bool => $value === null || $value === '',
        ))) {
            return null;
        }

        $valid = is_array($external)
            && is_string($external['label'] ?? null)
            && trim($external['label']) !== ''
            && mb_strlen(trim($external['label'])) <= 30
            && is_string($external['url'] ?? null)
            && filter_var($external['url'], FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($external['url'], PHP_URL_SCHEME), ['http', 'https'], true)
            && is_numeric($external['rating'] ?? null)
            && (float) $external['rating'] >= 0
            && (float) $external['rating'] <= 5
            && round((float) $external['rating'], 1) === (float) $external['rating']
            && filter_var($external['count'] ?? null, FILTER_VALIDATE_INT) !== false
            && (int) $external['count'] >= 0;

        if (! $valid) {
            if ($strict) {
                throw new InvalidArgumentException('Trust block external badge is invalid.');
            }

            return null;
        }

        return [
            'label' => trim($external['label']),
            'url' => $external['url'],
            'rating' => round((float) $external['rating'], 1),
            'count' => (int) $external['count'],
        ];
    }
}
