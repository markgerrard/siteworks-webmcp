<?php

namespace App\Support\Shop;

use InvalidArgumentException;

final class AutoTagConfig
{
    public const RULES = ['best-seller', 'new', 'low-stock', 'made-to-order'];

    /**
     * @return array<string, array{enabled: bool, label: string, show_as_badge: bool, tone: string, params: array<string, int>}>
     */
    public static function defaults(): array
    {
        return [
            'best-seller' => [
                'enabled' => false,
                'label' => 'Best seller',
                'show_as_badge' => true,
                'tone' => 'accent',
                'params' => ['n' => 8, 'days' => 30],
            ],
            'new' => [
                'enabled' => false,
                'label' => 'New',
                'show_as_badge' => true,
                'tone' => 'success',
                'params' => ['days' => 14],
            ],
            'low-stock' => [
                'enabled' => false,
                'label' => 'Low stock',
                'show_as_badge' => true,
                'tone' => 'warning',
                'params' => ['threshold' => 5],
            ],
            'made-to-order' => [
                'enabled' => false,
                'label' => 'Made to order',
                'show_as_badge' => true,
                'tone' => 'neutral',
                'params' => [],
            ],
        ];
    }

    /**
     * @return array<string, array{enabled: bool, label: string, show_as_badge: bool, tone: string, params: array<string, int>}>
     */
    public static function parse(mixed $raw): array
    {
        if ($raw === null || $raw === []) {
            return self::defaults();
        }

        if (! is_array($raw)) {
            throw new InvalidArgumentException('Auto tags must be an object.');
        }

        foreach (array_keys($raw) as $key) {
            if (! is_string($key) || ! in_array($key, self::RULES, true)) {
                throw new InvalidArgumentException("unknown auto-tag rule: {$key}.");
            }
        }

        return self::merge($raw, strict: true);
    }

    /**
     * @return array<string, array{enabled: bool, label: string, show_as_badge: bool, tone: string, params: array<string, int>}>
     */
    public static function normalize(mixed $raw): array
    {
        if (! is_array($raw)) {
            return self::defaults();
        }

        $filtered = [];
        foreach ($raw as $key => $value) {
            if (is_string($key) && in_array($key, self::RULES, true) && is_array($value)) {
                $filtered[$key] = $value;
            }
        }

        return self::merge($filtered, strict: false);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, array{enabled: bool, label: string, show_as_badge: bool, tone: string, params: array<string, int>}>
     */
    private static function merge(array $raw, bool $strict): array
    {
        $out = self::defaults();

        foreach (self::RULES as $rule) {
            if (! isset($raw[$rule]) || ! is_array($raw[$rule])) {
                continue;
            }

            $row = $raw[$rule];
            $label = $row['label'] ?? $out[$rule]['label'];
            if (! is_string($label) || trim($label) === '') {
                if ($strict) {
                    throw new InvalidArgumentException("Auto-tag {$rule} label is required.");
                }
                $label = $out[$rule]['label'];
            }

            $tone = $row['tone'] ?? $out[$rule]['tone'];
            if (! is_string($tone) || ! in_array($tone, ProductTagVocabulary::TONES, true)) {
                if ($strict) {
                    throw new InvalidArgumentException("Auto-tag {$rule} tone is invalid.");
                }
                $tone = $out[$rule]['tone'];
            }

            $out[$rule]['enabled'] = (bool) ($row['enabled'] ?? false);
            $out[$rule]['label'] = mb_substr(trim($label), 0, 40);
            $out[$rule]['show_as_badge'] = array_key_exists('show_as_badge', $row)
                ? (bool) $row['show_as_badge']
                : $out[$rule]['show_as_badge'];
            $out[$rule]['tone'] = $tone;
            $out[$rule]['params'] = self::params($rule, is_array($row['params'] ?? null) ? $row['params'] : []);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, int>
     */
    private static function params(string $rule, array $params): array
    {
        return match ($rule) {
            'best-seller' => [
                'n' => max(1, min(40, (int) ($params['n'] ?? 8))),
                'days' => max(1, min(365, (int) ($params['days'] ?? 30))),
            ],
            'new' => [
                'days' => max(1, min(365, (int) ($params['days'] ?? 14))),
            ],
            'low-stock' => [
                'threshold' => max(0, min(100000, (int) ($params['threshold'] ?? 5))),
            ],
            default => [],
        };
    }
}
