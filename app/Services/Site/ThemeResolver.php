<?php

namespace App\Services\Site;

use App\Enums\Archetype;
use App\Models\Site;
use App\Support\Textures\TextureLibrary;

/**
 * Resolves the brand theme array for a site.
 *
 * Shared between PreviewRenderer (legacy) and PageRenderer (versioned).
 *
 * Priority (highest → lowest, independently for each paint token):
 *   1. `composition.theme.token_overrides` — map keyed by the FINAL
 *      emitted CSS variable names without the `--` prefix
 *      (`color-band`, `radius-card`, …). Applied LAST onto the
 *      derived token set after brief resolution, legacy `*_override`
 *      knobs, band/scheme derivation, AND invert. A present key is a
 *      post-invert literal: what you set is what renders. Beats a
 *      legacy `*_override` that names the same final variable.
 *   2. Explicit legacy `*_override` values in composition.theme
 *      (13 per-token knobs: 8 colours + 2 fonts + 3 layout enums)
 *      plus `invert_mode_override` and `brand_section_scheme_override`.
 *      Invert rewrites the neutral palette after those knobs apply.
 *   3. The valid design brief stored on the site.
 *   4. Extracted palettes: profile.visual, fingerprint, scrape/profile.
 *   5. The site's legacy THEMES preset, used only when no higher layer
 *      supplies brand colours. `composition.theme.key` is not authoritative.
 *
 * A valid brief supplies every palette, font, and layout token, so it does
 * not inherit from the legacy preset/extraction chain. Legacy composition
 * overrides replace only the individual tokens explicitly present.
 * `token_overrides` is a later, more specific layer and is not one of the
 * 13 `*_override` keys; an empty/absent map is a no-op (byte-identical).
 */
class ThemeResolver
{
    private const DEFAULT_THEME = 'trades-bold';

    public const THEMES = [
        'trades-bold' => [
            'primary_color' => '#1e40af',
            'accent_color'  => '#f59e0b',
        ],
        'professional-clean' => [
            'primary_color' => '#1f2937',
            'accent_color'  => '#6366f1',
        ],
        'local-friendly' => [
            'primary_color' => '#15803d',
            'accent_color'  => '#ea580c',
        ],
    ];

    /**
     * Archetypes that opt into a light-tinted band rather than the default dark
     * band. Light-tinted suits airy/soft aesthetics (florists, wellness, boutique
     * retail, premium specialists) where a cold-dark band block clashes with the
     * overall palette mood.
     *
     * All other archetypes fall through to 'dark' (current behaviour — no change
     * on any existing Midlands-style site). Extend this list as new archetypes
     * are added to the Archetype enum.
     */
    // PremiumSpecialist was previously light-tinted (airy/boutique feel),
    // but per archetype-personality review that produced bland "doctor's
    // waiting room" renders when the palette was dark/sophisticated. The
    // "refined-minimal" mood of a premium specialist pairs better with a
    // dark authoritative band — the brand asserts expertise, not softness.
    // Moved to the default 'dark' bucket. RetailVenue stays light-tinted.
    private const LIGHT_TINTED_BAND_ARCHETYPES = [
        Archetype::RetailVenue,
    ];

    private const DEFAULT_TOKEN_COLOURS = [
        'surface_color' => '#ffffff',
        'surface_alt_color' => '#f5f5f5',
        'border_color' => '#e5e5e5',
        'text_color' => '#111111',
        'text_muted_color' => '#6b7280',
    ];

    /**
     * @var array<string, array{name: string, slug: string, fallback: string, weights: string}>
     */
    public const FONTS = [
        'inter' => [
            'name' => 'Inter',
            'slug' => 'inter',
            'fallback' => 'system-ui, sans-serif',
            'weights' => '400,500,600,700,800',
        ],
        'manrope' => [
            'name' => 'Manrope',
            'slug' => 'manrope',
            'fallback' => 'system-ui, sans-serif',
            'weights' => '400,500,600,700,800',
        ],
        'figtree' => [
            'name' => 'Figtree',
            'slug' => 'figtree',
            'fallback' => 'system-ui, sans-serif',
            'weights' => '400,500,600,700,800',
        ],
        'source-sans-3' => [
            'name' => 'Source Sans 3',
            'slug' => 'source-sans-3',
            'fallback' => 'system-ui, sans-serif',
            'weights' => '400,500,600,700',
        ],
        'nunito-sans' => [
            'name' => 'Nunito Sans',
            'slug' => 'nunito-sans',
            'fallback' => 'system-ui, sans-serif',
            'weights' => '400,500,600,700,800',
        ],
        'fraunces' => [
            'name' => 'Fraunces',
            'slug' => 'fraunces',
            'fallback' => 'Georgia, serif',
            'weights' => '600,700,800',
        ],
        'dm-serif-display' => [
            'name' => 'DM Serif Display',
            'slug' => 'dm-serif-display',
            'fallback' => 'Georgia, serif',
            'weights' => '400',
        ],
        'playfair-display' => [
            'name' => 'Playfair Display',
            'slug' => 'playfair-display',
            'fallback' => 'Georgia, serif',
            'weights' => '600,700,800',
        ],
        'space-grotesk' => [
            'name' => 'Space Grotesk',
            'slug' => 'space-grotesk',
            'fallback' => 'system-ui, sans-serif',
            'weights' => '500,600,700',
        ],
        'bricolage-grotesque' => [
            'name' => 'Bricolage Grotesque',
            'slug' => 'bricolage-grotesque',
            'fallback' => 'system-ui, sans-serif',
            'weights' => '500,600,700,800',
        ],
        'archivo-black' => [
            'name' => 'Archivo Black',
            'slug' => 'archivo-black',
            'fallback' => 'system-ui, sans-serif',
            'weights' => '400',
        ],
    ];

    private const RADIUS_CARD_MAP = [
        'sharp' => '4px',
        'soft' => '12px',
        'rounded' => '24px',
    ];

    private const RADIUS_BUTTON_MAP = [
        'sharp' => '4px',
        'soft' => '8px',
        'rounded' => '9999px',
    ];

    private const SECTION_SPACING_MAP = [
        'compact' => '4rem',
        'balanced' => '6rem',
        'generous' => '8rem',
    ];

    /**
     * Independent shell-width knob. `auto` (default) keeps today's
     * spacing_density-derived value so existing sites stay byte-identical.
     *
     * @var list<string>
     */
    public const CONTAINER_WIDTHS = [
        'auto',
        'standard',
        'wide',
        'grand',
    ];

    /**
     * Opt-in "bigger everything" preset. `standard` (default) is a no-op
     * so existing sites stay byte-identical.
     *
     * @var list<string>
     */
    public const DISPLAY_SCALES = [
        'standard',
        'grand',
    ];

    private const CONTAINER_WIDTH_TIER_MAP = [
        'standard' => '1280px',
        'wide' => '1440px',
        'grand' => '1680px',
    ];

    private const CONTAINER_WIDTH_MAP = [
        // spacing_density is a VERTICAL rhythm dial — it controls section
        // padding, not horizontal shell width. Conflating the two double-
        // compressed compact sites: the "bold / emergency_trade" archetype
        // chose spacing_density=compact, which also narrowed the shell from
        // 1280 → 1200 and made service grids feel cramped next to a
        // balanced-tier competitor's 1280. Flatten compact+balanced to the
        // same 1280 shell; preserve the relative generous expansion so
        // generous sites still breathe visibly wider.
        //
        // Used only when container_width is `auto` (the default). Explicit
        // standard/wide/grand tiers win over this map.
        'compact' => '1280px',
        'balanced' => '1280px',
        'generous' => '1360px',
    ];

