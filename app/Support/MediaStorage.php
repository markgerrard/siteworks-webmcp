<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Dedicated disk for generated and uploaded media.
 *
 * Resolves independently of the app-wide default filesystem disk so a
 * FILESYSTEM_DISK regression cannot silently park new images on
 * container-local disk.
 *
 * Media on {@see self::disk()} is addressed by public URL. Private media
 * (customer-supplied files served only through signed, authorised routes)
 * goes through {@see self::privateDisk()}, which is the same disk unless
 * filesystems.media_private names one that is never served statically.
 */
class MediaStorage
{
    public static function diskName(): string
    {
        return (string) config('filesystems.media', 's3');
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(self::diskName());
    }

    public static function privateDiskName(): string
    {
        $name = config('filesystems.media_private');

        return is_string($name) && $name !== '' ? $name : self::diskName();
    }

    public static function privateDisk(): Filesystem
    {
        return Storage::disk(self::privateDiskName());
    }
}
