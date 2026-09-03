<?php

namespace App\Services\Shop;

final class WeightTiers
{
    public const MAX_CART_GRAMS = 10_000_000;

    /**
     * JSON Schema for a `weight_tiers` rate's `tiers` array.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'array',
            'minItems' => 1,
            'items' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['up_to_grams', 'amount_cents'],
                'properties' => [
                    'up_to_grams' => ['type' => ['integer', 'null'], 'minimum' => 0],
                    'amount_cents' => ['type' => 'integer', 'minimum' => 0],
                ],
            ],
        ];
    }

    /**
     * @param  list<array{up_to_grams?: int|string|null, amount_cents?: int|string}>  $tiers
     */
    public static function error(array $tiers): ?string
    {
        $tiers = array_values($tiers);
        if ($tiers === []) {
            return 'Add at least one weight tier, ending with a catch-all.';
        }

        $catchAllIndexes = [];
        $previous = null;

        foreach ($tiers as $index => $tier) {
            $upTo = is_array($tier) ? ($tier['up_to_grams'] ?? null) : null;
            $amount = is_array($tier) ? ($tier['amount_cents'] ?? null) : null;

            if (! is_int($amount) && ! (is_string($amount) && is_numeric($amount))) {
                return 'Each tier needs an amount of at least 0.';
            }
            if ((int) $amount < 0) {
                return 'Each tier needs an amount of at least 0.';
            }

            if ($upTo === '' || $upTo === null) {
                $catchAllIndexes[] = $index;

                continue;
            }

            if (! is_int($upTo) && ! (is_string($upTo) && is_numeric($upTo))) {
                return 'Weight limits must be whole grams, or blank for the catch-all.';
            }

            $upTo = (int) $upTo;
            if ($upTo < 0) {
                return 'Weight limits must be at least 0 grams.';
            }
            if ($previous !== null && $upTo <= $previous) {
                return 'Weight limits must increase with each tier.';
            }
            $previous = $upTo;
        }

        if ($catchAllIndexes === []) {
            return 'The last weight tier must be a catch-all (blank up-to).';
        }

        if (count($catchAllIndexes) !== 1 || $catchAllIndexes[0] !== count($tiers) - 1) {
            return 'Use exactly one catch-all as the last weight tier.';
        }

        return null;
    }
}