    private const HEADING_LETTER_SPACING_MAP = [
        'tight' => '-0.02em',
        'balanced' => '-0.01em',
        'relaxed' => '0',
    ];

    // Lucide default stroke-width is 2. Bolder archetypes (emergency
    // trade, premium, industrial) need heavier strokes to balance against
    // heavy display typography — thin-outline icons read "lost" next to
    // Archivo Black / Bricolage Grotesque headings. Tied to corner_style
    // so the whole "sharpness" axis scales together:
    //   sharp    → 2.5  (bold, emergency_trade, premium_specialist)
    //   soft     → 2    (default, friendly)
    //   rounded  → 1.75 (airy, retail, boutique)
    private const ICON_STROKE_WIDTH_MAP = [
        'sharp' => '2.5',
        'soft' => '2',
        'rounded' => '1.75',
    ];

    /**
     * CSS custom-property names (no `--` prefix) the public renderer
     * actually emits as colours, mapped onto renderTokens() keys.
     * Operator token_overrides / section style_overrides may name only
     * these (plus the radius family below).
     *
     * @var array<string, string>
     */
    public const EMITTED_COLOR_OVERRIDE_TOKENS = [
        'color-primary' => 'primary',
        'color-primary-text' => 'primary_text',
        'color-primary-text-on-alt' => 'primary_text_on_alt',
        'color-accent' => 'accent',
        'color-accent-text' => 'accent_text',
        'color-accent-text-on-alt' => 'accent_text_on_alt',
        'color-tertiary' => 'tertiary',
        'color-surface' => 'surface',
        'color-surface-alt' => 'surface_alt',
        'color-border' => 'border',
        'color-text' => 'text',
        'color-text-on-alt' => 'text_on_alt',
        'color-text-muted' => 'text_muted',
        'color-text-muted-on-alt' => 'text_muted_on_alt',
        'color-band' => 'band',
        'color-text-on-band' => 'text_on_band',
        'color-band-overlay' => 'band_overlay',
        'color-text-on-primary' => 'text_on_primary',
        'color-text-on-accent' => 'text_on_accent',
        'color-surface-contrast' => 'surface_contrast',
        'color-text-on-contrast' => 'text_on_contrast',
        'color-text-muted-on-contrast' => 'text_muted_on_contrast',
        'color-accent-text-on-contrast' => 'accent_text_on_contrast',
        'color-brand-section-surface' => 'brand_section_surface',
        'color-brand-section-ink' => 'brand_section_ink',
        'color-brand-section-muted-ink' => 'brand_section_muted_ink',
        'color-brand-section-accent-ink' => 'brand_section_accent_ink',
    ];

    /**
     * Radius-family CSS custom-property names the renderer emits.
     *
     * @var array<string, string>
     */
    public const EMITTED_RADIUS_OVERRIDE_TOKENS = [
        'radius-card' => 'radius_card',
        'radius-button' => 'radius_button',
    ];

    /**
     * Per-section texture knobs on set_section_style only. Not emitted as
     * colour CSS variables; TextureLayer reads them from style_overrides.
     *
     * @var array<string, string>
     */
    public const EMITTED_TEXTURE_OVERRIDE_TOKENS = [
        'texture' => 'texture',
        'texture_opacity' => 'texture_opacity',
        'texture_size' => 'texture_size',
        'texture_image_path' => 'texture_image_path',
        'texture_image_mode' => 'texture_image_mode',
    ];

    private const RADIUS_OVERRIDE_PATTERN = '/^(?:0|\d+(?:\.\d+)?(?:px|rem|em|%))$/';

    /**
     * Obvious surface-vs-text pairings used for warn-only contrast lint
     * when an operator override lands. Never blocking.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private const TOKEN_OVERRIDE_CONTRAST_PAIRS = [
        ['color-text', 'color-surface', 'text', 'surface'],
        ['color-text-muted', 'color-surface', 'text_muted', 'surface'],
        ['color-text-on-alt', 'color-surface-alt', 'text_on_alt', 'surface_alt'],
        ['color-text-on-band', 'color-band', 'text_on_band', 'band'],
        ['color-text-on-primary', 'color-primary', 'text_on_primary', 'primary'],
        ['color-text-on-accent', 'color-accent', 'text_on_accent', 'accent'],
        ['color-text-on-contrast', 'color-surface-contrast', 'text_on_contrast', 'surface_contrast'],
        ['color-text-muted-on-contrast', 'color-surface-contrast', 'text_muted_on_contrast', 'surface_contrast'],
        ['color-brand-section-ink', 'color-brand-section-surface', 'brand_section_ink', 'brand_section_surface'],
        ['color-brand-section-muted-ink', 'color-brand-section-surface', 'brand_section_muted_ink', 'brand_section_surface'],
    ];

    /**
     * Resolve the theme array for a site.
     *
     * @param  array<string, mixed>  $profile  Business profile data.
     * @param  array<string, mixed>|null  $compositionTheme  composition.theme
     *     from the published SiteVersion. Valid per-token overrides win only
     *     for their corresponding tokens; its legacy preset key is ignored.
     */
    public function resolve(
        Site $site,
        array $profile,
        ?array $compositionTheme = null,
        ?array $designBrief = null,
    ): array
    {
        $brief = $this->resolveDesignBrief($site, $designBrief);

        if ($brief !== null) {
            $palette = $brief->palette();
            $resolved = [
                'primary_color' => $palette['primary'],
                'accent_color' => $palette['accent'],
                'tertiary_color' => $palette['tertiary'],
                'surface_color' => $palette['surface'],
                'surface_alt_color' => $palette['surface_alt'],
                'border_color' => $palette['border'],
                'text_color' => $palette['text'],
                'text_muted_color' => $palette['text_muted'],
                'display_font' => $brief->displayFont(),
                'body_font' => $brief->bodyFont(),
                'heading_scale' => $brief->headingScale(),
                'spacing_density' => $brief->spacingDensity(),
                'corner_style' => $brief->cornerStyle(),
                'container_width' => 'auto',
                'display_scale' => 'standard',
                'spacing_density_explicit' => false,
            ];
        } else {
            $base = self::THEMES[$site->theme] ?? self::THEMES[self::DEFAULT_THEME];
            $resolved = $this->applyBrandColours($base, $profile, $site);
            $resolved = array_merge($resolved, [
                'tertiary_color' => $this->deriveTertiaryColor($resolved['primary_color'] ?? $base['primary_color']),
                'display_font' => 'inter',
                'body_font' => 'inter',
                'heading_scale' => 'balanced',
                'spacing_density' => 'balanced',
                'corner_style' => 'soft',
                'container_width' => 'auto',
                'display_scale' => 'standard',
                'spacing_density_explicit' => false,
            ], self::DEFAULT_TOKEN_COLOURS);
        }

        // Derive band_mode from the business archetype so downstream
        // deriveBandColor knows whether to produce a dark spotlight band
        // (trades/professional) or a light-tinted airy band (boutique/wellness).
        $resolved['band_mode'] = $this->deriveBandMode(
            Archetype::fromProfile(is_string($profile['archetype'] ?? null) ? $profile['archetype'] : null)
        );
        $resolved['brand_section_scheme'] = 'bold';

        return $this->applyCompositionTheme($resolved, $compositionTheme);
    }

