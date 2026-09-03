<?php

namespace App\Services\Shop;

use InvalidArgumentException;

/**
 * Parse a decimal-pound string into integer pence. Digit concatenation of a
 * validated 0–2 dp decimal — never float, never bcmul-of-float.
 */
final class MoneyPence
{
    public const MIN = 1;

    public const MAX = 10_000_000;

    public static function fromDecimalPounds(string $pounds): int
    {
        $pounds = trim($pounds);
        if (preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/', $pounds, $matches) !== 1) {
            throw new InvalidArgumentException('bad_price');
        }

        $fraction = str_pad($matches[2] ?? '00', 2, '0');
        $pence = (int) ($matches[1].$fraction);

        if ($pence < self::MIN || $pence > self::MAX) {
            throw new InvalidArgumentException('bad_price');
        }

        return $pence;
    }
}
