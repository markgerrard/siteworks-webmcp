<?php

namespace App\Support\Postcode;

final class PassthroughPostcodeNormaliser implements PostcodeNormaliser
{
    public function normalise(string $postcode): string
    {
        return strtoupper(preg_replace('/\s+/', '', $postcode) ?? '');
    }

    public function outwardCode(string $normalised): string
    {
        return $normalised;
    }

    public function isValid(string $normalised): bool
    {
        return $normalised !== '';
    }
}
