<?php

namespace App\Support\Shop;

/**
 * Human labels for the review-note codes an import can leave on a product.
 * Codes are the contract (they travel in the tool receipt); labels are for people.
 */
final class ProductReviewNotes
{
    private const LABELS = [
        'missing_description' => 'No description',
        'duplicate_category' => 'Duplicate category skipped',
        'missing_variant_label' => 'Unlabelled variant',
        'price_missing' => 'No price — set a price before publishing',
        'matches_existing' => 'Matches an existing product',
    ];

    public static function label(string $code): string
    {
        return self::LABELS[$code] ?? str_replace('_', ' ', $code);
    }

    /**
     * @param  mixed  $notes
     * @return list<string>
     */
    public static function normalize(mixed $notes): array
    {
        if (! is_array($notes)) {
            return [];
        }

        return array_values(array_unique(array_filter($notes, fn ($note) => is_string($note) && $note !== '')));
    }

    public static function joined(mixed $notes): string
    {
        return implode('; ', array_map(self::label(...), self::normalize($notes)));
    }
}
