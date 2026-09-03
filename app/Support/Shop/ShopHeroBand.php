<?php

namespace App\Support\Shop;

final class ShopHeroBand
{
    /**
     * Site home hero boxed-left copy panel fallback
     * (`resources/views/site/sections/hero.blade.php` `$heroPanelOpacity`).
     */
    public const DEFAULT_PANEL_OPACITY = 78;

    public static function gutterClass(): string
    {
        return 'px-6 sm:px-10 md:px-14';
    }

    /**
     * Inner-hero scrim class soup (hero.blade.php $gradientClass /
     * _hero_scene.blade.php). Direction follows the text column so T44
     * zone-awareness stays. Center has no Tailwind equivalent — the
     * inline overlayGradient() paints that case.
     */
    public static function overlayClass(string $textZone): string
    {
        $stops = 'from-black/70 via-black/40 to-transparent';

        return match (self::column($textZone)) {
            'right' => 'bg-gradient-to-l '.$stops,
            'center' => '',
            default => 'bg-gradient-to-r '.$stops,
        };
    }

    public static function overlayGradient(string $textZone): string
    {
        $from = 'rgb(0 0 0 / 0.7)';
        $via = 'rgb(0 0 0 / 0.4)';

        return match (self::column($textZone)) {
            'right' => "linear-gradient(to left, {$from}, {$via}, transparent)",
            'center' => "linear-gradient(to right, transparent, {$from} 35%, {$via} 50%, {$from} 65%, transparent)",
            default => "linear-gradient(to right, {$from}, {$via}, transparent)",
        };
    }

    public static function verticalClass(string $textZone): string
    {
        return match (self::parts($textZone)[0]) {
            'top' => 'items-start',
            'bottom' => 'items-end',
            default => 'items-center',
        };
    }

    public static function horizontalClass(string $textZone): string
    {
        return match (self::column($textZone)) {
            'right' => 'text-right ml-auto',
            'center' => 'text-center mx-auto',
            default => 'text-left',
        };
    }

    /**
     * Inner-page h1 (hero.blade.php inner branch). Duplicated here so
     * shop views do not copy-paste the class soup.
     */
    public static function titleClass(?string $clampCap = null): string
    {
        $cap = $clampCap ?? '3rem';

        return "text-[clamp(1.875rem,3vw,{$cap})] font-extrabold text-white drop-shadow-[0_2px_8px_rgba(0,0,0,0.7)] mb-3 leading-tight [text-wrap:balance]";
    }

    /**
     * Inner-page subtitle (hero.blade.php $subTextClass + $textShadow).
     */
    public static function subtitleClass(): string
    {
        return 'text-base md:text-lg text-white/80 drop-shadow-[0_2px_8px_rgba(0,0,0,0.7)] max-w-xl leading-relaxed';
    }

    /**
     * Home-hero eyebrow language (font-semibold tracking-widest uppercase
     * + white + drop-shadow). Kept visible on mobile — shoppers need the
     * Shop/Category label; inner home hides it below sm.
     */
    public static function eyebrowClass(): string
    {
        return 'flex text-sm font-semibold tracking-widest uppercase mb-4 items-center gap-2 text-white drop-shadow-[0_2px_8px_rgba(0,0,0,0.7)]';
    }

    public static function flexJustifyClass(string $textZone): string
    {
        return match (self::column($textZone)) {
            'right' => 'justify-end',
            'center' => 'justify-center',
            default => '',
        };
    }

    public static function wrapTitle(string $title, mixed $accentWord, \App\Models\Site $site): string
    {
        $word = is_string($accentWord) ? $accentWord : null;
        $style = \App\Support\ChromeKnobs::accentStyle($site) === 'italic' ? 'italic' : null;

        return app(\App\Services\Site\AccentWordRenderer::class)->wrap($title, $word, $style);
    }

    /**
     * Boxed-left copy surface from the site home hero (`$heroCopySurfaceStyle`
     * default assignment). Empty for plain/null so the shop index hero stays
     * byte-identical to today.
     */
    public static function copySurfaceStyle(string $textStyle): string
    {
        if ($textStyle !== 'boxed') {
            return '';
        }

        return 'background-color: color-mix(in srgb, var(--brand-primary) '.self::DEFAULT_PANEL_OPACITY.'%, transparent); border-radius: var(--radius-card); padding: 1.5rem 2rem; max-width: 44rem;';
    }

    /**
     * Full-bleed heroes escape .site-shell-container via widthStyle(), so
     * the text column must re-apply the container constraint itself or the
     * copy hugs the viewport edge — and it uses the SAME gutter as the
     * inner-page hero (site/sections/hero.blade.php line ~277) so the shop
     * title starts at exactly the same x as About/Contact titles. Boxed
     * heroes are already inside the container; their wider gutter is inset
     * from the image-card edge, which is the boxed look.
     */
    public static function innerClass(string $heroWidth): string
    {
        return $heroWidth === 'full'
            ? 'site-shell-container px-4 sm:px-6 lg:px-8'
            : self::gutterClass();
    }

    public static function widthClass(string $heroWidth): string
    {
        return $heroWidth === 'full' ? 'shop-hero-full' : '';
    }

    /**
     * Inline style for the full-bleed breakout. Double negative side margins
     * stretch the section to the VIEWPORT edges (escaping both the px wrapper
     * and .site-shell-container's max-width) without the 100vw scrollbar
     * problem. $flush additionally cancels the wrapper's py-6 top padding so
     * the hero sits flush under the site header — used on both the storefront
     * index and category pages, since the breadcrumb renders BELOW the hero on
     * both surfaces. Boxed heroes never get the flush margin; only full-width
     * ones escape the wrapper padding.
     */
    public static function widthStyle(string $heroWidth, bool $flush = false): string
    {
        if ($heroWidth !== 'full') {
            return '';
        }

        $style = ' max-width: none; margin-left: calc(50% - 50vw); margin-right: calc(50% - 50vw);';

        return $flush ? $style.' margin-top: -1.5rem;' : $style;
    }

    public static function isEnabled(mixed $enabled): bool
    {
        if ($enabled === null) {
            return true;
        }

        return filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
    }

    public static function paddingClass(string $heroHeight, string $size = 'shop'): string
    {
        if ($size === 'category') {
            return match ($heroHeight) {
                'small' => 'py-14 md:py-16',
                'large' => 'py-28 md:py-40 lg:py-48',
                default => 'py-20 md:py-28 lg:py-36',
            };
        }

        return match ($heroHeight) {
            'small' => 'py-16 md:py-20',
            'large' => 'py-32 md:py-48 lg:py-56',
            default => 'py-24 md:py-36 lg:py-44',
        };
    }

    private static function column(string $textZone): string
    {
        return self::parts($textZone)[1];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function parts(string $textZone): array
    {
        $parts = array_pad(explode('-', $textZone), 2, 'left');

        return [(string) $parts[0], (string) $parts[1]];
    }
}
