<?php

namespace App\Enums;

enum ProjectsLayout: string
{
    case Grid = 'grid';
    case CaseStudies = 'case_studies';

    public function label(): string
    {
        return match ($this) {
            self::Grid => 'Tile grid',
            self::CaseStudies => 'Case studies',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Grid => 'Compact 2- or 3-column gallery of project tiles. Best when projects are visually distinct and you have many to show.',
            self::CaseStudies => 'Long-form narrative blocks per project with descriptive copy and tag chips. Best when each project has a story worth telling.',
        };
    }
}
