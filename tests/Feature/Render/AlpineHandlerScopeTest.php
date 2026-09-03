<?php

use Illuminate\Support\Facades\File;

/**
 * The agents CSP forbids inline `on*=` event handlers, so they were converted to
 * Alpine `x-on:` directives. Alpine only processes directives inside a tree it has
 * initialised — a Livewire component root supplies that implicitly (Livewire 4
 * registers `[wire:id]` as an Alpine root selector), but a PLAIN Blade view does
 * not. Without a scope on an ancestor the handler is silently inert: no console
 * error, no visual clue, the control simply stops working.
 *
 * That is how the Sites filter bar shipped broken. The first guard asserted only
 * that no inline `on*=` handlers remained — true, while the replacement did
 * nothing. The second guard asserted `str_contains($contents, 'x-data')`, which
 * deleting the real attribute and leaving a comment that mentions `x-data`
 * defeats just as easily. Both failures had the same shape: testing the presence
 * of a string rather than the structure that matters.
 *
 * So this walks the tag stack. It strips comments first (the exact defeat above),
 * tracks which open elements provide an Alpine scope, and requires every handler to
 * have one on itself or on an ancestor. Source-level ancestry is not a browser — a
 * handler can still be scoped and wrong — but it can no longer be satisfied by a
 * string appearing somewhere in the file.
 */

/**
 * Void elements never open a subtree, so they must not be pushed onto the stack.
 */
const HTML_VOID_ELEMENTS = [
    'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
    'link', 'meta', 'param', 'source', 'track', 'wbr',
];

/**
 * Find Alpine event handlers that sit outside any Alpine scope.
 *
 * @return array<int, string> human-readable "tag at line N" descriptions
 */
