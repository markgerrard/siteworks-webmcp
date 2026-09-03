<?php

function renderSection(string $type, array $section, array $profile = []): string
{
    return view("site.sections.{$type}", [
        'section' => array_merge(['type' => $type], $section),
        'sectionIndex' => 0,
        'pageId' => 1,
        'emitMarkers' => false,
        'profile' => $profile,
    ])->render();
}

// ───── phone_cta_strip ────────────────────────────────────────────────

test('phone_cta_strip renders with oversized phone when profile has a number', function () {
    $html = renderSection('phone_cta_strip', [], ['contact' => ['phones' => ['01234 567890']]]);

    expect($html)->toContain('01234 567890');
    expect($html)->toContain('tel:01234567890');
    // Background uses surface-alt to avoid the saturated-primary haze on
    // bright-primary brands and the disappear-into-surface problem on
    // near-black-primary brands.
    // Brand identity now carries via --color-accent-text-on-alt on the
    // phone number + icon, not via the band fill.
    expect($html)->toContain('var(--color-surface-alt)');
    expect($html)->toContain('var(--color-accent-text-on-alt)');
});

test('phone_cta_strip is omitted when profile has no phones', function () {
    $html = renderSection('phone_cta_strip', [], ['contact' => ['phones' => []]]);

    expect(trim($html))->toBe('');
});

test('phone_cta_strip tolerates missing contact key entirely', function () {
    expect(trim(renderSection('phone_cta_strip', [], [])))->toBe('');
});

// ───── opening_hours_strip ────────────────────────────────────────────

test('opening_hours_strip renders rows from a Mon-Fri style dict', function () {
    $html = renderSection('opening_hours_strip', [], [
        'opening_hours' => ['Mon' => '9-5', 'Tue' => '9-5', 'Sat' => 'Closed'],
    ]);

    expect($html)->toContain('Mon');
    expect($html)->toContain('9-5');
    expect($html)->toContain('Sat');
    expect($html)->toContain('Closed');
});

test('opening_hours_strip renders rows from a list-of-entries shape', function () {
    $html = renderSection('opening_hours_strip', [], [
        'opening_hours' => [
            ['day' => 'Monday', 'hours' => '08:00–18:00'],
            ['day' => 'Tuesday', 'hours' => '08:00–18:00'],
        ],
    ]);

    expect($html)->toContain('Monday');
    expect($html)->toContain('08:00–18:00');
});

test('opening_hours_strip is omitted when opening_hours is missing', function () {
    expect(trim(renderSection('opening_hours_strip', [], [])))->toBe('');
});

// ───── who_we_help_strip ──────────────────────────────────────────────

test('who_we_help_strip prefers content_data items when present', function () {
    $html = renderSection('who_we_help_strip', [
        'items' => [['title' => 'Landlords'], ['title' => 'Letting agents']],
    ], [
        'audience' => 'Ignored because items wins',
    ]);

    expect($html)->toContain('Landlords');
    expect($html)->toContain('Letting agents');
    expect($html)->not->toContain('Ignored because items wins');
});

test('who_we_help_strip falls back to audience_segments when items is absent', function () {
    $html = renderSection('who_we_help_strip', [], [
        'audience_segments' => ['Homeowners', 'Small businesses'],
    ]);

    expect($html)->toContain('Homeowners');
    expect($html)->toContain('Small businesses');
});

test('who_we_help_strip splits comma-separated audience string as last resort', function () {
    $html = renderSection('who_we_help_strip', [], [
        'audience' => 'Landlords, estate agents, property managers',
    ]);

    expect($html)->toContain('Landlords');
    expect($html)->toContain('estate agents');
    expect($html)->toContain('property managers');
});

test('who_we_help_strip is omitted when no audience data is available', function () {
    expect(trim(renderSection('who_we_help_strip', [], [])))->toBe('');
});

// ───── portfolio_strip ────────────────────────────────────────────────

test('portfolio_strip renders thumbnails when portfolio_images is present', function () {
    $html = renderSection('portfolio_strip', [], [
        'portfolio_images' => ['https://cdn/a.jpg', 'https://cdn/b.jpg'],
    ]);

    expect($html)->toContain('https://cdn/a.jpg');
    expect($html)->toContain('https://cdn/b.jpg');
    expect($html)->toContain('var(--radius-card)');
});

test('portfolio_strip is omitted when portfolio_images is missing or empty', function () {
    expect(trim(renderSection('portfolio_strip', [], [])))->toBe('');
    expect(trim(renderSection('portfolio_strip', [], ['portfolio_images' => []])))->toBe('');
});

test('portfolio_strip filters out non-http URLs to avoid broken images', function () {
    $html = renderSection('portfolio_strip', [], [
        'portfolio_images' => ['https://cdn/a.jpg', 'javascript:alert(1)', ''],
    ]);

    expect($html)->toContain('https://cdn/a.jpg');
    expect($html)->not->toContain('javascript:alert(1)');
});

// ───── case_study_teaser ──────────────────────────────────────────────

test('case_study_teaser renders when title + body are supplied', function () {
    $html = renderSection('case_study_teaser', [
        'title' => 'Bespoke kitchen in a grade-II farmhouse',
        'body' => 'Six-week fit, hand-dovetailed drawers, reclaimed oak worktops.',
        'client' => 'The Thompson family',
        'stat' => '6 weeks',
        'stat_label' => 'start to finish',
    ]);

    expect($html)->toContain('Bespoke kitchen in a grade-II farmhouse');
    expect($html)->toContain('hand-dovetailed drawers');
    expect($html)->toContain('The Thompson family');
    expect($html)->toContain('6 weeks');
    expect($html)->toContain('start to finish');
});

test('case_study_teaser is omitted when title or body is missing', function () {
    expect(trim(renderSection('case_study_teaser', [])))->toBe('');
    expect(trim(renderSection('case_study_teaser', ['title' => 'Only a title'])))->toBe('');
});

test('case_study_teaser renders an image when image_url is a valid https URL', function () {
    $html = renderSection('case_study_teaser', [
        'title' => 'Case study',
        'body' => 'Some body content',
        'image_url' => 'https://cdn/case.jpg',
    ]);

    expect($html)->toContain('https://cdn/case.jpg');
});

// ───── config registration ────────────────────────────────────────────

test('all five new section types are registered in site_sections config', function () {
    $sections = config('site_sections');
    foreach (['phone_cta_strip', 'opening_hours_strip', 'who_we_help_strip', 'portfolio_strip', 'case_study_teaser'] as $type) {
        expect($sections)->toHaveKey($type);
    }
});
