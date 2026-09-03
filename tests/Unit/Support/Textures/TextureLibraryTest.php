<?php

use App\Support\Textures\TextureLibrary;

const PLUS_TILE_PATH = 'M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z';

/**
 * @return list<string>
 */
function expectedTextureKeys(): array
{
    return [
        'plus',
        'dots',
        'grid',
        'diagonal-hatch',
        'herringbone',
        'waves',
        'topography',
        'sprig',
        'noise',
        'none',
    ];
}

function decodeTextureSvg(string $uri): string
{
    expect($uri)->toStartWith('data:image/svg+xml,');

    return rawurldecode(substr($uri, strlen('data:image/svg+xml,')));
}

test('the library exposes exactly ten texture keys', function () {
    expect(TextureLibrary::keys())->toBe(expectedTextureKeys())
        ->and(TextureLibrary::all())->toHaveCount(10);
});

test('each library entry carries key, svg, default_opacity and default_size', function () {
    foreach (TextureLibrary::all() as $key => $entry) {
        expect($entry)->toHaveKeys(['key', 'svg', 'default_opacity', 'default_size'])
            ->and($entry['key'])->toBe($key)
            ->and($entry['default_opacity'])->toBeFloat()
            ->and($entry['default_size'])->toBeInt();
    }
});

test('svg entries are parseable data-URI tiles with currentColor-equivalent fill', function () {
    foreach (TextureLibrary::all() as $key => $entry) {
        if ($key === 'none') {
            expect($entry['svg'])->toBeNull();

            continue;
        }

        expect($entry['svg'])->toBeString();
        $svg = decodeTextureSvg($entry['svg']);
        $xml = simplexml_load_string($svg);

        expect($xml)->not->toBeFalse()
            ->and($svg)->not->toContain('#fff')
            ->and($svg)->not->toContain('#FFF')
            ->and($svg)->not->toContain('#ffffff')
            ->and($svg)->not->toContain('%23fff')
            ->and($svg)->not->toContain('%23FFF')
            ->and($svg)->not->toContain('%23ffffff');
    }
});

test('default opacities sit in the tasteful range, with noise allowed higher', function () {
    foreach (TextureLibrary::all() as $key => $entry) {
        $opacity = $entry['default_opacity'];

        if ($key === 'none') {
            expect($opacity)->toBe(0.0);

            continue;
        }

        if ($key === 'noise') {
            expect($opacity)->toBeGreaterThanOrEqual(0.25)
                ->and($opacity)->toBeLessThanOrEqual(0.45);

            continue;
        }

        expect($opacity)->toBeGreaterThanOrEqual(0.04)
            ->and($opacity)->toBeLessThanOrEqual(0.08);
    }
});

test('plus keeps the current 60px tile path byte-for-byte', function () {
    $plus = TextureLibrary::get('plus');

    expect($plus)->not->toBeNull()
        ->and($plus['default_size'])->toBe(60)
        ->and($plus['default_opacity'])->toBe(0.05);

    $svg = decodeTextureSvg($plus['svg']);
    $xml = simplexml_load_string($svg);
    $paths = $xml->xpath('//*[local-name()="path"]');

    expect($paths)->not->toBeEmpty()
        ->and((string) $paths[0]['d'])->toBe(PLUS_TILE_PATH);
});

test('natural tile sizes match the authored personalities', function () {
    expect(TextureLibrary::get('dots')['default_size'])->toBe(24)
        ->and(TextureLibrary::get('grid')['default_size'])->toBe(32)
        ->and(TextureLibrary::get('diagonal-hatch')['default_size'])->toBe(12)
        ->and(TextureLibrary::get('herringbone')['default_size'])->toBe(28)
        ->and(TextureLibrary::get('waves')['default_size'])->toBe(80)
        ->and(TextureLibrary::get('topography')['default_size'])->toBe(120)
        ->and(TextureLibrary::get('sprig')['default_size'])->toBe(90)
        ->and(TextureLibrary::get('noise')['default_size'])->toBe(128)
        ->and(TextureLibrary::get('none')['default_size'])->toBe(0);
});

test('unknown keys are not in the library', function () {
    expect(TextureLibrary::has('swirl'))->toBeFalse()
        ->and(TextureLibrary::get('swirl'))->toBeNull()
        ->and(TextureLibrary::has('plus'))->toBeTrue();
});
