<?php

namespace App\Enums;

enum LogoConceptSource: string
{
    case Detected = 'detected';
    case Generated = 'generated';
    case Redraw = 'redraw';
    case Trace = 'trace';
    case Manual = 'manual';
    case Uploaded = 'uploaded';

    public function label(): string
    {
        return match ($this) {
            self::Detected => 'Detected',
            self::Generated => 'AI Concept',
            self::Redraw => 'Detected + Redrawn',
            self::Trace => 'Detected + Traced',
            self::Manual => 'Manual',
            self::Uploaded => 'Uploaded',
        };
    }
}
