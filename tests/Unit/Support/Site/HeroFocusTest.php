<?php

use App\Support\Site\HeroFocus;

it('returns an explicit override over site default and auto derivation', function (string $override, string $expected) {
    expect(HeroFocus::resolve($override, 'fill', 'panel-left', 'middle-center'))->toBe($expected);
})->with([
    'left' => ['left', 'left'],
    'centre' => ['centre', 'centre'],
    'right' => ['right', 'right'],
    'fill' => ['fill', 'fill'],
]);

it('uses the site default when override is absent or auto', function (?string $override, string $siteDefault, string $expected) {
    expect(HeroFocus::resolve($override, $siteDefault, 'panel-left', 'middle-center'))->toBe($expected);
})->with([
    'null override, site left' => [null, 'left', 'left'],
    'null override, site centre' => [null, 'centre', 'centre'],
    'null override, site right' => [null, 'right', 'right'],
    'null override, site fill' => [null, 'fill', 'fill'],
    'auto override, site right' => ['auto', 'right', 'right'],
]);

it('derives fill from auto when the variant is panel or boxed', function (?string $variant) {
    expect(HeroFocus::resolve(null, null, $variant, 'middle-left'))->toBe('fill')
        ->and(HeroFocus::resolve('auto', 'auto', $variant, 'top-right'))->toBe('fill');
})->with(['panel-left', 'boxed-left']);

it('derives the copy column from text_zone when auto and not panel/boxed', function (?string $variant, string $textZone, string $expected) {
    expect(HeroFocus::resolve(null, null, $variant, $textZone))->toBe($expected);
})->with([
    'null variant, left' => [null, 'middle-left', 'left'],
    'null variant, centre' => [null, 'middle-center', 'centre'],
    'null variant, right' => [null, 'top-right', 'right'],
    'unknown variant, left' => ['venue', 'bottom-left', 'left'],
]);

it('treats a nullable site default as auto', function () {
    expect(HeroFocus::resolve(null, null, null, null))->toBe('left');
});

it('drops invalid values and falls through', function () {
    expect(HeroFocus::resolve('center', 'fill', null, 'middle-left'))->toBe('fill')
        ->and(HeroFocus::resolve('nope', 'bogus', 'panel-left', 'middle-right'))->toBe('fill')
        ->and(HeroFocus::resolve('', '', null, 'middle-center'))->toBe('centre');
});

it('override beats site, site beats auto derivation', function () {
    expect(HeroFocus::resolve('right', 'fill', 'panel-left', 'middle-center'))->toBe('right')
        ->and(HeroFocus::resolve(null, 'left', 'panel-left', 'middle-center'))->toBe('left')
        ->and(HeroFocus::resolve('auto', 'auto', null, 'middle-right'))->toBe('right');
});

it('composition coordinate threshold uses the resolved region per hero_focus', function (?string $focus, mixed $x, bool $fails) {
    expect(HeroFocus::compositionCoordinateFails($focus, $x))->toBe($fails);
})->with([
    'left x=0.25 fails' => ['left', 0.25, true],
    'left x=0.39 fails' => ['left', 0.39, true],
    'left x=0.40 passes' => ['left', 0.40, false],
    'left x=0.65 passes' => ['left', 0.65, false],
    'null focus is left, x=0.25 fails' => [null, 0.25, true],
    'right x=0.25 passes' => ['right', 0.25, false],
    'right x=0.60 passes' => ['right', 0.60, false],
    'right x=0.61 fails' => ['right', 0.61, true],
    'right x=0.90 fails' => ['right', 0.90, true],
    'centre x=0.20 passes' => ['centre', 0.20, false],
    'centre x=0.33 fails' => ['centre', 0.33, true],
    'centre x=0.50 fails' => ['centre', 0.50, true],
    'centre x=0.66 fails' => ['centre', 0.66, true],
    'centre x=0.80 passes' => ['centre', 0.80, false],
    'fill x=0.10 never fails' => ['fill', 0.10, false],
    'fill x=0.50 never fails' => ['fill', 0.50, false],
    'fill x=0.90 never fails' => ['fill', 0.90, false],
    'fill missing x never fails' => ['fill', null, false],
]);
