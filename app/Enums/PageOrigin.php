<?php

namespace App\Enums;

enum PageOrigin: string
{
    case Pipeline = 'pipeline';
    case Managed = 'managed';
    case Imported = 'imported';

    public function label(): string
    {
        return match ($this) {
            self::Pipeline => 'Pipeline',
            self::Managed => 'Managed',
            self::Imported => 'Imported',
        };
    }
}