function unscopedAlpineHandlers(string $contents, bool $rootIsScoped): array
{
    // An `x-data` inside a comment is not an Alpine scope. Strip both comment
    // styles before anything else — this is the assertion the first guard failed.
    $source = preg_replace('/\{\{--.*?--\}\}/s', '', $contents) ?? $contents;
    $source = preg_replace('/<!--.*?-->/s', '', $source) ?? $source;

    $tagPattern = '/<(\/?)([a-zA-Z][a-zA-Z0-9:._-]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)(\/?)>/s';
    // @env / @production / @hasSection are branches too — a scope opened inside one
    // renders only in some environments, so a handler outside it can ship inert
    // while the walker still calls it scoped.
    $bladePattern = '/@(if|unless|isset|empty|foreach|forelse|for|while|switch|auth|guest|can|cannot|canany|env|production|hasSection|sectionMissing|error|elseif|else|endif|endunless|endisset|endempty|endforeach|endforelse|endfor|endwhile|endswitch|endauth|endguest|endcan|endcannot|endcanany|endenv|endproduction|endhasSection|endsectionMissing|enderror)\b/';

    preg_match_all($tagPattern, $source, $tagMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    preg_match_all($bladePattern, $source, $bladeMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

    // Merge tags and Blade control directives into one offset-ordered stream, so the
    // walker knows which branch each element was opened in.
    $tokens = [];
    foreach ($tagMatches as $m) {
        $tokens[] = ['offset' => $m[0][1], 'kind' => 'tag', 'match' => $m];
    }
    foreach ($bladeMatches as $m) {
        $tokens[] = ['offset' => $m[0][1], 'kind' => 'blade', 'directive' => strtolower($m[1][0])];
    }
    usort($tokens, fn ($a, $b) => $a['offset'] <=> $b['offset']);

    $opens = ['if', 'unless', 'isset', 'empty', 'foreach', 'forelse', 'for', 'while', 'switch', 'auth', 'guest', 'can', 'cannot', 'canany', 'env', 'production', 'hassection', 'sectionmissing', 'error'];
    $branches = ['else', 'elseif'];

    // Each element frame remembers WHICH BRANCH it was opened in. A scope opened
    // inside `@if(...)` is not an ancestor of a handler outside that block, even
    // though a flat tag-stack walk makes it look like one:
    //
    //     @if($editable) <div x-data> @endif
    //       <button x-on:click="go()">      <-- inert on the false branch
    //     @if($editable) </div> @endif
    //
    // A flat tag-stack walk alone gets this wrong, and several real views use
    // exactly this shape. Unproven is treated as unscoped on purpose.
    $context = [];
    $branchCounter = 0;
    $stack = [];
    $offenders = [];

    $isPrefix = function (array $prefix, array $path): bool {
        if (count($prefix) > count($path)) {
            return false;
        }
        foreach ($prefix as $i => $id) {
            if (($path[$i] ?? null) !== $id) {
                return false;
            }
        }

        return true;
    };

    foreach ($tokens as $token) {
        if ($token['kind'] === 'blade') {
            $directive = $token['directive'];

            if (in_array($directive, $opens, true)) {
                $context[] = ++$branchCounter;
            } elseif (in_array($directive, $branches, true) && $context !== []) {
                array_pop($context);
                $context[] = ++$branchCounter;
            } elseif (str_starts_with($directive, 'end') && $context !== []) {
                array_pop($context);
            }

            continue;
        }

        $tag = $token['match'];
        $isClosing = $tag[1][0] === '/';
        $name = strtolower($tag[2][0]);
        $attributes = $tag[3][0];
        $isSelfClosing = $tag[4][0] === '/' || in_array($name, HTML_VOID_ELEMENTS, true);

        if ($isClosing) {
            // Pop to the matching open tag rather than blindly. A stray or
            // mismatched close otherwise desynchronises ancestry for the whole
            // rest of the file and every later handler reads as scoped.
            for ($depth = count($stack) - 1; $depth >= 0; $depth--) {
                if ($stack[$depth]['name'] === $name) {
                    $stack = array_slice($stack, 0, $depth);
                    break;
                }
            }

            continue;
        }

        $declares = declaresAlpineScope($attributes);

        $inherited = $rootIsScoped;
        foreach ($stack as $frame) {
            if ($frame['declares'] && $isPrefix($frame['context'], $context)) {
                $inherited = true;
            }
        }

        $scoped = $declares || $inherited;

        if (! $scoped && hasAlpineHandler($attributes)) {
            $line = substr_count(substr($source, 0, $token['offset']), "\n") + 1;
            $offenders[] = "<{$name}> at line {$line}";
        }

        if (! $isSelfClosing) {
            $stack[] = ['name' => $name, 'declares' => $declares, 'context' => $context];
        }
    }

    return $offenders;
}

/**
 * Does this attribute list open an Alpine scope that is certain to be rendered?
 *
 * Attribute VALUES are stripped first: `class="… x-data-grid"` is not a scope, and
 * reading the raw attribute text let exactly that defeat the previous version.
 *
 * A scope inside a Blade conditional — `<div @if($editable) x-data @endif>` — is not
 * counted, because on the false branch the handler below it ships inert. Unproven
 * is treated as unscoped on purpose: the whole point of this test is that a handler
 * whose scope cannot be established is the bug it is looking for.
 */
function declaresAlpineScope(string $attributes): bool
{
    $names = preg_replace('/=\s*("[^"]*"|\'[^\']*\')/', '=""', $attributes) ?? $attributes;

    // Case-insensitive, and `(?![\w.:-])` rather than `\b`: HTML lowercases
    // attribute names, and `\b` would match before a hyphen, so `x-data-grid`
    // must not count as a scope.
    if (! preg_match('/(^|[\s"\'])(x-data|wire:id)(?![\w.:-])/i', $names)) {
        return false;
    }

    return ! preg_match('/@(if|elseif|else|endif|unless|endunless|isset|empty|endisset|endempty)\b/i', $names);
}

/**
 * `x-on:click="…"` and its `@click="…"` shorthand. Blade attribute directives
 * (`@class([…])`, `@checked(…)`) take a parenthesised argument rather than `=`,
 * and `wire:click` is Livewire's own binding, which needs no Alpine scope.
 */
function hasAlpineHandler(string $attributes): bool
{
    // The leading class allows a quote as well as whitespace: `class="x"x-on:click=`
    // is valid HTML and was invisible to the previous `(^|\s)` form.
    // Case-insensitive: HTML lowercases attribute names, so `X-ON:CLICK` and
    // `@Click` bind in Alpine but were invisible to the previous pattern.
    return (bool) preg_match('/(^|[\s"\'])(x-on:[\w.:-]+|@[a-z][\w.:-]*)\s*=/i', $attributes);
}

/**
 * A Livewire/Volt component root carries `wire:id` once rendered, which Livewire 4
 * registers as an Alpine root selector — so everything inside the file is scoped.
 *
 * The signal has to be structural. `str_contains($contents, 'extends Component')`
 * on raw source would exempt an entire plain view if any COMMENT happened to
 * mention it. Comments are stripped first, and the class declaration must appear
 * inside a PHP block, which is the thing that actually makes the file a Volt
 * component.
 */
function bladeRootIsAlpineScoped(string $relativePath, string $contents): bool
{
    if (str_starts_with($relativePath, 'livewire/')) {
        return true;
    }

    $source = preg_replace('/\{\{--.*?--\}\}/s', '', $contents) ?? $contents;
    $source = preg_replace('/<!--.*?-->/s', '', $source) ?? $source;

    if (! preg_match('/<\?php(.*?)(\?>|$)/s', $source, $phpBlock)) {
        return false;
    }

    // Strip PHP comments AND string literals before matching — a Blade comment, an
    // HTML comment, or a PHP string such as `$note = 'new class extends
    // Component';` must not exempt a whole plain view.
    $php = $phpBlock[1];
    $php = preg_replace('!/\*.*?\*/!s', '', $php) ?? $php;
    $php = preg_replace('!//[^\n]*!', '', $php) ?? $php;
    $php = preg_replace('/#[^\n]*/', '', $php) ?? $php;
    $php = preg_replace('/\'(?:[^\'\\\\]|\\\\.)*\'/s', "''", $php) ?? $php;
    $php = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/s', '""', $php) ?? $php;

    return (bool) preg_match('/\bnew\s+class\b.*?\bextends\s+Component\b/s', $php);
}

function alpineHandlerScopeOffenders(): array
{
    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $path = str_replace('\\', '/', $file->getRelativePathname());
        $contents = $file->getContents();

        if (! str_contains($contents, 'x-on:') && ! preg_match('/\s@[a-z][\w.:-]*\s*=/', $contents)) {
            continue;
        }

        $unscoped = unscopedAlpineHandlers($contents, bladeRootIsAlpineScoped($path, $contents));

        if ($unscoped !== []) {
            $offenders[$path] = $unscoped;
        }
    }

    return $offenders;
}

test('every Alpine handler sits inside an Alpine scope', function () {
    $offenders = alpineHandlerScopeOffenders();

    $report = implode('; ', array_map(
        fn (string $path, array $hits) => $path.' → '.implode(', ', $hits),
        array_keys($offenders),
        $offenders,
    ));

    expect($offenders)->toBe([], 'These handlers have no x-data or wire:id ancestor, so Alpine never '
        .'initialises them and they are inert: '.$report);
});

test('handler scope detection covers conditional, case and comment edge cases', function () {
    $handler = '<button x-on:click="go()"></button>';

    // (a) An Alpine-looking string in an attribute VALUE is not a scope.
    expect(unscopedAlpineHandlers('<form class="grid x-data-grid">'.$handler.'</form>', false))
        ->toHaveCount(1, 'x-data-grid in a class attribute must not count as a scope');

    // (b) A conditional scope is not a proven scope — the false branch ships inert.
    expect(unscopedAlpineHandlers('<div @if($editable) x-data @endif>'.$handler.'</div>', false))
        ->toHaveCount(1, 'a conditional x-data must not count as an unconditional scope');

    // (c) A handler with no whitespace before it is still a handler.
    expect(unscopedAlpineHandlers('<div><button class="x"x-on:click="go()"></button></div>', false))
        ->toHaveCount(1, 'a compact attribute must not hide a handler');

    // (d) A stray close tag must not desynchronise ancestry for everything after it.
    expect(unscopedAlpineHandlers('<section></div>'.$handler.'</section>', false))
        ->toHaveCount(1, 'a mismatched close tag must not silently scope later handlers');

    // (e) A comment mentioning the Volt class must not exempt a plain view.
    expect(bladeRootIsAlpineScoped('sites/index.blade.php', '{{-- see the Livewire version: new class extends Component --}}'))
        ->toBeFalse('a comment must not make a plain Blade view read as a Livewire root');
    expect(bladeRootIsAlpineScoped('sites/index.blade.php', '<!-- extends Component -->'))
        ->toBeFalse('an HTML comment must not make a plain Blade view read as a Livewire root');

    // ...while a real Volt component still is one.
    expect(bladeRootIsAlpineScoped('pages/settings/profile.blade.php', "<?php\nuse Livewire\\Volt\\Component;\nnew class extends Component {\n} ?>\n<div></div>"))
        ->toBeTrue('a real Volt component must still be treated as an Alpine root');
    expect(bladeRootIsAlpineScoped('livewire/page-manager.blade.php', '<div></div>'))
        ->toBeTrue('a Livewire view must still be treated as an Alpine root');
});

test('handler scope detection covers conditional elements, branches, attribute case and php string edge cases', function () {
    $handler = '<button x-on:click="go()"></button>';

    // (a) A conditional ELEMENT opening the scope, not just a conditional
    //     ATTRIBUTE. Several real views use this shape.
    expect(unscopedAlpineHandlers('@if($editable)<div x-data="{}">@endif '.$handler.' @if($editable)</div>@endif', false))
        ->toHaveCount(1, 'a scope opened inside @if must not count for a handler outside it');

    // (b) The two-root @if/@else form: the handler is scoped on one branch only.
    expect(unscopedAlpineHandlers('@if($c)<div x-data>@else<div>@endif '.$handler.'</div>', false))
        ->toHaveCount(1, 'a scope present on only one branch is not a proven scope');

    // (c) Uppercase attribute names — HTML lowercases them, so these bind in Alpine.
    expect(unscopedAlpineHandlers('<div><button X-ON:CLICK="go()"></button></div>', false))
        ->toHaveCount(1, 'X-ON:CLICK binds in a browser and must not be invisible');
    expect(hasAlpineHandler(' @Click="a"'))->toBeTrue();

    // (d) `\b` matched before a hyphen, so an attribute NAMED x-data-grid counted.
    expect(declaresAlpineScope(' x-data-grid="1"'))->toBeFalse();
    expect(declaresAlpineScope(' wire:id-x="1"'))->toBeFalse();
    expect(declaresAlpineScope(' x-data="{}"'))->toBeTrue();
    expect(declaresAlpineScope(' wire:id="abc"'))->toBeTrue();

    // (e) A PHP STRING mentioning the Volt class must not exempt a plain view,
    //     the same as a Blade comment or an HTML comment must not either.
    expect(bladeRootIsAlpineScoped('x/y.blade.php', "<?php \$note = 'new class extends Component'; ?>\n<div></div>"))
        ->toBeFalse('a PHP string literal must not make a plain view read as a Livewire root');
    expect(bladeRootIsAlpineScoped('x/y.blade.php', "<?php // new class extends Component\n?>\n<div></div>"))
        ->toBeFalse('a PHP comment must not either');

    // ...while a real Volt component still is one.
    expect(bladeRootIsAlpineScoped('x/y.blade.php', "<?php\nnew class extends Component {\n} ?>\n<div></div>"))
        ->toBeTrue();

    // (f) An environment branch is a branch: a scope that only exists locally must
    //     not vouch for a handler that ships to production.
    expect(unscopedAlpineHandlers('@env(\'local\')<div x-data>@endenv <button x-on:click="go()"></button>', false))
        ->toHaveCount(1, 'a scope inside @env must not count for a handler outside it');
    expect(unscopedAlpineHandlers('@production<div x-data>@endproduction <button x-on:click="go()"></button>', false))
        ->toHaveCount(1, 'same for @production');
});

test('the scope check reads structure, not the presence of a string', function () {
    // A comment mentioning x-data while the real attribute is gone must not
    // read as a scope.
    $defeated = <<<'BLADE'
    {{-- x-data is load-bearing here, do not remove --}}
    <form method="GET">
        <select x-on:change="$el.form.submit()"></select>
    </form>
    BLADE;

    expect(unscopedAlpineHandlers($defeated, rootIsScoped: false))->toHaveCount(1);

    // The same markup with a real scope passes.
    $scoped = str_replace('<form method="GET">', '<form method="GET" x-data>', $defeated);
    expect(unscopedAlpineHandlers($scoped, rootIsScoped: false))->toBe([]);

    // A scope that has already closed does not reach a later sibling.
    $closedBefore = <<<'BLADE'
    <div x-data></div>
    <button x-on:click="go()"></button>
    BLADE;

    expect(unscopedAlpineHandlers($closedBefore, rootIsScoped: false))->toHaveCount(1);

    // A Livewire component root supplies the scope implicitly.
    expect(unscopedAlpineHandlers($defeated, rootIsScoped: true))->toBe([]);
});

test('the scan actually reaches the handlers it claims to check', function () {
    // A scanner whose regex stops matching examines nothing and passes silently —
    // the same shape of false comfort as the guards before it.
    //
    // The floor counts only handlers in files the walker ACTUALLY WALKS. Counting
    // every handler in resources/views would let the floor be satisfied entirely by
    // handlers inside Livewire/Volt files, which are exempt at the root and never
    // examined by the walker this test is exercising.
    $examined = 0;
    $exempt = 0;
    $publicSite = 0;

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $path = str_replace('\\', '/', $file->getRelativePathname());
        $contents = $file->getContents();
        $handlers = preg_match_all('/(^|[\s"\'])(x-on:[\w.:-]+|@[a-z][\w.:-]*)\s*=/m', $contents);

        if ($handlers === 0) {
            continue;
        }

        // Public-site and preview views are not served under the agents CSP, so
        // counting them towards the floor would let it be satisfied without a
        // single staff handler in scope. Count staff/customer chrome only.
        $isPublicSite = str_starts_with($path, 'site/') || str_starts_with($path, 'preview/');

        if (bladeRootIsAlpineScoped($path, $contents)) {
            $exempt += $handlers;
        } elseif ($isPublicSite) {
            $publicSite += $handlers;
        } else {
            $examined += $handlers;
        }
    }

    expect($examined)->toBeGreaterThanOrEqual(20, "The walker only examines {$examined} staff-surface "
        ."handlers ({$exempt} in exempt Livewire/Volt files, {$publicSite} in public-site views that "
        .'are not served under the agents CSP) — either they were removed or the scan stopped '
        .'matching.');
});

test('no inline on* handlers remain in any view', function () {
    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }
        if (preg_match('/\son(click|change|error|mouseover|mouseout|submit|load)\s*=/i', $file->getContents())) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});
