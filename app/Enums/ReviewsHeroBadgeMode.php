<?php

namespace App\Enums;

/**
 * Where the header-area trust elements (desktop hero pill + mobile
 * reviews_badge strip) may render for a site.
 */
enum ReviewsHeroBadgeMode: string
{
    case On = 'on';
    case Off = 'off';
    case HomeOnly = 'home_only';

    public function label(): string
    {
        return match ($this) {
            self::On => 'On',
            self::Off => 'Off',
            self::HomeOnly => 'Home only',
        };
    }

    /** Should the badge/pill show on a page of the given type? */
    public function allowsPage(string $pageType): bool
    {
        return match ($this) {
            self::On => true,
            self::Off => false,
            self::HomeOnly => $pageType === 'home',
        };
    }
}
