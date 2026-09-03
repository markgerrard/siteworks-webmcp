<?php

namespace App\Support\Media;

enum MediaKind: string
{
    case Image = 'image';
    case Video = 'video';
    case Document = 'document';

    public static function fromMime(?string $mime): self
    {
        $normalised = strtolower(trim((string) $mime));

        if ($normalised === '' || str_starts_with($normalised, 'image/')) {
            return self::Image;
        }

        if (str_starts_with($normalised, 'video/')) {
            return self::Video;
        }

        return self::Document;
    }
}
