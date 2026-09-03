<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;

/**
 * Blade's statement regex is `\B@directive`: it compiles a directive only when the
 * `@` follows a NON-word character. `class="py-12@if(...)"` therefore ships the
 * literal text `@if(...)` to the customer inside the class attribute (a fixture re-seed
 * once baked that leak into every
 * service-page byte fixture). Sibling test BladeDirectiveCompilationTest guards the
 * tag-name form; this one proves, at compiler level, that no control directive
 * survives compilation anywhere in the public-site views.
 */
test('no control directive survives compilation in the public-site views', function () {
    $leaks = [];

    foreach (File::allFiles(resource_path('views/site')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        // Directives compile to PHP open/close blocks; anything left outside those
        // blocks is literal output. Comments inside PHP blocks may legitimately
        // mention @if, so strip the blocks before scanning.
        $compiled = Blade::compileString($file->getContents());
        $literal = preg_replace('/<\?php.*?\?>/s', '', $compiled) ?? $compiled;

        if (preg_match_all('/@(if|elseif|else|endif|unless|endunless|foreach|endforeach|forelse|endforelse|isset|endisset|empty|endempty)\b/', $literal, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$text, $offset]) {
                $leaks[] = $file->getRelativePathname().': '.trim(substr($literal, max(0, $offset - 40), 90));
            }
        }
    }

    expect($leaks)->toBe([]);
});
