<?php

namespace App\Services\Shop\Fulfilment;

use App\Support\Postcode\PostcodeNormaliser;

final class ZoneMatcher
{
    /**
     * Longest matching prefix wins across zones. A prefix hits when it
     * is the start of the outward code or of the full normalised postcode.
     *
     * @param  list<array{name?: string, prefixes?: list<string>, fee_cents?: int, free_over_cents?: int|null, lead_time?: string, min_order_cents?: int|null}>  $zones
     * @return array{name: string, prefixes: list<string>, fee_cents: int, free_over_cents: int|null, lead_time: string, min_order_cents: int|null, matched_prefix: string}|null
     */
    public function match(string $normalisedPostcode, array $zones, PostcodeNormaliser $normaliser): ?array
    {
        if ($normalisedPostcode === '') {
            return null;
        }

        $outward = $normaliser->outwardCode($normalisedPostcode);
        $best = null;
        $bestLength = -1;

        foreach ($zones as $zone) {
            if (! is_array($zone)) {
                continue;
            }

            $prefixes = $zone['prefixes'] ?? [];
            if (! is_array($prefixes)) {
                continue;
            }

            foreach ($prefixes as $prefix) {
                if (! is_string($prefix) || $prefix === '') {
                    continue;
                }

                $prefix = $normaliser->normalise($prefix);
                $length = strlen($prefix);
                if ($length === 0 || $length < $bestLength) {
                    continue;
                }

                $hits = str_starts_with($outward, $prefix)
                    || str_starts_with($normalisedPostcode, $prefix);

                if (! $hits) {
                    continue;
                }

                $bestLength = $length;
                $best = [
                    'name' => (string) ($zone['name'] ?? ''),
                    'prefixes' => array_values(array_map(
                        fn (mixed $item): string => is_string($item) ? $normaliser->normalise($item) : '',
                        $prefixes,
                    )),
                    'fee_cents' => (int) ($zone['fee_cents'] ?? 0),
                    'free_over_cents' => array_key_exists('free_over_cents', $zone) && $zone['free_over_cents'] !== null
                        ? (int) $zone['free_over_cents']
                        : null,
                    'lead_time' => (string) ($zone['lead_time'] ?? ''),
                    'min_order_cents' => array_key_exists('min_order_cents', $zone) && $zone['min_order_cents'] !== null
                        ? (int) $zone['min_order_cents']
                        : null,
                    'matched_prefix' => $prefix,
                ];
            }
        }

        return $best;
    }
}
