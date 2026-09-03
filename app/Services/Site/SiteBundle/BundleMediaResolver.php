<?php

namespace App\Services\Site\SiteBundle;

use App\Support\MediaStorage;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves a stored value (a full URL, or a bare disk-relative key) that
 * *might* reference a file on a given disk, down to that disk-relative
 * key — or null if it plainly isn't one.
 */
class BundleMediaResolver
{
    public static function diskName(string $resolver): string
    {
        return $resolver === 'media' ? MediaStorage::diskName() : $resolver;
    }

    /**
     * @return string|null the disk-relative key, or null if $value doesn't
     *                      resolve to a file that exists on $disk
     */
    public static function relativeKey(?string $value, string $disk): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $key = $value;

        $base = self::diskBaseUrl($disk);
        if ($base !== null && $base !== '' && str_starts_with($value, $base)) {
            $key = ltrim(substr($value, strlen($base)), '/');
        } elseif (preg_match('#^https?://#i', $value)) {
            // Looks like a URL but not on this disk's base — not ours.
            return null;
        }

        try {
            return Storage::disk($disk)->exists($key) ? $key : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function diskBaseUrl(string $disk): ?string
    {
        try {
            return rtrim(Storage::disk($disk)->url(''), '/');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Recursively collect every string leaf value out of a decoded
     * JSON/array structure, for scanning as candidate media references.
     *
     * @param  mixed  $value
     * @return list<string>
     */
    public static function collectStrings(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $v) {
                $out = [...$out, ...self::collectStrings($v)];
            }

            return $out;
        }

        return [];
    }
}
