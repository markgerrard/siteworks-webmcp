<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Shrinking allow-list of today's hard-coded colour in shop views.
 * One entry per file+pattern. Later page tasks delete the lines they
 * remove. A rule that starts red and shrinks is enforceable; one bolted
 * on at the end is negotiable.
 *
 * New T4/T5 components must add zero entries.
 *
 * @return array<string, list<string>>
 */
function shopColourAllowList(): array
{
    return [];
}

/**
 * Palette utilities plus bare white/black. Matches the v2 T5 pattern,
 * scoped to class tokens so hover:bg-white still counts.
 *
 * @return list<string>
 */
function shopColourPaletteViolations(string $contents): array
{
    $found = [];

    preg_match_all(
        '/(?<![A-Za-z0-9-])((?:bg|text|border|ring|divide|from|via|to)-(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d{2,3})\b/',
        $contents,
        $palette,
    );
    foreach ($palette[1] as $token) {
        $found[$token] = true;
    }

    preg_match_all(
        '/(?<![A-Za-z0-9-])(bg-white|bg-black|text-white|text-black)\b/',
        $contents,
        $bare,
    );
    foreach ($bare[1] as $token) {
        $found[$token] = true;
    }

    return array_keys($found);
}

/**
 * Hex only as a CSS value or class token (`: #fff`, `="#fff"`, `[#fff]`).
 * Bare `#[0-9a-f]{3,6}` would match `&#039;` (an escaped apostrophe).
 *
 * @return list<string>
 */
function shopColourHexViolations(string $contents): array
{
    preg_match_all(
        '/(?::\s*|=\s*[\'"]\s*|\[)#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})\b/',
        $contents,
        $matches,
    );

    $found = [];
    foreach ($matches[0] as $token) {
        $found[$token] = true;
    }

    return array_keys($found);
}

/**
 * @return array<string, list<string>>
 */
function shopColourScan(): array
{
    $roots = [
        resource_path('views/shop'),
        resource_path('views/components/shop'),
    ];

    $exempt = resource_path('views/components/shop/layout.blade.php');
    $found = [];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        foreach (File::allFiles($root) as $file) {
            if ($file->getPathname() === $exempt) {
                continue;
            }

            $relative = Str::of($file->getPathname())
                ->after(base_path().DIRECTORY_SEPARATOR)
                ->replace('\\', '/')
                ->toString();

            $contents = File::get($file->getPathname());
            $tokens = shopColourPaletteViolations($contents);
            foreach (shopColourHexViolations($contents) as $hex) {
                $tokens[] = 'hex:'.$hex;
            }

            sort($tokens);
            if ($tokens !== []) {
                $found[$relative] = array_values(array_unique($tokens));
            }
        }
    }

    ksort($found);

    return $found;
}

test('shop views have no unallowlisted Tailwind palette colour or CSS hex', function () {
    $found = shopColourScan();
    $allowed = shopColourAllowList();

    $unexpected = [];
    foreach ($found as $file => $tokens) {
        $permitted = $allowed[$file] ?? [];
        $extra = array_values(array_diff($tokens, $permitted));
        if ($extra !== []) {
            $unexpected[$file] = $extra;
        }
    }

    $stale = [];
    foreach ($allowed as $file => $tokens) {
        $actual = $found[$file] ?? [];
        $unused = array_values(array_diff($tokens, $actual));
        if ($unused !== []) {
            $stale[$file] = $unused;
        }
        if ($actual === []) {
            $stale[$file] = $tokens;
        }
    }

    expect($unexpected)->toBe([])
        ->and($stale)->toBe([]);
});

test('the shop layout is the only file exempt from the colour scan', function () {
    $layout = resource_path('views/components/shop/layout.blade.php');
    expect(File::exists($layout))->toBeTrue();

    $found = shopColourScan();
    expect($found)->not->toHaveKey('resources/views/components/shop/layout.blade.php');
});
