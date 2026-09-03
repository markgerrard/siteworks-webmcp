<?php

use App\Services\Site\Editor\OperationRegistry;

it('names every registered editor operation in the architecture catalogue', function () {
    $doc = file_get_contents(base_path('docs/architecture/editor-operations.md'));
    $names = collect(OperationRegistry::discover()->all())->keys();

    expect($doc)->toBeString()->not->toBe('');

    $missing = $names
        ->reject(fn (string $name): bool => str_contains($doc, $name))
        ->values()
        ->all();

    expect($missing)->toBe([])
        ->and($names)->toHaveCount(50)
        ->and($doc)->toContain('(**50** operation classes')
        ->and($doc)->toContain('Verified against `OperationRegistry::discover()` (**50**)')
        ->and($doc)->toContain('The 50 names');
});

it('lists operations in discover() order and states auto-only product-block suppression', function () {
    $doc = file_get_contents(base_path('docs/architecture/editor-operations.md'));
    $names = array_keys(OperationRegistry::discover()->all());

    expect($doc)->toMatch('/The 50 names, in discover\(\) insertion order \(sorted file glob\):\n\n(`[^`]+`(?:, `[^`]+`)*)/');
    preg_match('/The 50 names, in discover\(\) insertion order \(sorted file glob\):\n\n(`[^`]+`(?:, `[^`]+`)*)/', $doc, $match);
    $listed = array_map(static fn (string $name): string => trim($name, '`'), explode(', ', $match[1]));

    expect($listed)->toBe($names)
        ->and($doc)->not->toContain('Every source (`manual`, `featured`, `newest`, `tag:<slug>`, `category:<slug>`) emits nothing when it matches fewer than two snapshot products.')
        ->and($doc)->toContain('Auto sources (`newest`, `tag:<slug>`, `category:<slug>`) emit nothing when they match fewer than two snapshot products; `manual` and `featured` render whatever was picked.');
});
