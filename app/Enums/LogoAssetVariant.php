<?php

namespace App\Enums;

enum LogoAssetVariant: string
{
    case Selected = 'selected';
    case Overlay = 'overlay';
    case Inverted = 'inverted';
    case Transparent = 'transparent';
    case Wordmark = 'wordmark';
    case Icon = 'icon';
    case Light = 'light';
    case Dark = 'dark';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
