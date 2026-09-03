<?php

namespace App\Services\Site;

use App\Models\Site;
use App\Support\ChromeKnobs;

final class FormChrome
{
    public const BOXED_INPUT = 'w-full rounded-md border border-gray-300 px-4 py-2.5 text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:border-transparent transition-shadow';

    public const LEAD_BOXED_INPUT = 'w-full px-4 py-2.5 rounded-md border border-gray-300 text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:border-transparent transition-shadow';

    public const BOXED_SELECT = self::BOXED_INPUT;

    public const LEAD_BOXED_SELECT = 'w-full px-4 py-2.5 rounded-md border border-gray-300 text-gray-900 bg-white focus:outline-none focus:ring-2 focus:border-transparent transition-shadow';

    public const BOXED_LABEL = 'block text-sm font-semibold text-gray-700 mb-1.5';

    public const BOXED_RADIO_OPTION = 'flex items-center gap-2 text-sm text-gray-700';

    public const LEAD_BOXED_RADIO_OPTION = 'flex items-center gap-2 px-3 py-2 rounded-md border border-gray-300 cursor-pointer hover:bg-gray-50 transition-colors';

    public const UNDERLINE_INPUT = 'w-full bg-transparent border-0 border-b border-gray-500 px-0 py-2.5 text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-0 focus:[border-bottom-color:var(--brand-accent)] transition-colors';

    public const UNDERLINE_LABEL = 'block text-xs font-semibold uppercase tracking-[0.18em] text-gray-700 mb-1.5';

    public const UNDERLINE_RADIO_OPTION = 'inline-flex items-center gap-2 py-1';

    public const SOFT_INPUT = 'w-full rounded-md border-0 px-4 py-2.5 text-gray-900 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-[var(--brand-accent)] [background-color:color-mix(in_oklab,var(--color-band)_8%,white)]';

    public const LEAD_SOFT_INPUT = self::SOFT_INPUT;

    public const LEAD_SOFT_SELECT = self::SOFT_INPUT.' appearance-none';

    public const SOFT_RADIO_OPTION = 'flex items-center gap-2 px-3 py-2 rounded-md cursor-pointer transition-colors [background-color:color-mix(in_oklab,var(--color-band)_8%,white)] hover:[background-color:color-mix(in_oklab,var(--color-band)_14%,white)]';

    public const SOFT_INPUT_DARK = 'w-full rounded-md border-0 px-4 py-2.5 text-white placeholder-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-accent)] bg-white/[0.12]';

    public const UNDERLINE_INPUT_DARK = 'w-full bg-transparent border-0 border-b border-white/70 px-0 py-2.5 text-white placeholder-white focus:outline-none focus:ring-0 focus:[border-bottom-color:var(--brand-accent)]';

    public const UNDERLINE_LABEL_DARK = 'block text-xs font-semibold uppercase tracking-[0.18em] text-white mb-1.5';

    public const BOXED_INPUT_DARK = 'w-full rounded-md border border-white/70 bg-white/[0.12] px-4 py-2.5 text-white placeholder-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-accent)] focus:border-transparent';

    public const BOXED_LABEL_DARK = 'block text-sm font-semibold text-white mb-1.5';

    public const RADIO_OPTION_DARK = 'flex items-center gap-2 px-3 py-2 rounded-md border border-white/70 bg-white/[0.12] text-white cursor-pointer transition-colors hover:bg-white/20';

    public const LEAD_RADIO_SEGMENT = 'flex items-center gap-2 px-3 py-2 border border-gray-300 cursor-pointer first:rounded-l-md last:rounded-r-md -ml-px first:ml-0 hover:bg-gray-50 transition-colors';

    public const LEAD_RADIO_SEGMENT_DARK = 'flex items-center gap-2 px-3 py-2 border border-white/70 text-white cursor-pointer first:rounded-l-md last:rounded-r-md -ml-px first:ml-0 hover:bg-white/20 transition-colors';

    public const LEAD_RADIO_TILE = 'flex flex-col items-start gap-1 px-4 py-3 rounded-md border border-gray-300 cursor-pointer hover:bg-gray-50 transition-colors';

    public const LEAD_RADIO_TILE_DARK = 'flex flex-col items-start gap-1 px-4 py-3 rounded-md border border-white/70 text-white cursor-pointer hover:bg-white/20 transition-colors';

    public const ERROR_LIGHT = 'text-sm text-red-600';

    // text-red-50 / #fef2f2: text-red-300 (#fca5a5) is 2.64:1 on local-friendly primary #15803d; red-50 is the lightest default red that still clears 4.5:1 there.
    public const ERROR_DARK = 'text-sm text-red-50';

