<?php

namespace App\Support\Textures;

/**
 * Curated tileable SVG textures. Colour is never baked in: tiles use an
 * opaque black fill/stroke so CSS mask-image + a surface colour can paint
 * them. Opacity is a separate knob (see default_opacity).
 *
 * @phpstan-type TextureEntry array{
 *     key: string,
 *     svg: string|null,
 *     default_opacity: float,
 *     default_size: int,
 *     default_height: int
 * }
 */
final class TextureLibrary
{
    public const PLUS_PATH = 'M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z';

    /** @var list<string> */
    public const KEYS = [
        'plus',
        'dots',
        'grid',
        'diagonal-hatch',
        'herringbone',
        'waves',
        'topography',
        'sprig',
        'noise',
        'none',
    ];

    /** Fallback motif when a stored key is unknown or retired. */
    public const FALLBACK_KEY = 'plus';

    /** Seeded-pick pool for unmatched sites. */
    public const SEEDED_KEYS = ['plus', 'dots', 'grid', 'waves', 'noise'];

    /** @var list<string> */
    public const SIZE_STEPS = ['sm', 'md', 'lg'];

    /** @var list<string> */
    public const IMAGE_MODES = ['tile', 'cover'];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return self::KEYS;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::entries());
    }

    /**
     * @return TextureEntry|null
     */
    public static function get(string $key): ?array
    {
        return self::entries()[$key] ?? null;
    }

    /**
     * @return array<string, TextureEntry>
     */
    public static function all(): array
    {
        return self::entries();
    }

    public static function isLibraryKey(string $key): bool
    {
        return self::has($key);
    }

    public static function isDrawableKey(string $key): bool
    {
        return self::has($key) && $key !== 'none';
    }

    /**
     * @return array<string, TextureEntry>
     */
    private static function entries(): array
    {
        static $entries = null;

        if ($entries !== null) {
            return $entries;
        }

        $entries = [
            'plus' => self::entry('plus', self::plusSvg(), 0.05, 60),
            'dots' => self::entry('dots', self::dotsSvg(), 0.06, 24),
            'grid' => self::entry('grid', self::gridSvg(), 0.06, 32),
            'diagonal-hatch' => self::entry('diagonal-hatch', self::diagonalHatchSvg(), 0.07, 12),
            'herringbone' => self::entry('herringbone', self::herringboneSvg(), 0.06, 28),
            'waves' => self::entry('waves', self::wavesSvg(), 0.07, 80, 20),
            'topography' => self::entry('topography', self::topographySvg(), 0.06, 120),
            'sprig' => self::entry('sprig', self::sprigSvg(), 0.08, 90),
            'noise' => self::entry('noise', self::noiseSvg(), 0.35, 128),
            'none' => self::entry('none', null, 0.0, 0, 0),
        ];

        return $entries;
    }

    /**
     * @return TextureEntry
     */
    private static function entry(string $key, ?string $svg, float $opacity, int $size, ?int $height = null): array
    {
        return [
            'key' => $key,
            'svg' => $svg,
            'default_opacity' => $opacity,
            'default_size' => $size,
            'default_height' => $height ?? $size,
        ];
    }

    private static function plusSvg(): string
    {
        return self::dataUri(
            "<svg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'><g fill='none' fill-rule='evenodd'><g fill='#000'><path d='".self::PLUS_PATH."'/></g></g></svg>"
        );
    }

    private static function dotsSvg(): string
    {
        return self::dataUri(
            "<svg width='24' height='24' viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg'><circle cx='12' cy='12' r='1.5' fill='#000'/></svg>"
        );
    }

    private static function gridSvg(): string
    {
        return self::dataUri(
            "<svg width='32' height='32' viewBox='0 0 32 32' xmlns='http://www.w3.org/2000/svg'><path d='M32 0H0v32' fill='none' stroke='#000' stroke-width='0.5'/></svg>"
        );
    }

    private static function diagonalHatchSvg(): string
    {
        return self::dataUri(
            "<svg width='12' height='12' viewBox='0 0 12 12' xmlns='http://www.w3.org/2000/svg'><path d='M-1 1l2-2M0 12l12-12M11 13l2-2' fill='none' stroke='#000' stroke-width='1'/></svg>"
        );
    }

    private static function herringboneSvg(): string
    {
        return self::dataUri(
            "<svg width='28' height='28' viewBox='0 0 28 28' xmlns='http://www.w3.org/2000/svg'><g fill='#000'><polygon points='0,0 14,0 0,14'/><polygon points='14,0 28,0 28,14 14,14'/><polygon points='0,14 14,14 14,28 0,28'/><polygon points='14,14 28,14 14,28'/></g></svg>"
        );
    }

    private static function wavesSvg(): string
    {
        return self::dataUri(
            "<svg width='80' height='20' viewBox='0 0 80 20' xmlns='http://www.w3.org/2000/svg'><path d='M0 10C10 2 20 2 30 10s20 8 30 0 20-8 30 0' fill='none' stroke='#000' stroke-width='1.25' stroke-linecap='square'/></svg>"
        );
    }

    private static function topographySvg(): string
    {
        return self::dataUri(
            "<svg width='120' height='120' viewBox='0 0 120 120' xmlns='http://www.w3.org/2000/svg'><g fill='none' stroke='#000' stroke-width='0.75'><ellipse cx='60' cy='60' rx='16' ry='12'/><ellipse cx='60' cy='60' rx='30' ry='24'/><ellipse cx='60' cy='60' rx='46' ry='38'/></g></svg>"
        );
    }

    private static function sprigSvg(): string
    {
        return self::dataUri(
            "<svg width='90' height='90' viewBox='0 0 90 90' xmlns='http://www.w3.org/2000/svg'><g fill='none' stroke='#000' stroke-width='1' stroke-linecap='round' stroke-linejoin='round'><path d='M46 32c-1 12-2 20-2 28'/><path d='M44 44c-8-5-14-5-18-2 6 4 12 5 18 4'/><path d='M44 52c8-4 14-3 18 1-6 3-12 3-18 2'/></g></svg>"
        );
    }

    private static function noiseSvg(): string
    {
        return self::dataUri(
            "<svg width='128' height='128' viewBox='0 0 128 128' xmlns='http://www.w3.org/2000/svg'><filter id='t' x='0' y='0' width='100%' height='100%'><feTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='4' stitchTiles='stitch'/></filter><rect width='128' height='128' filter='url(#t)'/></svg>"
        );
    }

    private static function dataUri(string $svg): string
    {
        $compact = str_replace(["\n", "\r", "\t"], '', $svg);

        return 'data:image/svg+xml,'.str_replace(
            ['#', '<', '>', '"'],
            ['%23', '%3C', '%3E', "'"],
            $compact,
        );
    }
}
