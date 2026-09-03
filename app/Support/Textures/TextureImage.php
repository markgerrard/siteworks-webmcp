<?php

namespace App\Support\Textures;

use App\Models\Site;
use App\Support\MediaStorage;

final class TextureImage
{
    public static function publicUrl(Site $site, ?string $path): ?string
    {
        if (! self::isSiteMediaPath($site, $path)) {
            return null;
        }

        try {
            $disk = MediaStorage::disk();
            if (! $disk->exists($path)) {
                return null;
            }

            $url = $disk->url($path);

            return is_string($url) && $url !== '' ? $url : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function isSiteMediaPath(Site $site, mixed $path): bool
    {
        if (! is_string($path) || $path === '') {
            return false;
        }

        if (str_contains($path, '..') || str_contains($path, '\\')) {
            return false;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1) {
            return false;
        }

        $siteId = is_numeric($site->id) ? (int) $site->id : 0;
        if ($siteId < 1) {
            return false;
        }

        $prefix = 'sites/'.$siteId.'/';

        return str_starts_with($path, $prefix) && strlen($path) > strlen($prefix);
    }
}
