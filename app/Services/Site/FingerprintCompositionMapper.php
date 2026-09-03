<?php

namespace App\Services\Site;

class FingerprintCompositionMapper
{
    /** Deterministic extended-vocabulary mapping (MVP-gated). */
    private const EXTENDED_MAP = [
        'stats-row' => 'trust',
        'portfolio' => 'services',
        'logo-strip' => 'trust',
        'other' => 'about-text',
        'google_reviews' => 'reviews',
    ];

    private const CORE_TYPES = [
        'hero', 'services', 'testimonials', 'about-text', 'cta', 'contact_form', 'faqs',
        'reviews_summary', 'reviews', 'reviews_badge',
    ];

    /**
     * Walk fingerprint.sections → normalised composition list with count/copy hints.
     */
    public function map(array $fingerprint): array
    {
        $sections = $fingerprint['sections'] ?? [];

        return array_map(function (array $s) {
            $type = $s['type'] ?? 'other';
            if (! in_array($type, self::CORE_TYPES, true)) {
                $type = self::EXTENDED_MAP[$type] ?? 'about-text';
            }

            return [
                'type' => $type,
                'variant_hint' => $s['variant'] ?? '',
                'items_hint' => $s['items_count'] ?? 0,
                'headline_hint' => $s['headline_preview'] ?? '',
            ];
        }, $sections);
    }
}
