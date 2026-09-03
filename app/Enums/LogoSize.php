<?php

namespace App\Enums;

enum LogoSize: string
{
    case Standard = 'standard';
    case Large = 'large';
    case Compact = 'compact';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard',
            self::Large => 'Large',
            self::Compact => 'Compact',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Standard => 'Default nav logo size. SaaS / wordmark sites still auto-shrink via the saas_platform heuristic.',
            self::Large => 'About 25% taller than standard — use when the mark looks small in the sticky nav.',
            self::Compact => 'Smaller cap for wide wordmarks. Forces compact sizing even when the archetype is not saas_platform.',
        };
    }
}
