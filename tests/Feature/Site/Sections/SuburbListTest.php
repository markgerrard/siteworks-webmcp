<?php

function renderSuburbList(array $profile, array $section = ['type' => 'suburb_list']): string
{
    return view('site.sections.suburb_list', [
        'section' => $section,
        'sectionIndex' => 0,
        'pageId' => 1,
        'emitMarkers' => false,
        'profile' => $profile,
    ])->render();
}

test('suburb_list renders when scope is local and 5+ suburbs are present', function () {
    $html = renderSuburbList([
        'geo' => [
            'scope' => 'local',
            'suburbs' => ['Clifton', 'Redland', 'Bishopston', 'Montpelier', 'Stokes Croft'],
        ],
    ]);

    foreach (['Clifton', 'Redland', 'Bishopston', 'Montpelier', 'Stokes Croft'] as $suburb) {
        expect($html)->toContain($suburb);
    }
    expect($html)->toContain('var(--radius-button)');
    expect($html)->toContain('var(--color-surface-alt)');
});

test('suburb_list is omitted when scope is regional', function () {
    $html = renderSuburbList([
        'geo' => [
            'scope' => 'regional',
            'suburbs' => array_fill(0, 10, 'Suburb'),
        ],
    ]);

    expect(trim($html))->toBe('');
});

test('suburb_list is omitted when fewer than 5 suburbs are present', function () {
    $html = renderSuburbList([
        'geo' => [
            'scope' => 'local',
            'suburbs' => ['Clifton', 'Redland', 'Bishopston', 'Montpelier'],
        ],
    ]);

    expect(trim($html))->toBe('');
});

test('suburb_list is omitted when geo key is absent entirely', function () {
    $html = renderSuburbList(['name' => 'Something Co']);

    expect(trim($html))->toBe('');
});

test('suburb_list dedupes and drops blank suburb entries before counting', function () {
    // 4 unique + 2 blanks + 1 duplicate — effective count is 4, should NOT render
    $html = renderSuburbList([
        'geo' => [
            'scope' => 'local',
            'suburbs' => ['Clifton', 'Redland', '', 'Clifton', 'Bishopston', '  ', 'Montpelier'],
        ],
    ]);

    expect(trim($html))->toBe('');
});

test('suburb_list renders a custom title when provided', function () {
    $html = renderSuburbList(
        [
            'geo' => [
                'scope' => 'local',
                'suburbs' => ['A', 'B', 'C', 'D', 'E'],
            ],
        ],
        ['type' => 'suburb_list', 'title' => 'Our patch', 'intro' => 'Bristol-wide coverage.'],
    );

    expect($html)->toContain('Our patch');
    expect($html)->toContain('Bristol-wide coverage.');
    expect($html)->not->toContain('Areas we cover');
});

test('suburb_list falls back to a default title when section has none', function () {
    $html = renderSuburbList([
        'geo' => [
            'scope' => 'local',
            'suburbs' => ['A', 'B', 'C', 'D', 'E'],
        ],
    ]);

    expect($html)->toContain('Areas we cover');
});

test('suburb_list is registered in site_sections config', function () {
    $sections = config('site_sections');
    expect($sections)->toHaveKey('suburb_list');
    expect($sections['suburb_list']['fields'])->toHaveKey('title');
});
