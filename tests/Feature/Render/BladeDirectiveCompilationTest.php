<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;

/**
 * A Blade directive only compiles where Blade's statement regex can see it: the
 * pattern requires `\B@`, which matches after a NON-word character and fails after
 * a word character. So `crossorigin="anonymous"@cspNonce` compiles, and
 * `<script@cspNonce>` does not — it survives as literal text, and the HTML parser
 * then reads it as an element named SCRIPT@CSPNONCE. The script never executes and
 * its body renders on screen as visible text.
 *
 * That shipped in 8c700e59 and left the WYSIWYG editor shell dead (no toolbar, no
 * Publish, config JSON painted on the page) and the /sites date filter uninitialised,
 * while the entire suite stayed green and review missed it.
 *
 * Compilation is the level this has to be checked at. Grepping the source for
 * `@cspNonce` cannot tell the working form from the broken one — only the compiler
 * knows.
 */
function bladeViewSources(): array
{
    $sources = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }
        $sources[$file->getRelativePathname()] = $file->getContents();
    }

    return $sources;
}

test('no @cspNonce directive survives compilation as literal text', function () {
    $offenders = [];

    foreach (bladeViewSources() as $path => $contents) {
        if (! str_contains($contents, '@cspNonce')) {
            continue;
        }

        if (str_contains(Blade::compileString($contents), '@cspNonce')) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], 'These views write @cspNonce where Blade cannot compile it, so the '
        .'inline script is emitted un-nonced and never executes: '.implode(', ', $offenders));
});

test('no Blade directive is glued to an HTML tag name', function () {
    $offenders = [];

    foreach (bladeViewSources() as $path => $contents) {
        // <script@cspNonce>, <div@if, <flux:card@if, <x-forms.input@if — a directive
        // needs whitespace before it. Component tag names contain : and . , so the
        // name class has to allow them or the guard misses exactly the tags most
        // likely to be written by hand.
        if (preg_match('/<[a-zA-Z][a-zA-Z0-9.:_-]*@[a-zA-Z]/', $contents, $matches)) {
            $offenders[] = $path.' ('.$matches[0].')';
        }
    }

    expect($offenders)->toBe([], 'A directive written immediately after a tag name does not compile and '
        .'produces an unknown element instead: '.implode(', ', $offenders));
});

/**
 * Is this offset inside an element's START TAG?
 *
 * The previous version compared `strrpos($before, '<')` with `strrpos($before, '>')`
 * over the whole preceding source, so any `<` in ordinary script text outranked the
 * `>` that had already closed the tag:
 *
 *     <script>
 *       if (a < b) { go(); }
 *       @cspNonce            <-- read as "inside a start tag". It is not.
 *     </script>
 *
 * A naive "last bracket" heuristic can pass while rendering the nonce as the
 * script's BODY. So scan forward instead, tracking real tag boundaries and
 * quoting, rather than guessing from the last bracket.
 */
function offsetIsInsideStartTag(string $source, int $offset): bool
{
    $insideTag = false;
    $quote = null;
    $length = min($offset, strlen($source));
    $rawTextElements = ['script', 'style', 'textarea', 'title'];
    $currentTag = '';

    for ($i = 0; $i < $length; $i++) {
        $char = $source[$i];

        if ($insideTag && $quote !== null) {
            if ($char === $quote) {
                $quote = null;
            }

            continue;
        }

        if ($insideTag && ($char === '"' || $char === "'")) {
            $quote = $char;

            continue;
        }

        if ($insideTag && $char === '>') {
            $insideTag = false;

            // Inside <script>/<style>/<textarea>/<title> the content is RAW TEXT: the
            // parser does not look for tags until the matching close. Continuing to
            // interpret `<` there is how `for (let i=0;i<n;i++)` convinces a naive
            // scanner it is inside a start tag — one whitespace character away from
            // `if (a < b)`.
            if (in_array($currentTag, $rawTextElements, true)) {
                $close = stripos($source, '</'.$currentTag, $i);

                if ($close === false || $close >= $length) {
                    return false; // the directive sits in raw text, not a start tag
                }

                $i = $close;
            }

            $currentTag = '';

            continue;
        }

        // A tag only opens on `<` followed by a name or a closing slash. `a < b`
        // is not a tag, which is the whole point.
        if (! $insideTag && $char === '<' && isset($source[$i + 1]) && preg_match('/[a-zA-Z\/]/', $source[$i + 1])) {
            $insideTag = true;
            preg_match('/^<\/?([a-zA-Z][a-zA-Z0-9:._-]*)/', substr($source, $i, 40), $nameMatch);
            $currentTag = strtolower($nameMatch[1] ?? '');
        }
    }

    return $insideTag;
}

test('@cspNonce is written inside a start tag, not as element content', function () {
    // `<script>@cspNonce</script>` compiles perfectly and passes every check above:
    // no literal survives, nothing is glued to a tag name, and the compiled output
    // contains the nonce lookup. It is still dead — the nonce is emitted as the
    // script's BODY, so the element carries no nonce attribute, script-src refuses
    // it, and the body is a syntax error. Green suite, dead surface: the same shape
    // as the bug this file was written to catch.
    $offenders = [];

    foreach (bladeViewSources() as $path => $contents) {
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', $contents) ?? $contents;

        $offset = 0;
        while (($position = strpos($source, '@cspNonce', $offset)) !== false) {
            if (! offsetIsInsideStartTag($source, $position)) {
                $line = substr_count(substr($source, 0, $position), "\n") + 1;
                $offenders[] = "{$path}:{$line}";
            }

            $offset = $position + 9;
        }
    }

    expect($offenders)->toBe([], 'These write @cspNonce as element content, so it renders a nonce as text '
        .'instead of stamping a nonce attribute: '.implode(', ', $offenders));
});

test('every view declaring @cspNonce compiles to a real nonce attribute', function () {
    $checked = 0;
    $offenders = [];

    foreach (bladeViewSources() as $path => $contents) {
        if (! str_contains($contents, '@cspNonce')) {
            continue;
        }

        if (! str_contains(Blade::compileString($contents), 'Vite::cspNonce()')) {
            $offenders[] = $path;
        }

        $checked++;
    }

    expect($offenders)->toBe([], 'These views declare @cspNonce but compile no nonce lookup: '
        .implode(', ', $offenders));

    expect($checked)->toBeGreaterThan(0, 'No view uses @cspNonce — has the directive been renamed?');
});

test('the start-tag scan is not fooled by comparison operators in script bodies', function () {
    // `if (a < b)` and the compact forms real JavaScript actually uses (`i<n`,
    // `a<b`) are one whitespace character away from looking like a start tag.
    // Raw-text elements are skipped wholesale instead.
    $cases = [
        "<script>\n  if (a < b) { go(); }\n  @cspNonce\n</script>",
        "<script>\n  for (let i=0;i<n;i++) {}\n  @cspNonce\n</script>",
        "<script>\n  if (a<b) { }\n  @cspNonce\n</script>",
        "<script>\n  const marker = \"<script \";\n  @cspNonce\n</script>",
    ];

    foreach ($cases as $index => $source) {
        $position = strpos($source, '@cspNonce');

        expect(offsetIsInsideStartTag($source, $position))
            ->toBeFalse("case {$index}: a directive in a script BODY is not inside a start tag");
    }

    // ...and the legitimate placement still reads as inside a start tag.
    $good = '<script @cspNonce>go()</script>';
    expect(offsetIsInsideStartTag($good, strpos($good, '@cspNonce')))->toBeTrue();
});
