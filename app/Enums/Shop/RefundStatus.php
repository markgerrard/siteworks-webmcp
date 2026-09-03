<?php

namespace App\Enums\Shop;

enum RefundStatus: string
{
    case None = 'none';
    case Partial = 'partial';
    case Full = 'full';
}
