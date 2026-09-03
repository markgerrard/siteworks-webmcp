<?php

function renderTrust(array $section): string
{
    return view('site.sections.trust', [
        'section' => array_merge(['type' => 'trust'], $section),
        'sectionIndex' => 0,
        'pageId' => 1,
        'emitMarkers' => false,
    ])->render();
}

test('trust cards carry the mobile stacking class and media query', function () {
    $html = renderTrust([
        'title' => 'Why Choose Us',
        'items' => [
            ['title' => 'Quality Craftsmanship', 'body' => 'Every project completed to an exceptional standard.'],
            ['title' => 'Honest & Transparent', 'body' => 'Clear upfront quotes with no hidden surprises.'],
            ['title' => 'London Specialists', 'body' => 'We know the local property landscape.'],
        ],
    ]);

    expect($html)->toContain('trust-item-card flex items-start gap-5');
    expect($html)->toContain('max-width: 639px');
    expect($html)->toContain('flex-direction: column');
    expect($html)->toContain('Quality Craftsmanship');
});

test('trust section without items renders no card markup or style block', function () {
    $html = renderTrust(['title' => 'Why Choose Us']);

    expect($html)->not->toContain('trust-item-card');
    expect($html)->not->toContain('<style>');
});
