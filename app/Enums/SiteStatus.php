<?php

namespace App\Enums;

enum SiteStatus: string
{
    case Draft = 'draft';
    case Scraping = 'scraping';
    case Profiling = 'profiling';
    case Generating = 'generating';
    case Building = 'building';
    case Review = 'review';
    case Published = 'published';
    case Failed = 'failed';
}
