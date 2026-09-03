<?php

namespace App\Support\Postcode;

final class PostcodeNormaliserFactory
{
    public function forCountry(string $countryCode): PostcodeNormaliser
    {
        return strtoupper($countryCode) === 'GB'
            ? new GbPostcodeNormaliser
            : new PassthroughPostcodeNormaliser;
    }
}