    /**
     * Build a theme array from composition.theme (admin intent). Applies
     * any per-token override found in the composition to the resolved
     * theme. Returns the theme unchanged if the composition carries no
     * actionable signals.
     *
     * The full override shape has 13 keys. Each is optional — presence
     * wins over the brief/extraction/preset value for that one token.
     * Absence leaves the prior layer's value in place. Admins can tweak
     * any single token without touching the rest of the design brief.
     *
     * @param  array<string, mixed>  $compositionTheme
     */
    protected function applyCompositionTheme(array $theme, ?array $compositionTheme = null): array
    {
        if (! is_array($compositionTheme)) {
            return $theme;
        }

        // Colour overrides — keyed by the target token in $theme vs the
        // composition.theme override key.
        $colourOverrides = [
            'primary_color' => 'primary_override',
            'accent_color' => 'accent_override',
            'tertiary_color' => 'tertiary_override',
            'surface_color' => 'surface_override',
            'surface_alt_color' => 'surface_alt_override',
            'border_color' => 'border_override',
            'text_color' => 'text_override',
            'text_muted_color' => 'text_muted_override',
        ];

        foreach ($colourOverrides as $themeKey => $overrideKey) {
            $override = $this->normaliseHex((string) ($compositionTheme[$overrideKey] ?? ''));
            if ($override !== null) {
                $theme[$themeKey] = $override;
            }
        }

        // Non-colour overrides (fonts + layout tokens). Each constrained to
        // the same enum DesignBrief uses — skip any value outside the
        // allowlist so a corrupted override can't silently substitute an
        // unknown CSS token.
        $enumOverrides = [
            'display_font' => DesignBrief::DISPLAY_FONTS,
            'body_font' => DesignBrief::BODY_FONTS,
            'heading_scale' => DesignBrief::HEADING_SCALES,
            'spacing_density' => DesignBrief::SPACING_DENSITIES,
            'corner_style' => DesignBrief::CORNER_STYLES,
            'container_width' => self::CONTAINER_WIDTHS,
            'display_scale' => self::DISPLAY_SCALES,
        ];

        foreach ($enumOverrides as $themeKey => $allowed) {
            $override = $compositionTheme["{$themeKey}_override"] ?? null;
            if (is_string($override) && in_array($override, $allowed, true)) {
                $theme[$themeKey] = $override;
                if ($themeKey === 'spacing_density') {
                    $theme['spacing_density_explicit'] = true;
                }
            }
        }

        $brandSectionScheme = $compositionTheme['brand_section_scheme_override'] ?? null;
        if (is_string($brandSectionScheme) && in_array($brandSectionScheme, ['bold', 'soft'], true)) {
            $theme['brand_section_scheme'] = $brandSectionScheme;
        }

        // Light ⇆ Dark invert override. Agent-facing toggle in the design
        // panel flips the neutral tokens (surface / surface_alt / text /
        // text_muted / border) to the opposite luminance, leaving brand
        // colours (primary, accent, tertiary) untouched. Implemented as a
        // lightness flip in HSL space so the hue + saturation of each
        // token is preserved — a subtly-tinted surface stays tinted in
        // its dark form. Applied LAST so per-token colour overrides
        // above still invert correctly.
        if ($this->isInverted($compositionTheme)) {
            foreach (['surface_color', 'surface_alt_color', 'text_color', 'text_muted_color', 'border_color'] as $key) {
                if (! isset($theme[$key]) || ! is_string($theme[$key])) {
                    continue;
                }
                $hex = $this->normaliseHex($theme[$key]);
                if ($hex === null) {
                    continue;
                }
                $hsl = $this->hexToHsl($hex);
                // Clamp to [0.04, 0.96] so pure #ffffff doesn't flip to
                // pure #000000 (too harsh against mid-value accents) and
                // pure #000000 doesn't flip to pure #ffffff.
                $flipped = max(0.04, min(0.96, 1.0 - $hsl['l']));
                $theme[$key] = $this->hslToHex($hsl['h'], $hsl['s'], $flipped);
            }
        }

        // Carry the operator map through so renderTokens() can apply it
        // LAST, after derivation. Invalid keys/values are skipped there
        // (write-path ops reject them; render must not blow up).
        $tokenOverrides = $compositionTheme['token_overrides'] ?? null;
        if (is_array($tokenOverrides)) {
            $theme['token_overrides'] = $tokenOverrides;
        }

        return $theme;
    }

