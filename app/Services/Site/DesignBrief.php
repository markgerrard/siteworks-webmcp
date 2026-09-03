<?php

namespace App\Services\Site;

use App\Models\Site;

/**
 * @phpstan-type DesignBriefPalette array{
 *     primary: string,
 *     accent: string,
 *     tertiary: string,
 *     surface: string,
 *     surface_alt: string,
 *     border: string,
 *     text: string,
 *     text_muted: string
 * }
 * @phpstan-type DesignBriefData array{
 *     mood: string,
 *     display_font: string,
 *     body_font: string,
 *     heading_scale: string,
 *     spacing_density: string,
 *     corner_style: string,
 *     palette: DesignBriefPalette,
 *     rationale?: string|null
 * }
 */
class DesignBrief
{
    public const MOODS = [
        'warm-traditional',
        'bold-modern',
        'refined-minimal',
        'robust-industrial',
        'friendly-local',
    ];

    public const DISPLAY_FONTS = [
        'fraunces',
        'dm-serif-display',
        'playfair-display',
        'space-grotesk',
        'bricolage-grotesque',
        'archivo-black',
    ];

    public const SERIF_DISPLAY_FONTS = [
        'fraunces',
        'dm-serif-display',
        'playfair-display',
    ];

    public const BODY_FONTS = [
        'inter',
        'manrope',
        'figtree',
        'source-sans-3',
        'nunito-sans',
    ];

    public const HEADING_SCALES = [
        'tight',
        'balanced',
        'relaxed',
    ];

    public const SPACING_DENSITIES = [
        'compact',
        'balanced',
        'generous',
    ];

    public const CORNER_STYLES = [
        'sharp',
        'soft',
        'rounded',
    ];

    private const REQUIRED_PALETTE_KEYS = [
        'primary',
        'accent',
        'tertiary',
        'surface',
        'surface_alt',
        'border',
        'text',
        'text_muted',
    ];

    public function __construct(private readonly array $data) {}

    public static function fromArray(array $data): ?self
    {
        $brief = new self($data);

        return $brief->isValid() ? $brief : null;
    }

    public static function fromSite(Site $site): ?self
    {
        return is_array($site->design_brief) ? self::fromArray($site->design_brief) : null;
    }

    public function isValid(): bool
    {
        return $this->normalisedData() !== null;
    }

    public function mood(): string
    {
        /** @var DesignBriefData $data */
        $data = $this->requireValidData();

        return $data['mood'];
    }

    public function displayFont(): string
    {
        /** @var DesignBriefData $data */
        $data = $this->requireValidData();

        return $data['display_font'];
    }

    public function bodyFont(): string
    {
        /** @var DesignBriefData $data */
        $data = $this->requireValidData();

        return $data['body_font'];
    }

    public function headingScale(): string
    {
        /** @var DesignBriefData $data */
        $data = $this->requireValidData();

        return $data['heading_scale'];
    }

    public function spacingDensity(): string
    {
        /** @var DesignBriefData $data */
        $data = $this->requireValidData();

        return $data['spacing_density'];
    }

    public function cornerStyle(): string
    {
        /** @var DesignBriefData $data */
        $data = $this->requireValidData();

        return $data['corner_style'];
    }

    /**
     * @return DesignBriefPalette
     */
    public function palette(): array
    {
        /** @var DesignBriefData $data */
        $data = $this->requireValidData();

        return $data['palette'];
    }

    public function rationale(): ?string
    {
        /** @var DesignBriefData $data */
        $data = $this->requireValidData();

        return $data['rationale'] ?? null;
    }

    /**
     * @return DesignBriefData
     */
    public function toArray(): array
    {
        /** @var DesignBriefData $data */
        $data = $this->requireValidData();

        return $data;
    }

