<?php

namespace App\Enums\Shop;

enum ProductReviewSource: string
{
    case Seed = 'seed';
    case Manual = 'manual';
    case Shopper = 'shopper';
    case Invited = 'invited';
}