    private function isInverted(?array $compositionTheme): bool
    {
        if (! is_array($compositionTheme)) {
            return false;
        }
        $value = $compositionTheme['invert_mode_override'] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    public function baseTheme(string $handle): array
    {
        return self::THEMES[$handle] ?? self::THEMES['trades-bold'];
    }

    public static function availableThemes(): array
    {
        return array_keys(self::THEMES);
    }

    /**
     * @param  array<string, mixed>  $theme
     * @return array<string, string>
     */
    public function renderTokens(array $theme): array
    {
        $displayFont = $this->fontDefinition((string) ($theme['display_font'] ?? 'inter'));
        $bodyFont = $this->fontDefinition((string) ($theme['body_font'] ?? 'inter'));

        $primary = (string) ($theme['primary_color'] ?? self::THEMES[self::DEFAULT_THEME]['primary_color']);
        $accent = (string) ($theme['accent_color'] ?? self::THEMES[self::DEFAULT_THEME]['accent_color']);
        $surface = (string) ($theme['surface_color'] ?? self::DEFAULT_TOKEN_COLOURS['surface_color']);
        $surfaceAlt = (string) ($theme['surface_alt_color'] ?? self::DEFAULT_TOKEN_COLOURS['surface_alt_color']);
        $text = (string) ($theme['text_color'] ?? self::DEFAULT_TOKEN_COLOURS['text_color']);
        $textMuted = (string) ($theme['text_muted_color'] ?? self::DEFAULT_TOKEN_COLOURS['text_muted_color']);
        // Text-safe variants for places where the brand colour is used AS
        // foreground text (logo wordmark, rich-text links, big stat
        // numbers). If the raw brand already passes WCAG AA against
        // surface, the derivation is a no-op and the text token equals the
        // brand. Same hue + saturation preserved so the site still reads
        // as "orange" / "navy" — only lightness shifts when needed.
        $primaryText = $this->deriveTextSafeColor($primary, $surface);
        $accentText = $this->deriveTextSafeColor($accent, $surface);

        // Surface-alt can legitimately be dark (some design briefs pick a
        // near-black alt band to create contrast rhythm). In that case the
        // default --color-text (picked for light surface) becomes dark-on-
        // dark — invisible. Derive text + text-muted variants that always
        // pass 4.5:1 against surface-alt; consumers use these in any
        // section whose background is surface-alt.
        $textOnAlt = $this->deriveTextSafeColor($text, $surfaceAlt);
        $textMutedOnAlt = $this->deriveTextSafeColor($textMuted, $surfaceAlt, minRatio: 3.0);
        // And primary/accent as text on alt — same principle as on surface.
        $primaryTextOnAlt = $this->deriveTextSafeColor($primary, $surfaceAlt);
        $accentTextOnAlt = $this->deriveTextSafeColor($accent, $surfaceAlt);

        // Band tokens — the "high-contrast spotlight" band used by sections
        // such as the lead-form that need a dramatically dark backdrop.
        //
        // Dark surface (luminance < 0.18): band is the deeper of surface and
        // the hard-coded slate #0f172a, keeping the band at least as dark as
        // the surface itself.
        //
        // Light surface (luminance ≥ 0.18): band is a deep primary-tinted
        // colour — the primary hue pulled down to L≈0.12 so it always reads
        // as "very dark" while staying brand-connected. Saturation is
        // preserved (clamped to a minimum of 0.4 so even desaturated brands
        // produce a vivid-ish dark rather than a near-black grey).
        //
        // band_mode = 'light-tinted' (boutique/wellness archetypes): band is a
        // lightly-tinted primary surface at L≈0.92 — distinct but airy. The
        // text_on_band token is derived as dark slate rather than white.
        $bandMode = (string) ($theme['band_mode'] ?? 'dark');
        $band = $this->deriveBandColor($primary, $accent, $surface, $bandMode);
        $textOnBand = $this->prefersLightInk($band)
            ? $this->deriveTextSafeColor('#ffffff', $band)
            : $this->deriveTextSafeColor('#0f172a', $band);
        $bandOverlay = $band;

        // Text colour safe against a solid-primary background (used by the
        // cta band and any other primary-colour surface). For mid-luminance
        // primaries (bright cyans around #00aeef, yellow-greens, oranges)
        // neither pure white nor pure black reliably passes 4.5:1 from a
        // single-direction shift. Try both white and a deep slate as
        // starting points and pick whichever ends with higher contrast.
        $textOnPrimary = $this->prefersLightInk($primary)
            ? $this->deriveTextSafeColor('#ffffff', $primary)
            : $this->deriveTextSafeColor('#0f172a', $primary);

        // Same two-direction derivation for text on the accent colour,
        // used by every primary CTA button in the site. Fixes white-on-
        // light-cyan / white-on-yellow-green button labels.
        $accentLight = $this->deriveTextSafeColor('#ffffff', $accent);
        $accentDark = $this->deriveTextSafeColor('#0f172a', $accent);
        $textOnAccent = $this->contrastRatio($accentDark, $accent)
            >= $this->contrastRatio($accentLight, $accent)
            ? $accentDark
            : $accentLight;

        // Additive elevated-band token. Polarity-aware: a LIGHT band on
        // dark surfaces, a darker band on light surfaces, with a 1.3:1
        // floor vs --color-surface AND --color-surface-alt (adjacency).
        // deriveBandColor() is untouched.
        $surfaceContrast = $this->deriveSurfaceContrastColor($surface, $surfaceAlt, $primary, $accent);
        // Display type on the band walks past bare-AA to a confident
        // depth (a dark brand's flipped text otherwise stops at a washed
        // mid-tone the moment it scrapes 4.5:1). Light
        // brands' already-dark text clears 7:1 untouched.
        // Band headings speak the same ink as the inner pages' light
        // sections (gray-900-family #0f172a) rather than a walked brand
        // tone, for parity. Polarity-guarded for dark bands.
        $textOnContrast = $this->relativeLuminance($surfaceContrast) >= 0.5
            ? $this->deriveTextSafeColor('#0f172a', $surfaceContrast, minRatio: 7.0)
            : $this->deriveTextSafeColor('#ffffff', $surfaceContrast, minRatio: 7.0);
        $textMutedOnContrast = $this->deriveTextSafeColor($textMuted, $surfaceContrast, minRatio: 4.5);
        $accentTextOnContrast = $this->deriveTextSafeColor($accent, $surfaceContrast);

        $brandSectionScheme = ($theme['brand_section_scheme'] ?? null) === 'soft' ? 'soft' : 'bold';
        $brandSectionSurface = $this->deriveBandColor($primary, $accent, $surface, 'light-tinted');
        $brandSectionInk = $this->deriveTextSafeColor($primary, $brandSectionSurface);
        $brandSectionMutedInk = $this->deriveTextSafeColor($textMuted, $brandSectionSurface);
        $brandSectionAccentInk = $this->deriveTextSafeColor($accent, $brandSectionSurface);

        if ($brandSectionScheme === 'soft') {
            $surfaceContrast = $brandSectionSurface;
            $textOnContrast = $brandSectionInk;
            $textMutedOnContrast = $brandSectionMutedInk;
            $accentTextOnContrast = $brandSectionAccentInk;
        }

        $tokens = [
            'primary' => $primary,
            'primary_text' => $primaryText,
            'primary_text_on_alt' => $primaryTextOnAlt,
            'accent' => $accent,
            'accent_text' => $accentText,
            'accent_text_on_alt' => $accentTextOnAlt,
            'tertiary' => (string) ($theme['tertiary_color'] ?? $this->deriveTertiaryColor((string) ($theme['primary_color'] ?? self::THEMES[self::DEFAULT_THEME]['primary_color']))),
            'surface' => $surface,
            'surface_alt' => $surfaceAlt,
            'border' => (string) ($theme['border_color'] ?? self::DEFAULT_TOKEN_COLOURS['border_color']),
            'text' => $text,
            'text_on_alt' => $textOnAlt,
            'text_muted' => $textMuted,
            'text_muted_on_alt' => $textMutedOnAlt,
            'display_font_stack' => sprintf('"%s", %s', $displayFont['name'], $displayFont['fallback']),
            'body_font_stack' => sprintf('"%s", %s', $bodyFont['name'], $bodyFont['fallback']),
            'display_font_slug' => $displayFont['slug'],
            'body_font_slug' => $bodyFont['slug'],
            'font_link_href' => '/fonts/'.$displayFont['slug'].'+'.$bodyFont['slug'].'.css',
            'radius_card' => self::RADIUS_CARD_MAP[$theme['corner_style'] ?? 'soft'] ?? self::RADIUS_CARD_MAP['soft'],
            'radius_button' => self::RADIUS_BUTTON_MAP[$theme['corner_style'] ?? 'soft'] ?? self::RADIUS_BUTTON_MAP['soft'],
            'section_spacing' => $this->resolveSectionSpacing($theme),
            'container_width' => $this->resolveContainerWidth($theme),
            'heading_letter_spacing' => self::HEADING_LETTER_SPACING_MAP[$theme['heading_scale'] ?? 'balanced'] ?? self::HEADING_LETTER_SPACING_MAP['balanced'],
            'hero_home_clamp_cap' => $this->isGrandDisplayScale($theme) ? '4.5rem' : '3.75rem',
            'hero_inner_clamp_cap' => $this->isGrandDisplayScale($theme) ? '3.75rem' : '3rem',
            // Header, announcement strip and sections share one horizontal inset so the logo's left edge
            // lines up with content at every scale; Grand only adds vertical chrome padding below.
            'nav_padding_class' => 'px-4 sm:px-6 lg:px-8',
            'chrome_padding_y' => $this->isGrandDisplayScale($theme) ? '0.5rem' : '',
            // Grand: shell breathes at xl; empty = no rule emitted.
            'shell_inset_xl' => $this->isGrandDisplayScale($theme) ? '4rem' : '',
            'chrome_brand_row_class' => $this->isGrandDisplayScale($theme) ? 'h-[120px] lg:h-[136px]' : 'h-[104px] lg:h-[120px]',
            // Standard-layout header store controls (search / cart) step up with Grand; the centred header keeps its own sizing.
            'store_control_icon_class' => $this->isGrandDisplayScale($theme) ? 'h-5 w-5' : 'h-4 w-4',
            'store_control_text_class' => $this->isGrandDisplayScale($theme) ? 'text-base' : 'text-sm',
            'icon_stroke_width' => self::ICON_STROKE_WIDTH_MAP[$theme['corner_style'] ?? 'soft'] ?? self::ICON_STROKE_WIDTH_MAP['soft'],
            'band' => $band,
            'text_on_band' => $textOnBand,
            'band_overlay' => $bandOverlay,
            'band_mode' => $bandMode,
            'text_on_primary' => $textOnPrimary,
            'text_on_accent' => $textOnAccent,
            'surface_contrast' => $surfaceContrast,
            'text_on_contrast' => $textOnContrast,
            'text_muted_on_contrast' => $textMutedOnContrast,
            'accent_text_on_contrast' => $accentTextOnContrast,
            'brand_section_scheme' => $brandSectionScheme,
            'brand_section_surface' => $brandSectionSurface,
            'brand_section_ink' => $brandSectionInk,
            'brand_section_muted_ink' => $brandSectionMutedInk,
            'brand_section_accent_ink' => $brandSectionAccentInk,
        ];

        return $this->applyEmittedTokenOverrides($tokens, $theme['token_overrides'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $theme
     */
    private function isGrandDisplayScale(array $theme): bool
    {
        return ($theme['display_scale'] ?? 'standard') === 'grand';
    }

    /**
     * @param  array<string, mixed>  $theme
     */
    private function resolveContainerWidth(array $theme): string
    {
        $tier = $theme['container_width'] ?? 'auto';
        if (is_string($tier) && isset(self::CONTAINER_WIDTH_TIER_MAP[$tier])) {
            return self::CONTAINER_WIDTH_TIER_MAP[$tier];
        }

        if ($this->isGrandDisplayScale($theme)) {
            return self::CONTAINER_WIDTH_TIER_MAP['grand'];
        }

        return self::CONTAINER_WIDTH_MAP[$theme['spacing_density'] ?? 'balanced'] ?? self::CONTAINER_WIDTH_MAP['balanced'];
    }

    /**
     * @param  array<string, mixed>  $theme
     */
    private function resolveSectionSpacing(array $theme): string
    {
        $density = $theme['spacing_density'] ?? 'balanced';
        if (! isset(self::SECTION_SPACING_MAP[$density])) {
            $density = 'balanced';
        }

        if ($this->isGrandDisplayScale($theme) && ! ($theme['spacing_density_explicit'] ?? false)) {
            $density = $density === 'compact' ? 'balanced' : 'generous';
        }

        return self::SECTION_SPACING_MAP[$density];
    }

    /**
     * Emitted CSS variable names (no `--`) that token_overrides may name.
     *
     * @return list<string>
     */
    public static function emittedOverrideNames(): array
    {
        return array_keys(self::emittedOverrideTokenMap());
    }

    /**
     * @return array<string, string>
     */
    public static function emittedOverrideTokenMap(): array
    {
        return self::EMITTED_COLOR_OVERRIDE_TOKENS + self::EMITTED_RADIUS_OVERRIDE_TOKENS;
    }

    public function isRadiusOverrideToken(string $name): bool
    {
        return array_key_exists($name, self::EMITTED_RADIUS_OVERRIDE_TOKENS);
    }

    public function isTextureOverrideToken(string $name): bool
    {
        return array_key_exists($name, self::EMITTED_TEXTURE_OVERRIDE_TOKENS);
    }

    /**
     * Normalise one override value. Colours go through normaliseHex();
     * radius-family values must be a small unit-suffixed length.
     * Texture knobs are validated against the library / clamp ranges.
     */
    public function normaliseTokenOverrideValue(string $name, mixed $value, ?Site $site = null): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if ($this->isRadiusOverrideToken($name)) {
            return preg_match(self::RADIUS_OVERRIDE_PATTERN, $value) === 1 ? $value : null;
        }

        if ($this->isTextureOverrideToken($name)) {
            return $this->normaliseTextureOverrideValue($name, $value, $site);
        }

        if (! array_key_exists($name, self::EMITTED_COLOR_OVERRIDE_TOKENS)) {
            return null;
        }

        return $this->normaliseHex($value);
    }

    private function normaliseTextureOverrideValue(string $name, string $value, ?Site $site): ?string
    {
        return match ($name) {
            'texture' => $this->normaliseTextureKey($value),
            'texture_opacity' => $this->normaliseTextureOpacity($value),
            'texture_size' => in_array($value, TextureLibrary::SIZE_STEPS, true) ? $value : null,
            'texture_image_mode' => in_array($value, TextureLibrary::IMAGE_MODES, true) ? $value : null,
            'texture_image_path' => $this->normaliseTextureImagePath($value, $site),
            default => null,
        };
    }

    private function normaliseTextureKey(string $value): ?string
    {
        if ($value === 'image' || $value === 'none' || TextureLibrary::has($value)) {
            return $value;
        }

        return null;
    }

    private function normaliseTextureOpacity(string $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        $clamped = max(0.01, min(0.5, (float) $value));

        return (string) $clamped;
    }

    private function normaliseTextureImagePath(string $value, ?Site $site): ?string
    {
        if (str_contains($value, '..') || str_contains($value, '\\')) {
            return null;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) === 1) {
            return null;
        }

        $siteId = is_numeric($site?->id) ? (int) $site->id : null;
        if ($siteId === null || $siteId < 1) {
            return preg_match('#^sites/[1-9][0-9]*/.+#', $value) === 1 ? $value : null;
        }

        $prefix = 'sites/'.$siteId.'/';
        if (! str_starts_with($value, $prefix) || strlen($value) <= strlen($prefix)) {
            return null;
        }

        return $value;
    }

    /**
     * Apply a token_overrides map onto already-derived render tokens.
     * Unknown keys and invalid values are skipped (render-time defensive).
     *
     * @param  array<string, mixed>  $tokens
     * @return array<string, mixed>
     */
    public function applyEmittedTokenOverrides(array $tokens, mixed $overrides): array
    {
        if (! is_array($overrides) || $overrides === []) {
            return $tokens;
        }

        $map = self::emittedOverrideTokenMap();

        foreach ($overrides as $name => $value) {
            if (! is_string($name) || ! array_key_exists($name, $map)) {
                continue;
            }

            $normalised = $this->normaliseTokenOverrideValue($name, $value);
            if ($normalised === null) {
                continue;
            }

            $tokens[$map[$name]] = $normalised;
        }

        return $tokens;
    }

    /**
     * Validate a patch map for token_overrides / style_overrides.
     * Explicit null removes a key; unknown names and invalid values fail.
     *
     * @return array{ok: true, set: array<string, string>, remove: list<string>}|array{ok: false, message: string, fields: array<string, list<string>>}
     */
    public function validateTokenOverridePatch(mixed $tokens, bool $allowTextureTokens = false, ?Site $site = null): array
    {
        if (! is_array($tokens)) {
            return [
                'ok' => false,
                'message' => 'tokens must be an object map of CSS variable names.',
                'fields' => ['tokens' => ['object']],
            ];
        }

        $allowed = self::emittedOverrideTokenMap();
        if ($allowTextureTokens) {
            $allowed = $allowed + self::EMITTED_TEXTURE_OVERRIDE_TOKENS;
        }
        $set = [];
        $remove = [];

        foreach ($tokens as $name => $value) {
            if (! is_string($name) || $name === '') {
                return [
                    'ok' => false,
                    'message' => 'Token override keys must be CSS variable names without the -- prefix.',
                    'fields' => ['tokens' => ['keys must be strings']],
                ];
            }

            if (! array_key_exists($name, $allowed)) {
                return [
                    'ok' => false,
                    'message' => "Unknown token [{$name}].",
                    'fields' => ['tokens' => ["unknown key [{$name}]"]],
                ];
            }

            if ($value === null) {
                $remove[] = $name;

                continue;
            }

            $normalised = $this->normaliseTokenOverrideValue($name, $value, $site);
            if ($normalised === null) {
                $display = is_scalar($value) ? (string) $value : gettype($value);
                $kind = $this->isRadiusOverrideToken($name)
                    ? 'length'
                    : ($this->isTextureOverrideToken($name) ? 'value' : 'hex colour');

                return [
                    'ok' => false,
                    'message' => "Invalid {$kind} for [{$name}]: {$display}.",
                    'fields' => ['tokens' => ["[{$name}] is not a valid {$kind}"]],
                ];
            }

            $set[$name] = $normalised;
        }

        return ['ok' => true, 'set' => $set, 'remove' => $remove];
    }

    /**
     * @param  array<string, string>  $existing
     * @param  array<string, string>  $set
     * @param  list<string>  $remove
     * @return array<string, string>
     */
    public function mergeTokenOverrideMap(array $existing, array $set, array $remove): array
    {
        foreach ($remove as $name) {
            unset($existing[$name]);
        }

        foreach ($set as $name => $value) {
            $existing[$name] = $value;
        }

        return $existing;
    }

    /**
     * Warn-only contrast lint for surface/text family pairs touched by an override.
     *
     * @param  array<string, mixed>  $tokens
     * @param  list<string>  $touchedCssNames
     * @return list<array{code: string, message: string, path: string}>
     */
    public function contrastWarningsForTokens(array $tokens, array $touchedCssNames): array
    {
        $touched = array_fill_keys($touchedCssNames, true);
        $warnings = [];

        foreach (self::TOKEN_OVERRIDE_CONTRAST_PAIRS as [$fgCss, $bgCss, $fgKey, $bgKey]) {
            if ($touched !== [] && ! isset($touched[$fgCss]) && ! isset($touched[$bgCss])) {
                continue;
            }

            $fg = $tokens[$fgKey] ?? null;
            $bg = $tokens[$bgKey] ?? null;
            if (! is_string($fg) || ! is_string($bg) || $this->normaliseHex($fg) === null || $this->normaliseHex($bg) === null) {
                continue;
            }

            $ratio = $this->contrastRatio($fg, $bg);
            if ($ratio < 4.5) {
                $warnings[] = [
                    'code' => 'contrast_below_aa',
                    'message' => "{$fgCss}/{$bgCss} contrast ratio {$ratio}:1 is below AA (4.5:1).",
                    'path' => $bgCss,
                ];
            } elseif ($ratio < 7.0 && $fgCss === 'color-text' && $bgCss === 'color-surface') {
                $warnings[] = [
                    'code' => 'contrast_below_aaa',
                    'message' => "{$fgCss}/{$bgCss} contrast ratio {$ratio}:1 is below AAA (7.0:1).",
                    'path' => $fgCss,
                ];
            }
        }

        return $warnings;
    }

    /**
     * Inline custom-property declarations for a section wrapper so only
     * that instance repaints. Empty/unknown/invalid maps return ''.
     */
    public function inlineTokenOverrides(mixed $overrides): string
    {
        if (! is_array($overrides) || $overrides === []) {
            return '';
        }

        $parts = [];
        foreach ($overrides as $name => $value) {
            if (! is_string($name) || $this->isTextureOverrideToken($name)) {
                continue;
            }
            $normalised = $this->normaliseTokenOverrideValue($name, $value);
            if ($normalised === null) {
                continue;
            }
            $parts[] = '--'.$name.': '.$normalised;
        }

        return $parts === [] ? '' : implode('; ', $parts).';';
    }

    private function applyBrandColours(array $theme, array $profile, Site $site): array
    {
        if ($visualTheme = $this->applyVisualPalette($theme, $profile)) {
            return $visualTheme;
        }

        // Layer 2: layout_fingerprint.palette.primary_hex_guess.
        if ($fingerprintTheme = $this->applyFingerprintPalette($theme, $site)) {
            return $fingerprintTheme;
        }

        $colours = $profile['palette'] ?? [];

        $colours = array_values(array_unique(array_filter(
            array_map(fn ($c) => $this->normaliseHex($c), $colours)
        )));

        if (empty($colours)) {
            return $theme;
        }

        $scored = [];
        foreach ($colours as $hex) {
            $hsl = $this->hexToHsl($hex);
            if ($hsl['l'] < 0.1 || $hsl['l'] > 0.9 || $hsl['s'] < 0.15) {
                continue;
            }
            $scored[] = ['hex' => $hex, 'score' => $hsl['s'] * (1 - abs($hsl['l'] - 0.4))];
        }

        if (empty($scored)) {
            return $theme;
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        $theme['primary_color'] = $scored[0]['hex'];

        if (count($scored) > 1) {
            $primaryHsl = $this->hexToHsl($scored[0]['hex']);
            foreach (array_slice($scored, 1) as $candidate) {
                $candidateHsl = $this->hexToHsl($candidate['hex']);
                $hueDiff = abs($primaryHsl['h'] - $candidateHsl['h']);
                if ($hueDiff > 180) {
                    $hueDiff = 360 - $hueDiff;
                }
                if ($hueDiff > 30) {
                    $theme['accent_color'] = $candidate['hex'];
                    break;
                }
            }
        }

        return $theme;
    }

    private function applyFingerprintPalette(array $theme, Site $site): ?array
    {
        $fingerprint = $site->businessProfile?->layout_fingerprint;
        if (! is_array($fingerprint)) {
            return null;
        }

        $primary = $this->normaliseHex((string) ($fingerprint['palette']['primary_hex_guess'] ?? ''));
        if (! $primary) {
            return null;
        }

        $theme['primary_color'] = $primary;

        $accent = $this->normaliseHex((string) ($fingerprint['palette']['accent_hex_guess'] ?? ''));
        if ($accent && $accent !== $primary) {
            $theme['accent_color'] = $accent;
        }

        return $theme;
    }

    private function applyVisualPalette(array $theme, array $profile): ?array
    {
        $palette = $profile['visual']['palette'] ?? null;
        if (! is_array($palette)) {
            return null;
        }

        $primary = $this->normaliseHex((string) ($palette['primary'] ?? ''));
        if (! $primary) {
            return null;
        }
        $theme['primary_color'] = $primary;

        $primaryHsl = $this->hexToHsl($primary);
        foreach (['accent', 'secondary', 'text'] as $key) {
            $candidate = $this->normaliseHex((string) ($palette[$key] ?? ''));
            if (! $candidate || $candidate === $primary) {
                continue;
            }

            $hsl = $this->hexToHsl($candidate);
            if ($hsl['l'] < 0.12 || $hsl['l'] > 0.88) {
                continue;
            }
            if ($hsl['s'] < 0.25) {
                continue;
            }
            $hueDiff = abs($primaryHsl['h'] - $hsl['h']);
            if ($hueDiff > 180) {
                $hueDiff = 360 - $hueDiff;
            }
            $lightnessDiff = abs($primaryHsl['l'] - $hsl['l']);

            if ($hueDiff > 30 || $lightnessDiff > 0.25) {
                $theme['accent_color'] = $candidate;
                break;
            }
        }

        return $theme;
    }

    private function resolveDesignBrief(Site $site, ?array $designBrief = null): ?DesignBrief
    {
        if ($designBrief === null) {
            // Read via direct DB query rather than the passed-in Site
            // instance's attribute. Long-running queue workers (batch
            // jobs, persistent renderers) can hold a stale Site whose
            // design_brief attribute was fetched before DesignBriefJob
            // or DesignPanel::save updated it.
            $stored = Site::where('id', $site->id)->value('design_brief');
            if (is_array($stored)) {
                $designBrief = $stored;
            }
        }

        if (! is_array($designBrief)) {
            return null;
        }

        return DesignBrief::fromArray($designBrief);
    }

    private function deriveTertiaryColor(string $primary): string
    {
        $hsl = $this->hexToHsl($primary);
        $derivedHue = fmod($hsl['h'] + 60, 360);
        $derivedSaturation = max(0.18, $hsl['s'] - 0.2);
        $derivedLightness = min(0.78, max(0.35, $hsl['l'] + 0.18));

        return $this->hslToHex($derivedHue, $derivedSaturation, $derivedLightness);
    }

    /**
     * Map an archetype to a band_mode value.
     *
     * Light-tinted archetypes (boutique retail, premium specialist, wellness-
     * adjacent) produce an airy band that suits soft/pastel palettes. Everything
     * else defaults to 'dark' — current behaviour, no visual change on existing
     * trade/professional sites.
     */
    private function deriveBandMode(Archetype $archetype): string
    {
        return in_array($archetype, self::LIGHT_TINTED_BAND_ARCHETYPES, true)
            ? 'light-tinted'
            : 'dark';
    }

    /**
     * Derive the band background colour for "high-contrast spotlight" sections.
     *
     * band_mode = 'dark' (default):
     *   Dark surface (WCAG luminance < 0.18): returns whichever is darker between
     *   the surface and the hard-coded deep-slate #0f172a.
     *   Light surface (luminance ≥ 0.18): returns a deep primary-tinted colour at
     *   L≈0.12 with saturation clamped to [0.40, primary-saturation] so even
     *   desaturated brands produce a vivid-ish dark rather than near-black grey.
     *   In both branches the result is guaranteed to have WCAG luminance ≤ 0.15.
     *
     * band_mode = 'light-tinted':
     *   Returns a softly-tinted primary surface at L≈0.92 with saturation clamped
     *   to [0.10, 0.30] so cream/blush/pastel archetypes get a distinct-but-airy
     *   band — not near-white (which would disappear against the page) and not the
     *   same saturated hue as the primary (which would be garish). The corresponding
     *   text_on_band token is derived as dark slate by the caller.
     */
    private function deriveBandColor(string $primary, string $accent, string $surface, string $bandMode = 'dark'): string
    {
        // Saturation threshold below which a hex is considered "achromatic"
        // (black / white / pure grey). hexToHsl returns h=0 for any
        // saturation-zero colour, which would otherwise propagate into
        // the band derivation as a "red hue at L=0.12" — a surprise dark
        // red-brown band on every brand-neutral site. Use this guard to fall
        // through to the accent's hue, then deepSlate, instead of inventing red.
        $achromaticThreshold = 0.05;

        if ($bandMode === 'light-tinted') {
            // Light-tinted path: primary hue at high lightness, gently saturated.
            // Lightness 0.95 ensures WCAG luminance > 0.85 across vivid hues;
            // saturation clamped to [0.08, 0.22] keeps the tint perceptible but
            // airy — not the same saturated shade as the primary.
            //
            // Hue source: primary if chromatic; accent if primary is achromatic
            // and accent is chromatic; otherwise the airy-tint logic still
            // produces a near-white at L=0.95 with s clamped to 0.08, which
            // reads as a faint warm-grey wash — acceptable for the
            // brand-neutral edge case in this mode.
            $hueSourceHsl = $this->pickChromaticHsl($primary, $accent, $achromaticThreshold);

            return $this->hslToHex(
                $hueSourceHsl['h'],
                min(0.22, max(0.08, $hueSourceHsl['s'] * 0.25)),
                0.95,
            );
        }

        $deepSlate = '#0f172a';
        $surfaceLuminance = $this->relativeLuminance($surface);

        if ($surfaceLuminance < 0.18) {
            // Dark-surface path: use whichever is darker.
            return $this->relativeLuminance($surface) <= $this->relativeLuminance($deepSlate)
                ? $surface
                : $deepSlate;
        }

        // Light-surface dark-mode path: deep lightness, saturated, hue from
        // primary. If primary is achromatic (black/white/grey), promoting
        // its h=0 placeholder through the saturation clamp would invent a
        // dark-red hue (HSL(0, 0.40, 0.12) = #2b1212) — visually a brown.
        // Fall back to: accent hue if chromatic; else deepSlate (no
        // invented hue at all).
        $primaryHsl = $this->hexToHsl($primary);
        if ($primaryHsl['s'] < $achromaticThreshold) {
            $accentHsl = $this->hexToHsl($accent);
            if ($accentHsl['s'] >= $achromaticThreshold) {
                return $this->hslToHex(
                    $accentHsl['h'],
                    max(0.40, $accentHsl['s']),
                    0.12,
                );
            }

            return $deepSlate;
        }

        return $this->hslToHex(
            $primaryHsl['h'],
            max(0.40, $primaryHsl['s']),
            0.12,
        );
    }

    /**
     * Elevated band that actually reads against --color-surface and the
     * neighbouring --color-surface-alt. Light brands get a darker wash;
     * dark brands get a light wash. Floor is 1.3:1 vs both (the inherited
     * alt pair sits at ~1.1:1).
     */
    private function deriveSurfaceContrastColor(string $surface, string $surfaceAlt, string $primary, string $accent): string
    {
        $surfaceLum = $this->relativeLuminance($surface);
        $surfaceHsl = $this->hexToHsl($surface);
        $hueSource = $this->pickChromaticHsl($primary, $accent, 0.05);
        $h = $surfaceHsl['s'] >= 0.05 ? $surfaceHsl['h'] : $hueSource['h'];
        $s = $surfaceHsl['s'] >= 0.05 ? $surfaceHsl['s'] : $hueSource['s'];

        if ($surfaceLum < 0.18) {
            // Parity with the inner pages' bg-white light sections:
            // a dark brand's flipped band is plain white when the floors
            // allow, not a grey-washed tint. Light-brand bands keep their
            // darker-tint walk below — the tint IS their mechanism.
            if ($this->meetsContrastFloor('#ffffff', $surface, $surfaceAlt)) {
                return '#ffffff';
            }
            $s = min(0.18, max(0.04, $s * 0.25));
            for ($lightness = 0.92; $lightness >= 0.55; $lightness -= 0.02) {
                $candidate = $this->hslToHex($h, $s, $lightness);
                if ($this->meetsContrastFloor($candidate, $surface, $surfaceAlt)
                    && $this->relativeLuminance($candidate) > $surfaceLum) {
                    return $candidate;
                }
            }

            return $this->hslToHex($h, $s, 0.88);
        }

        $s = min(0.28, max(0.06, $s));
        $start = max(0.12, $surfaceHsl['l'] - 0.08);
        for ($lightness = $start; $lightness >= 0.08; $lightness -= 0.02) {
            $candidate = $this->hslToHex($h, $s, $lightness);
            if ($this->meetsContrastFloor($candidate, $surface, $surfaceAlt)
                && $this->relativeLuminance($candidate) < $surfaceLum) {
                return $candidate;
            }
        }

        return $this->hslToHex($h, $s, 0.12);
    }

    private function meetsContrastFloor(string $candidate, string $surface, string $surfaceAlt): bool
    {
        return $this->contrastRatio($candidate, $surface) >= 1.3
            && $this->contrastRatio($candidate, $surfaceAlt) >= 1.3;
    }

    /**
     * Return the HSL of the first chromatic-enough colour (saturation above
     * the achromatic threshold), preferring primary, falling back to accent.
     * Used by band-colour derivation so brand-neutral primaries don't
     * propagate h=0 placeholders downstream.
     *
     * @return array{h: float, s: float, l: float}
     */
    private function pickChromaticHsl(string $primary, string $accent, float $threshold): array
    {
        $primaryHsl = $this->hexToHsl($primary);
        if ($primaryHsl['s'] >= $threshold) {
            return $primaryHsl;
        }

        $accentHsl = $this->hexToHsl($accent);
        if ($accentHsl['s'] >= $threshold) {
            return $accentHsl;
        }

        return $primaryHsl;
    }

    /**
     * Derive a text-safe variant of a brand colour that passes the WCAG AA
     * 4.5:1 contrast minimum against the given surface. Returns the brand
     * colour unchanged when it already passes. Otherwise walks lightness
     * toward the opposite end of the surface until the ratio is met — so a
     * pale orange primary on white becomes a darker rust, but the same
     * primary on a dark surface becomes a lighter peach.
     *
     * Preserves hue and saturation so the brand still reads as "orange" /
     * "navy" / etc. — only lightness shifts.
     */
    public function deriveTextSafeColor(string $brand, string $surface, float $minRatio = 4.5): string
    {
        if ($this->contrastRatio($brand, $surface) >= $minRatio) {
            return $brand;
        }

        $brandHsl = $this->hexToHsl($brand);
        $surfaceLuminance = $this->relativeLuminance($surface);
        // Light surface → darken brand; dark surface → lighten brand.
        $step = $surfaceLuminance > 0.5 ? -0.02 : 0.02;
        $lightness = $brandHsl['l'];

        // Cap at ~100 iterations so a pathological input can't loop forever.
        for ($i = 0; $i < 100; $i++) {
            $lightness = max(0.0, min(1.0, $lightness + $step));
            $candidate = $this->hslToHex($brandHsl['h'], $brandHsl['s'], $lightness);
            if ($this->contrastRatio($candidate, $surface) >= $minRatio) {
                return $candidate;
            }
            if ($lightness <= 0.0 || $lightness >= 1.0) {
                break;
            }
        }

        // Give up: return the extreme-lightness candidate so callers at
        // least get a high-contrast value rather than the failing brand.
        return $this->hslToHex($brandHsl['h'], $brandHsl['s'] * 0.85, $surfaceLuminance > 0.5 ? 0.08 : 0.92);
    }

    public function contrastRatio(string $hexA, string $hexB): float
    {
        $lumA = $this->relativeLuminance($hexA);
        $lumB = $this->relativeLuminance($hexB);

        return (max($lumA, $lumB) + 0.05) / (min($lumA, $lumB) + 0.05);
    }

    /**
     * Whether inverted (white) chrome belongs on this fill.
     *
     * Thin wrapper around prefersLightInk(): the same walked-candidate
     * decision renderTokens() uses for text_on_primary / text_on_band.
     * Ties break toward dark ink (light chrome off).
     */
    public function isDarkSurface(string $hex): bool
    {
        return $this->prefersLightInk($hex);
    }

    /**
     * Light ink on this fill when the walked white candidate strictly
     * out-contrasts the walked dark-slate candidate. Ties break toward
     * the dark candidate — identical to the text_on_primary pick.
     */
    private function prefersLightInk(string $surfaceHex): bool
    {
        $surface = $this->normaliseHex($surfaceHex) ?? $surfaceHex;
        $candidateLight = $this->deriveTextSafeColor('#ffffff', $surface);
        $candidateDark = $this->deriveTextSafeColor('#0f172a', $surface);

        return $this->contrastRatio($candidateLight, $surface)
            > $this->contrastRatio($candidateDark, $surface);
    }

    /**
     * WCAG relative luminance. Input MUST be a 6-digit hex — callers should
     * pre-normalise via normaliseHex if the source is user-supplied.
     */
    public function relativeLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) {
            return 0.0;
        }

        $channels = [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
        $linear = array_map(
            fn (float $c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4,
            $channels,
        );

        return (0.2126 * $linear[0]) + (0.7152 * $linear[1]) + (0.0722 * $linear[2]);
    }

    /**
     * @return array{name: string, slug: string, fallback: string, weights: string}
     */
    private function fontDefinition(string $handle): array
    {
        return self::FONTS[$handle] ?? self::FONTS['inter'];
    }

    public function normaliseHex(string $hex): ?string
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return null;
        }

        return '#'.strtolower($hex);
    }

    public function hexToHsl(string $hex): array
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;

        if ($d == 0) {
            return ['h' => 0, 's' => 0, 'l' => $l];
        }

        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

        $h = match (true) {
            $max === $r => 60 * fmod(($g - $b) / $d, 6),
            $max === $g => 60 * (($b - $r) / $d + 2),
            default     => 60 * (($r - $g) / $d + 4),
        };

        if ($h < 0) {
            $h += 360;
        }

        return ['h' => $h, 's' => $s, 'l' => $l];
    }

    public function hslToHex(float $hue, float $saturation, float $lightness): string
    {
        $hue = fmod($hue, 360);
        if ($hue < 0) {
            $hue += 360;
        }

        $c = (1 - abs(2 * $lightness - 1)) * $saturation;
        $x = $c * (1 - abs(fmod($hue / 60, 2) - 1));
        $m = $lightness - $c / 2;

        [$r, $g, $b] = match (true) {
            $hue < 60 => [$c, $x, 0],
            $hue < 120 => [$x, $c, 0],
            $hue < 180 => [0, $c, $x],
            $hue < 240 => [0, $x, $c],
            $hue < 300 => [$x, 0, $c],
            default => [$c, 0, $x],
        };

        return sprintf(
            '#%02x%02x%02x',
            (int) round(($r + $m) * 255),
            (int) round(($g + $m) * 255),
            (int) round(($b + $m) * 255),
        );
    }
}