    public const SUBMIT_FULL = 'w-full px-6 py-3.5 rounded-md font-bold shadow-md transition-all hover:shadow-lg hover:brightness-110 disabled:opacity-60';

    public const SUBMIT_AUTO_ARROW = 'inline-flex items-center justify-center gap-2 w-full md:w-auto md:ml-auto px-6 py-3.5 rounded-md font-bold shadow-md transition-all hover:shadow-lg hover:brightness-110 disabled:opacity-60';

    public const SUBMIT_AUTO = 'inline-flex items-center justify-center w-full md:w-auto px-8 py-3.5 rounded-md text-sm font-bold uppercase tracking-[0.12em] shadow-md transition-all hover:shadow-lg hover:brightness-110 disabled:opacity-60';

    private static function resolvedStyle(?Site $site, ?string $style): string
    {
        if ($style !== null) {
            return $style;
        }

        return $site !== null ? ChromeKnobs::formStyle($site) : 'boxed';
    }

    private static function isDark(?string $surface): bool
    {
        return $surface === 'panel-inverted';
    }

    public static function inputClass(?Site $site = null, string $family = 'contact', ?string $style = null, ?string $surface = null): string
    {
        $style = self::resolvedStyle($site, $style);
        if (self::isDark($surface)) {
            return match ($style) {
                'underline' => self::UNDERLINE_INPUT_DARK,
                'soft-filled' => self::SOFT_INPUT_DARK,
                default => self::BOXED_INPUT_DARK,
            };
        }
        if ($style === 'underline') {
            return self::UNDERLINE_INPUT;
        }
        if ($style === 'soft-filled') {
            return $family === 'lead' ? self::LEAD_SOFT_INPUT : self::SOFT_INPUT;
        }

        return $family === 'lead' ? self::LEAD_BOXED_INPUT : self::BOXED_INPUT;
    }

    public static function labelClass(?Site $site = null, ?string $style = null, ?string $surface = null): string
    {
        $style = self::resolvedStyle($site, $style);
        if (self::isDark($surface)) {
            return $style === 'underline' ? self::UNDERLINE_LABEL_DARK : self::BOXED_LABEL_DARK;
        }

        return $style === 'underline' ? self::UNDERLINE_LABEL : self::BOXED_LABEL;
    }

    public static function selectClass(?Site $site = null, string $family = 'contact', ?string $style = null, ?string $surface = null): string
    {
        $style = self::resolvedStyle($site, $style);
        if (self::isDark($surface)) {
            return ($style === 'underline' ? self::UNDERLINE_INPUT_DARK : ($style === 'soft-filled' ? self::SOFT_INPUT_DARK : self::BOXED_INPUT_DARK)).' appearance-none [&>option]:text-gray-900';
        }
        if ($style === 'underline') {
            return self::UNDERLINE_INPUT.' appearance-none';
        }
        if ($style === 'soft-filled') {
            return self::LEAD_SOFT_SELECT;
        }

        return $family === 'lead' ? self::LEAD_BOXED_SELECT : self::BOXED_SELECT;
    }

    public static function radioOptionClass(?Site $site = null, string $family = 'contact', ?string $style = null, ?string $surface = null, ?string $radioStyle = null): string
    {
        $dark = self::isDark($surface);
        if ($radioStyle === 'tiles') {
            return $dark ? self::LEAD_RADIO_TILE_DARK : self::LEAD_RADIO_TILE;
        }
        if ($radioStyle === 'segmented') {
            return $dark ? self::LEAD_RADIO_SEGMENT_DARK : self::LEAD_RADIO_SEGMENT;
        }
        if ($dark) {
            return self::RADIO_OPTION_DARK;
        }
        $style = self::resolvedStyle($site, $style);
        if ($style === 'soft-filled') {
            return self::SOFT_RADIO_OPTION;
        }

        return $style === 'underline' ? self::UNDERLINE_RADIO_OPTION : ($family === 'lead' ? self::LEAD_BOXED_RADIO_OPTION : self::BOXED_RADIO_OPTION);
    }

    public static function submitClass(?string $submitStyle = null): string
    {
        return match ($submitStyle) {
            'auto-arrow' => self::SUBMIT_AUTO_ARROW,
            'auto' => self::SUBMIT_AUTO,
            default => self::SUBMIT_FULL,
        };
    }

    public static function errorClass(?string $surface = null): string
    {
        return self::isDark($surface) ? self::ERROR_DARK : self::ERROR_LIGHT;
    }
}
