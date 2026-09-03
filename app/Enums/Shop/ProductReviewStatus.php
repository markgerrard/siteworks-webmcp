<?php

namespace App\Enums\Shop;

enum ProductReviewStatus: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Hidden = 'hidden';
}
