<?php

namespace App\Support\Postcode;

interface PostcodeNormaliser
{
    /**
     * Uppercase and strip spaces. Empty input stays empty.
     */
    public function normalise(string $postcode): string;

    /**
     * Outward code used for prefix matching. Non-GB implementations
     * return the full normalised value.
     */
    public function outwardCode(string $normalised): string;

    /**
     * Light format check. Must never throw: garbage is false, not a 500.
     */
    public function isValid(string $normalised): bool;
}
