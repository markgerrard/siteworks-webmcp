<?php

namespace App\Enums;

enum GenerationMode: string
{
    case ProspectLed = 'prospect_led';
    case CompetitorLed = 'competitor_led';
    case Hybrid = 'hybrid';
    case NoSite = 'no_site';
}
