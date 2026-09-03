<?php

namespace App\Support\Site;

final class HeroFocus
{
    /** @var list<string> */
    public const STORED = ['auto', 'left', 'centre', 'right', 'fill'];

    /** @var list<string> */
    public const RESOLVED = ['left', 'centre', 'right', 'fill'];

    /**
     * Allowlist for stored values. Invalid or empty → null (treat as auto).
     */
    public static function sanitize(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return in_array($value, self::STORED, true) ? $value : null;
    }

    /**
     * Resolve a concrete generation focus. Precedence: override > site > auto.
     * Auto (null column, "auto", or invalid) derives fill for panel/boxed
     * variants, otherwise the text_zone column (left/centre/right).
     */
    public static function resolve(?string $override, ?string $siteDefault, ?string $variant, ?string $textZone): string
    {
        $fromOverride = self::sanitize($override);
        if ($fromOverride !== null && $fromOverride !== 'auto') {
            return $fromOverride;
        }

        $fromSite = self::sanitize($siteDefault);
        if ($fromSite !== null && $fromSite !== 'auto') {
            return $fromSite;
        }

        if (in_array($variant, ['panel-left', 'boxed-left'], true)) {
            return 'fill';
        }

        $column = 'left';
        if (is_string($textZone) && $textZone !== '') {
            $parts = explode('-', $textZone);
            $column = $parts[1] ?? $parts[0] ?? 'left';
        }

        return match ($column) {
            'center' => 'centre',
            'right' => 'right',
            default => 'left',
        };
    }

    /**
     * Quantitative composition-region test, mirrored from the clearance
     * rule. Fill never fails (the check is N/A).
     *
     * left:  x < 0.40 fails
     * right: x > 0.60 fails
     * centre: 0.33 ≤ x ≤ 0.66 fails
     * fill: never
     */
    public static function compositionCoordinateFails(?string $heroFocus, mixed $x): bool
    {
        $focus = strtolower((string) ($heroFocus ?: 'left'));
        if ($focus === 'fill' || ! is_numeric($x)) {
            return false;
        }

        $x = (float) $x;

        return match ($focus) {
            'right' => $x > 0.60,
            'centre', 'center' => $x >= 0.33 && $x <= 0.66,
            default => $x < 0.40,
        };
    }
}