    public function wcagContrastRatio(string $hex1, string $hex2): float
    {
        $luminanceA = $this->relativeLuminance($hex1);
        $luminanceB = $this->relativeLuminance($hex2);

        $lighter = max($luminanceA, $luminanceB);
        $darker = min($luminanceA, $luminanceB);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * @return DesignBriefData|null
     */
    private function normalisedData(): ?array
    {
        $mood = $this->normaliseEnum($this->data['mood'] ?? null, self::MOODS);
        $displayFont = $this->normaliseEnum($this->data['display_font'] ?? null, self::DISPLAY_FONTS);
        $bodyFont = $this->normaliseEnum($this->data['body_font'] ?? null, self::BODY_FONTS);
        $headingScale = $this->normaliseEnum($this->data['heading_scale'] ?? null, self::HEADING_SCALES);
        $spacingDensity = $this->normaliseEnum($this->data['spacing_density'] ?? null, self::SPACING_DENSITIES);
        $cornerStyle = $this->normaliseEnum($this->data['corner_style'] ?? null, self::CORNER_STYLES);

        if (! $mood || ! $displayFont || ! $bodyFont || ! $headingScale || ! $spacingDensity || ! $cornerStyle) {
            return null;
        }

        if (in_array($displayFont, self::SERIF_DISPLAY_FONTS, true)
            && ! in_array($mood, ['warm-traditional', 'refined-minimal'], true)) {
            return null;
        }

        $palette = $this->normalisePalette($this->data['palette'] ?? null);
        if ($palette === null) {
            return null;
        }

        if ($this->wcagContrastRatio($palette['text'], $palette['surface']) < 4.5) {
            return null;
        }

        if ($this->wcagContrastRatio($palette['text_muted'], $palette['surface']) < 3.0) {
            return null;
        }

        if ($this->wcagContrastRatio($palette['primary'], $palette['surface']) < 3.0) {
            return null;
        }

        if ($this->wcagContrastRatio($palette['accent'], $palette['surface']) < 3.0) {
            return null;
        }

        $rationale = $this->data['rationale'] ?? null;
        if ($rationale !== null && ! is_string($rationale)) {
            return null;
        }

        return [
            'mood' => $mood,
            'display_font' => $displayFont,
            'body_font' => $bodyFont,
            'heading_scale' => $headingScale,
            'spacing_density' => $spacingDensity,
            'corner_style' => $cornerStyle,
            'palette' => $palette,
            'rationale' => is_string($rationale) ? trim($rationale) : null,
        ];
    }

    private function normaliseEnum(mixed $value, array $allowed): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalised = strtolower(trim($value));

        return in_array($normalised, $allowed, true) ? $normalised : null;
    }

    /**
     * @return DesignBriefPalette|null
     */
    private function normalisePalette(mixed $palette): ?array
    {
        if (! is_array($palette)) {
            return null;
        }

        $normalised = [];

        foreach (self::REQUIRED_PALETTE_KEYS as $key) {
            $value = $palette[$key] ?? null;
            if (! is_string($value)) {
                return null;
            }

            $hex = $this->normaliseHex($value);
            if ($hex === null) {
                return null;
            }

            $normalised[$key] = $hex;
        }

        /** @var DesignBriefPalette $normalised */
        return $normalised;
    }

    private function normaliseHex(string $value): ?string
    {
        $themeResolver = new ThemeResolver();
        $hex = $themeResolver->normaliseHex($value);

        if ($hex === null) {
            return null;
        }

        // Reuse ThemeResolver's parsing path so DesignBrief and theme
        // extraction share the same colour-normalisation assumptions.
        $themeResolver->hexToHsl($hex);

        return $hex;
    }

    private function relativeLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');

        $channels = [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];

        $linear = array_map(function (float $channel): float {
            if ($channel <= 0.03928) {
                return $channel / 12.92;
            }

            return (($channel + 0.055) / 1.055) ** 2.4;
        }, $channels);

        return (0.2126 * $linear[0]) + (0.7152 * $linear[1]) + (0.0722 * $linear[2]);
    }

    /**
     * @return DesignBriefData
     */
    private function requireValidData(): array
    {
        $data = $this->normalisedData();

        if ($data === null) {
            throw new \LogicException('Design brief data is invalid.');
        }

        return $data;
    }
}
