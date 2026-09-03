<?php

use App\Services\Site\Editor\OperationRegistry;

/**
 * @return list<mixed>
 */
function webmcpEvalCases(): array
{
    $path = base_path('evals/webmcp/cases.json');
    expect(file_exists($path))->toBeTrue();

    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    expect($decoded)->toBeArray()->not->toBeEmpty();

    return $decoded;
}

/**
 * @param  list<mixed>  $expectedCall
 * @return list<string>
 */
function webmcpEvalFunctionNames(array $expectedCall): array
{
    $names = [];

    foreach ($expectedCall as $step) {
        if (! is_array($step)) {
            continue;
        }

        if (is_string($step['functionName'] ?? null) && $step['functionName'] !== '') {
            $names[] = $step['functionName'];
        }

        foreach (['ordered', 'unordered'] as $group) {
            if (isset($step[$group]) && is_array($step[$group])) {
                $names = [...$names, ...webmcpEvalFunctionNames($step[$group])];
            }
        }
    }

    return $names;
}

function webmcpEvalBareName(string $functionName): string
{
    return str_starts_with($functionName, 'siteworks.')
        ? substr($functionName, strlen('siteworks.'))
        : $functionName;
}

it('authors 15-25 local eval cases with name, messages, and expectedCall', function () {
    $cases = webmcpEvalCases();

    expect(count($cases))->toBeGreaterThanOrEqual(15)
        ->and(count($cases))->toBeLessThanOrEqual(25);

    foreach ($cases as $case) {
        expect($case)->toBeArray()
            ->and($case['name'] ?? null)->toBeString()->not->toBeEmpty()
            ->and($case['messages'] ?? null)->toBeArray()->not->toBeEmpty()
            ->and($case['expectedCall'] ?? null)->toBeArray();

        foreach ($case['messages'] as $message) {
            expect($message)->toBeArray()
                ->and($message['role'] ?? null)->toBeIn(['user', 'assistant', 'system', 'tool']);

            if (($message['type'] ?? 'message') === 'message') {
                expect($message['content'] ?? null)->toBeString()->not->toBeEmpty();
            }
        }
    }
});

it('references only real advertised tools and never a publish operation', function () {
    $cases = webmcpEvalCases();
    $surface = array_keys(app(OperationRegistry::class)->all());
    $committed = json_decode(
        (string) file_get_contents(resource_path('js/site-editor/webmcp/schemas.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $surface = array_values(array_unique([
        ...$surface,
        ...array_keys($committed['operations'] ?? []),
    ]));

    $seen = [];

    foreach ($cases as $case) {
        foreach (webmcpEvalFunctionNames($case['expectedCall']) as $functionName) {
            $bare = webmcpEvalBareName($functionName);
            expect($bare)->not->toBe('publish')
                ->and($surface)->toContain($bare);
            $seen[] = $bare;
        }
    }

    expect($seen)->toContain('skill_import_catalogue_from_source')
        ->and($seen)->toContain('skill_add_product_with_imagery')
        ->and($seen)->toContain('skill_export_catalogue')
        ->and($seen)->toContain('list_products')
        ->and($seen)->toContain('get_site_context')
        ->and($seen)->toContain('get_brand_system');
});

it('covers matcher operators used in the webmcp-evals local format', function () {
    $raw = (string) file_get_contents(base_path('evals/webmcp/cases.json'));

    expect($raw)->toContain('"$contains"')
        ->and($raw)->toContain('"$lte"')
        ->and($raw)->toContain('"$any"');
});
