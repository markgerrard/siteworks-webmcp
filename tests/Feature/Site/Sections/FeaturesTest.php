<?php

function renderFeatures(array $section): string
{
    return view('site.sections.features', [
        'section' => array_merge(['type' => 'features'], $section),
        'sectionIndex' => 0,
        'pageId' => 1,
        'emitMarkers' => false,
    ])->render();
}

test('features section renders a grid of items with title + body', function () {
    $html = renderFeatures([
        'title' => 'What is Included with Boiler Servicing',
        'intro' => 'Fixed-scope, transparent offering — no surprises.',
        'items' => [
            ['icon' => 'wrench', 'title' => 'Full system flush', 'body' => 'Central heating cleaned to restore flow.'],
            ['icon' => 'shield-check', 'title' => '12-month warranty', 'body' => 'Labour and parts covered.'],
            ['icon' => 'clock', 'title' => 'Same-day quote', 'body' => 'Firm price within 24 hours of call-out.'],
        ],
    ]);

    expect($html)->toContain('What is Included with Boiler Servicing');
    expect($html)->toContain('Fixed-scope, transparent offering');
    expect($html)->toContain('Full system flush');
    expect($html)->toContain('12-month warranty');
    expect($html)->toContain('Same-day quote');
    expect($html)->toContain('data-lucide="wrench"');
});

test('features section renders a checkmark SVG fallback when icon is missing', function () {
    $html = renderFeatures([
        'title' => 'Included Features',
        'items' => [['title' => 'No icon', 'body' => 'Something']],
    ]);

    expect($html)->toContain('No icon');
    expect($html)->toContain('<svg');
    expect($html)->toContain('stroke-linecap="round"');
});

test('features section is omitted when items is empty', function () {
    expect(trim(renderFeatures(['title' => 'Empty', 'items' => []])))->toBe('');
    expect(trim(renderFeatures(['title' => 'None'])))->toBe('');
});

test('features section uses surface-alt-aware text tokens', function () {
    $html = renderFeatures([
        'items' => [['title' => 'A', 'body' => 'B']],
    ]);

    // Item sits on surface-alt bg, so text uses the -on-alt variants
    // (avoids dark-on-dark when brief picks a dark surface-alt).
    expect($html)->toContain('var(--color-text-on-alt)');
    expect($html)->toContain('var(--color-text-muted-on-alt)');
});

test('features section is registered in site_sections config', function () {
    $sections = config('site_sections');
    expect($sections)->toHaveKey('features');
    expect($sections['features']['fields'])->toHaveKey('title');
    expect($sections['features']['fields'])->toHaveKey('items.*.title');
});

test('ContentShapeTranslator routes features into the sections array', function () {
    $translated = app(\App\Services\Site\ContentShapeTranslator::class)->translate([
        'hero' => ['heading' => 'Title'],
        'features' => [
            'heading' => 'Included',
            'items' => [['icon' => 'star', 'title' => 'Foo', 'body' => 'Bar']],
        ],
        'cta' => ['heading' => 'Call'],
    ]);

    $types = array_column($translated['sections'], 'type');
    expect($types)->toBe(['hero', 'features', 'cta']);
});
