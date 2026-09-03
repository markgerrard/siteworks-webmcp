<?php

use App\Support\Postcode\GbPostcodeNormaliser;
use App\Support\Postcode\PassthroughPostcodeNormaliser;
use App\Support\Postcode\PostcodeNormaliserFactory;

test('GB normaliser uppercases and strips spaces', function () {
    $gb = new GbPostcodeNormaliser;

    expect($gb->normalise('sw1a 1aa'))->toBe('SW1A1AA')
        ->and($gb->normalise('  SW1A   1AA '))->toBe('SW1A1AA')
        ->and($gb->normalise(''))->toBe('');
});

test('GB outward code drops the inward three when the postcode is complete', function () {
    $gb = new GbPostcodeNormaliser;

    expect($gb->outwardCode('SW1A1AA'))->toBe('SW1A')
        ->and($gb->outwardCode('M11AA'))->toBe('M1')
        ->and($gb->outwardCode('SW1A'))->toBe('SW1A')
        ->and($gb->outwardCode('SW1'))->toBe('SW1');
});

test('GB validator accepts full and outward forms and rejects garbage without throwing', function () {
    $gb = new GbPostcodeNormaliser;

    expect($gb->isValid('SW1A1AA'))->toBeTrue()
        ->and($gb->isValid('SW1A'))->toBeTrue()
        ->and($gb->isValid('SW1'))->toBeTrue()
        ->and($gb->isValid('M11AA'))->toBeTrue()
        ->and($gb->isValid(''))->toBeFalse()
        ->and($gb->isValid('!!!'))->toBeFalse()
        ->and($gb->isValid('HELLO'))->toBeFalse()
        ->and($gb->isValid('12345'))->toBeFalse()
        ->and($gb->isValid('SW'))->toBeFalse();
});

test('non-GB passthrough uppercases and strips spaces and accepts whatever was typed', function () {
    $pass = new PassthroughPostcodeNormaliser;

    expect($pass->normalise('90210'))->toBe('90210')
        ->and($pass->normalise('k1a 0b1'))->toBe('K1A0B1')
        ->and($pass->outwardCode('90210'))->toBe('90210')
        ->and($pass->isValid('90210'))->toBeTrue()
        ->and($pass->isValid('!!!'))->toBeTrue()
        ->and($pass->isValid(''))->toBeFalse();
});

test('factory returns GB for GB and passthrough otherwise', function () {
    $factory = new PostcodeNormaliserFactory;

    expect($factory->forCountry('GB'))->toBeInstanceOf(GbPostcodeNormaliser::class)
        ->and($factory->forCountry('gb'))->toBeInstanceOf(GbPostcodeNormaliser::class)
        ->and($factory->forCountry('US'))->toBeInstanceOf(PassthroughPostcodeNormaliser::class)
        ->and($factory->forCountry('AU'))->toBeInstanceOf(PassthroughPostcodeNormaliser::class);
});
