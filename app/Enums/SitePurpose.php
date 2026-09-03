<?php

namespace App\Enums;

/**
 * What the site row exists FOR. 'website' is the product; 'video_only'
 * rows exist purely to feed the VideoWorks promo pipeline (profile,
 * theme, logo, media) for businesses we are not building a site for —
 * they must never publish, take a domain, or be touched by schedulers.
 */
enum SitePurpose: string
{
    case Website = 'website';
    case VideoOnly = 'video_only';
}
