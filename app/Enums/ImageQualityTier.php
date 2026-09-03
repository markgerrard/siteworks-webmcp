<?php

namespace App\Enums;

enum ImageQualityTier: string
{
    case Preview = 'preview';
    case Production = 'production';
}
