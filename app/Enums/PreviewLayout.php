<?php

namespace App\Enums;

enum PreviewLayout: string
{
    case OnePage = 'one_page';
    case MultiPage = 'multi_page';

    public function label(): string
    {
        return match ($this) {
            self::OnePage => 'Single-page scroll',
            self::MultiPage => 'Separate pages',
        };
    }
}
