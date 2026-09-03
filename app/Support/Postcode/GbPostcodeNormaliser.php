<?php

namespace App\Support\Postcode;

final class GbPostcodeNormaliser implements PostcodeNormaliser
{
    public function normalise(string $postcode): string
    {
        return strtoupper(preg_replace('/\s+/', '', $postcode) ?? '');
    }

    public function outwardCode(string $normalised): string
    {
        if (strlen($normalised) >= 5) {
            return substr($normalised, 0, -3);
        }

        return $normalised;
    }

    public function isValid(string $normalised): bool
    {
        if ($normalised === '') {
            return false;
        }

        return (bool) preg_match('/^[A-Z]{1,2}[0-9][A-Z0-9]?([0-9][A-Z]{2})?$/', $normalised);
    }
}
