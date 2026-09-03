<?php

namespace App\Enums\Shop;

enum InventoryReason: string
{
    case Sale = 'sale';
    case Return = 'return';
    case Adjustment = 'adjustment';
    case Import = 'import';
    case Correction = 'correction';
}
