<?php

namespace App\Support\Media;

enum MediaOrigin: string
{
    case Generated = 'generated';
    case Uploaded = 'uploaded';
    case Imported = 'imported';

    public static function fromSource(string $source, mixed $llmCallId = null): self
    {
        if ($llmCallId !== null && $llmCallId !== '') {
            return self::Generated;
        }

        $normalised = strtolower(trim($source));

        if (in_array($normalised, ['zion', 'facebook'], true)) {
            return self::Imported;
        }

        if (in_array($normalised, ['ai_generated', 'generated'], true)) {
            return self::Generated;
        }

        return self::Uploaded;
    }
}
