<?php

namespace App\Services\Site;

class LayoutFingerprintValidator
{
    private const TYPES = [
        'hero', 'services', 'testimonials', 'about-text', 'cta', 'contact_form',
        'faqs', 'stats-row', 'portfolio', 'logo-strip', 'other',
        'reviews_summary', 'reviews', 'reviews_badge',
    ];

    private const WEIGHTS = ['light', 'regular', 'semibold', 'bold', 'extrabold'];

    private const DENSITIES = ['sparse', 'balanced', 'dense'];

    public function validate(array $data): array
    {
        $sectionsRaw = $data['sections'] ?? [];
        $rawCount = count($sectionsRaw);
        $sections = array_slice(array_map([$this, 'normaliseSection'], $sectionsRaw), 0, 12);

        return [
            'sections' => $sections,
            'palette' => $this->normalisePalette($data['palette'] ?? []),
            'typography' => $this->normaliseTypography($data['typography'] ?? []),
            'overall_density' => in_array($data['overall_density'] ?? '', self::DENSITIES, true)
                ? $data['overall_density']
                : 'balanced',
            'meta' => [
                'sections_count_raw' => (int) ($data['meta']['sections_count_raw'] ?? $rawCount),
                'confidence' => max(0.0, min(1.0, (float) ($data['meta']['confidence'] ?? 0.0))),
            ],
        ];
    }

    private function normaliseSection(array $s): array
    {
        return [
            'type' => in_array($s['type'] ?? '', self::TYPES, true) ? $s['type'] : 'other',
            'variant' => mb_substr((string) ($s['variant'] ?? ''), 0, 40),
            'items_count' => max(0, min(50, (int) ($s['items_count'] ?? 0))),
            'has_image' => (bool) ($s['has_image'] ?? false),
            'headline_preview' => mb_substr((string) ($s['headline_preview'] ?? ''), 0, 80),
        ];
    }

    private function normalisePalette(array $p): array
    {
        return [
            'primary_hex_guess' => $this->validateHex($p['primary_hex_guess'] ?? null),
            'accent_hex_guess' => $this->validateHex($p['accent_hex_guess'] ?? null),
            'dark_sections_used' => (bool) ($p['dark_sections_used'] ?? false),
        ];
    }

    private function normaliseTypography(array $t): array
    {
        return [
            'heading_weight' => in_array($t['heading_weight'] ?? '', self::WEIGHTS, true)
                ? $t['heading_weight']
                : 'regular',
            'uses_cursive_or_display_font' => (bool) ($t['uses_cursive_or_display_font'] ?? false),
        ];
    }

    private function validateHex(?string $v): ?string
    {
        return (is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v)) ? $v : null;
    }
}
