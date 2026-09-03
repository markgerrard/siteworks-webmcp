<?php

use App\Support\Site\HeroSizing;

it('does not compact when height is absent', function (?string $height) {
    expect(HeroSizing::compactFor($height))->toBeFalse();
})->with([
    'null' => [null],
    'empty' => [''],
]);

it('compacts viewport heights at or below 45', function (string $height) {
    expect(HeroSizing::compactFor($height))->toBeTrue();
})->with(['30vh', '45vh', '30svh', '45svh', '30dvh', '45dvh']);

it('does not compact viewport heights above 45', function () {
    expect(HeroSizing::compactFor('46vh'))->toBeFalse();
});

it('compacts pixel heights at or below 450', function () {
    expect(HeroSizing::compactFor('450px'))->toBeTrue();
});

it('does not compact pixel heights above 450', function () {
    expect(HeroSizing::compactFor('451px'))->toBeFalse();
});

it('rejects calc expressions and garbage', function (string $height) {
    expect(HeroSizing::compactFor($height))->toBeFalse();
})->with([
    'calc(30vh + 10px)',
    'calc(40vh)',
    'tall',
    '45',
    '45VH',
    '30 vh',
]);
