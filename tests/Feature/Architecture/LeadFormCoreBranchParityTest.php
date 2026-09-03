<?php

/**
 * Drift guard: the row branch's per-field loop body is an accepted duplicate of
 * the stacked branch. Whitespace-normalised, the two copies must stay identical.
 */
it('stacked and row field-loop bodies in lead-form-core stay identical', function () {
    $blade = file_get_contents(resource_path('views/site/partials/lead-form-core.blade.php'));
    expect($blade)->not->toBeFalse();

    $bodies = leadFormCoreBranchParityFieldLoopBodies($blade);
    expect($bodies)->toHaveCount(2);

    $normalize = fn (string $s): string => preg_replace('/\s+/', ' ', trim($s)) ?? '';

    expect($normalize($bodies[1]))->toBe($normalize($bodies[0]));
});

/**
 * Extract each field-loop body between `@foreach (... as $i => $field)` and its
 * matching `@endforeach`, from the per-field `@php` onwards (so the row
 * branch's submit-split `@if` is not compared).
 *
 * @return list<string>
 */
function leadFormCoreBranchParityFieldLoopBodies(string $blade): array
{
    $bodies = [];
    $offset = 0;
    while (preg_match('/@foreach\s*\(.*as\s+\$i\s*=>\s*\$field\)/', $blade, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
        $start = $match[0][1] + strlen($match[0][0]);
        $depth = 1;
        $pos = $start;
        $end = null;
        while (preg_match('/@(foreach|endforeach)\b/', $blade, $inner, PREG_OFFSET_CAPTURE, $pos) === 1) {
            if ($inner[1][0] === 'foreach') {
                $depth++;
            } else {
                $depth--;
            }
            $pos = $inner[0][1] + strlen($inner[0][0]);
            if ($depth === 0) {
                $end = $inner[0][1];
                break;
            }
        }
        expect($end)->not->toBeNull();
        $extract = substr($blade, $start, $end - $start);
        $php = strpos($extract, '@php');
        expect($php)->not->toBeFalse();
        $bodies[] = substr($extract, $php);
        $offset = $pos;
    }

    return $bodies;
}
