<?php

namespace App\Support\Site;

final class HeroSizing
{
    /**
     * True when a scene height override is small enough that the default
     * full-bleed padding (py-28 md:py-36 lg:py-40) would consume the box.
     */
    public static function compactFor(?string $height): bool
    {
        if ($height === null || $height === '') {
            return false;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)(vh|svh|dvh|px)$/', $height, $matches) !== 1) {
            return false;
        }

        $magnitude = (float) $matches[1];
        $unit = $matches[2];

        if (in_array($unit, ['vh', 'svh', 'dvh'], true)) {
            return $magnitude <= 45;
        }

        return $magnitude <= 450;
    }
}
