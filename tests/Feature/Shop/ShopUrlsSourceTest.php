<?php

/**
 * Cheap leftover-literal detector: full `/shop/p/` / `/shop/c/` plus
 * concatenated fragments like `'/shop/p'.'/'` or `"/shop/c"+"/"`.
 */
function shopLegacyStorefrontNeedle(string $text): bool
{
    if (str_contains($text, '/shop/p/') || str_contains($text, '/shop/c/')) {
        return true;
    }

    return preg_match('/[\'"]\/shop\/[pc][\'"]\s*[\.\+]\s*[\'"]\//', $text) === 1;
}

test('legacy URL detection catches concatenated /shop/p and /shop/c fragments', function () {
    expect(shopLegacyStorefrontNeedle("'/shop/p'.'/'"))->toBeTrue()
        ->and(shopLegacyStorefrontNeedle('"/shop/c"+"/"'))->toBeTrue()
        ->and(shopLegacyStorefrontNeedle("'/shop/p' . '/'"))->toBeTrue()
        ->and(shopLegacyStorefrontNeedle('ShopUrls::product($slug)'))->toBeFalse()
        ->and(shopLegacyStorefrontNeedle('/products/lilac-vintage-ribbon-cake'))->toBeFalse();
});

test('app, views and js do not hardcode legacy /shop/p/ or /shop/c/ URLs outside redirect routes', function () {
    $roots = [
        base_path('app'),
        base_path('resources/views'),
        base_path('resources/js'),
        base_path('routes/site-public.php'),
    ];
    $legacy = [];

    $iterator = function (string $path) use (&$iterator, &$legacy): void {
        if (is_file($path)) {
            $contents = (string) file_get_contents($path);
            if (! shopLegacyStorefrontNeedle($contents)) {
                return;
            }
            foreach (explode("\n", $contents) as $number => $line) {
                if (! shopLegacyStorefrontNeedle($line)) {
                    continue;
                }
                $relative = str_replace(base_path().'/', '', $path);
                if ($relative === 'routes/site-public.php' && (
                    str_contains($line, "Route::get('/shop/p/{slug}'")
                    || str_contains($line, "Route::get('/shop/c/{path}'")
                )) {
                    continue;
                }
                $legacy[] = $relative.':'.($number + 1).': '.trim($line);
            }

            return;
        }

        foreach (scandir($path) ?: [] as $child) {
            if ($child === '.' || $child === '..') {
                continue;
            }
            $iterator($path.DIRECTORY_SEPARATOR.$child);
        }
    };

    foreach ($roots as $root) {
        $iterator($root);
    }

    expect($legacy)->toBeEmpty('legacy storefront URL literals remain:'.PHP_EOL.implode(PHP_EOL, $legacy));
});
