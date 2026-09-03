<?php

namespace App\Support\Site;

use Illuminate\Support\Facades\Storage;

final class SitePublicObject
{
    public static function put(int $siteId, string $folder, string $filename, string $contents): string
    {
        $path = sprintf('sites/%d/%s/%s', $siteId, $folder, $filename);
        Storage::disk('s3')->put($path, $contents, 'public');

        return $path;
    }

    public static function url(?string $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        return Storage::disk('s3')->url($path);
    }
}
