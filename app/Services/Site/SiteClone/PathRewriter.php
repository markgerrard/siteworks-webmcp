<?php

namespace App\Services\Site\SiteClone;

class PathRewriter
{
    public static function rewrite(string $value, int $srcId, int $newId, string $sourcePrefix, string $destPrefix): string
    {
        $value = self::swapPrefix($value, $sourcePrefix, $destPrefix);

        $roots = array_values(array_unique(array_filter([
            $sourcePrefix,
            $destPrefix,
            'sites',
            'site-media',
        ])));

        foreach ($roots as $root) {
            $value = str_replace("{$root}/{$srcId}/", "{$root}/{$newId}/", $value);
            $value = str_replace("{$root}\\/{$srcId}\\/", "{$root}\\/{$newId}\\/", $value);
        }

        return $value;
    }

    private static function swapPrefix(string $value, string $sourcePrefix, string $destPrefix): string
    {
        if ($sourcePrefix === '' || $sourcePrefix === $destPrefix) {
            return $value;
        }

        $value = str_replace($sourcePrefix.'/', $destPrefix.'/', $value);

        return str_replace($sourcePrefix.'\\/', $destPrefix.'\\/', $value);
    }
}
