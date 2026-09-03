<?php

namespace App\Support\Textures;

use App\Models\Site;

final class TextureLayer
{
    /** @var array<string, float> */
    public const SIZE_SCALE = [
        'sm' => 0.75,
        'md' => 1.0,
        'lg' => 1.5,
    ];

    /**
     * @param  array<string, mixed>|null  $styleOverrides
     */
    public static function resolve(
        ?ResolvedTexture $siteTexture,
        ?array $styleOverrides,
        bool $defaultOn,
        ?Site $site = null,
    ): ?ResolvedTexture {
        $overrides = is_array($styleOverrides) ? $styleOverrides : [];
        $sectionKey = self::stringOverride($overrides, 'texture');

        if ($sectionKey === 'none') {
            return null;
        }

        if ($sectionKey === null && ! $defaultOn) {
            return null;
        }

        $base = $siteTexture ?? TextureResolver::none();

        if ($sectionKey === 'image') {
            $image = self::resolveImage($overrides, $site, $base);
            if ($image !== null) {
                return $image;
            }
            // Missing/broken media path: fall through to the SVG resolution.
            $sectionKey = null;
        }

        if ($sectionKey !== null && TextureLibrary::isDrawableKey($sectionKey)) {
            $base = TextureResolver::fromLibrary($sectionKey, $overrides['texture_opacity'] ?? null);
            $base = self::copy($base, overridesRoot: true);
        } elseif ($base->isNone()) {
            return null;
        }

        $opacity = self::stringOverride($overrides, 'texture_opacity');
        if ($opacity !== null && is_numeric($opacity)) {
            $clamped = max(0.01, min(0.5, (float) $opacity));
            $base = self::copy($base, opacity: $clamped, overridesRoot: true);
        }

        $sizeStep = self::stringOverride($overrides, 'texture_size');
        if ($sizeStep !== null && isset(self::SIZE_SCALE[$sizeStep])) {
            $scale = self::SIZE_SCALE[$sizeStep];
            $base = self::copy(
                $base,
                size: (int) round($base->size * $scale),
                height: (int) round($base->height * $scale),
                overridesRoot: true,
            );
        }

        return $base->isNone() ? null : $base;
    }

    /**
     * @param  array<string, mixed>|null  $section
     */
    public static function html(
        ?ResolvedTexture $siteTexture,
        ?array $section,
        bool $defaultOn,
        ?Site $site = null,
        bool $softFilter = false,
    ): string {
        return self::markup(
            self::resolve(
                $siteTexture,
                is_array($section['style_overrides'] ?? null) ? $section['style_overrides'] : null,
                $defaultOn,
                $site,
            ),
            $softFilter,
        );
    }

    public static function markup(?ResolvedTexture $layer, bool $softFilter = false): string
    {
        if ($layer === null || $layer->isNone()) {
            return '';
        }

        $classes = 'absolute inset-0 hero-pattern';
        if ($layer->mode === 'image') {
            $classes .= ' site-texture--image';
        }

        $styles = [];
        if ($layer->overridesRoot) {
            $image = $layer->cssImage();
            if ($image !== null) {
                $styles[] = '--site-texture-image: '.$image;
            }
            $styles[] = '--site-texture-opacity: '.$layer->opacity;
            $styles[] = '--site-texture-size: '.$layer->sizeCss();
            $styles[] = '--site-texture-repeat: '.$layer->repeatCss();
        }
        if ($softFilter) {
            $styles[] = 'filter: invert(1)';
        }

        $html = '<div class="'.$classes.'"';
        if ($styles !== []) {
            $html .= ' style="'.implode('; ', $styles).';"';
        }

        return $html.'></div>';
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private static function resolveImage(array $overrides, ?Site $site, ResolvedTexture $fallback): ?ResolvedTexture
    {
        if ($site === null) {
            return null;
        }

        $path = self::stringOverride($overrides, 'texture_image_path')
            ?? (is_string($site->texture_image_path) ? $site->texture_image_path : null);
        $mode = self::stringOverride($overrides, 'texture_image_mode') ?? 'tile';
        $resolved = TextureResolver::fromImage(
            $site,
            $path,
            $overrides['texture_opacity'] ?? $fallback->opacity,
            $mode,
        );

        return $resolved === null ? null : self::copy($resolved, overridesRoot: true);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private static function stringOverride(array $overrides, string $key): ?string
    {
        $value = $overrides[$key] ?? null;
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function copy(
        ResolvedTexture $base,
        ?float $opacity = null,
        ?int $size = null,
        ?int $height = null,
        ?bool $overridesRoot = null,
    ): ResolvedTexture {
        return new ResolvedTexture(
            key: $base->key,
            opacity: $opacity ?? $base->opacity,
            size: $size ?? $base->size,
            height: $height ?? $base->height,
            svgUri: $base->svgUri,
            mode: $base->mode,
            imageMode: $base->imageMode,
            imageUrl: $base->imageUrl,
            overridesRoot: $overridesRoot ?? $base->overridesRoot,
        );
    }
}
