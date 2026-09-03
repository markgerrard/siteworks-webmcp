<?php

function renderHeroWithAccent(array $section, string $pageType = 'home'): string
{
    return view('site.sections.hero', [
        'section' => array_merge(['type' => 'hero'], $section),
        'sectionIndex' => 0,
        'pageId' => 1,
        'emitMarkers' => false,
        'profile' => [],
        'heroImageUrl' => null,
        'heroPlacement' => [],
        'heroSizeConfig' => ['home' => '55vh', 'inner' => '35vh'],
        'project' => (object) ['business_name' => 'Acme'],
        'pageType' => $pageType,
        'layout' => 'multi_page',
        'pageUrl' => fn ($p) => "/{$p}",
        'previewSlug' => 'demo',
        'data' => [
            'heading' => $section['title'] ?? '',
            'subheading' => $section['subtitle'] ?? '',
            'cta_label' => $section['cta_label'] ?? '',
        ],
    ])->render();
}

function renderCtaWithAccent(array $section): string
{
    return view('site.sections.cta', [
        'section' => array_merge(['type' => 'cta'], $section),
        'sectionIndex' => 0,
        'pageId' => 1,
        'emitMarkers' => false,
    ])->render();
}

function renderServicesWithAccent(array $section): string
{
    return view('site.sections.services', [
        'section' => array_merge(['type' => 'services', 'items' => []], $section),
        'sectionIndex' => 0,
        'pageId' => 1,
        'emitMarkers' => false,
        'pagesBySlug' => [],
    ])->render();
}

function renderHeroCompactWithAccent(array $section): string
{
    return view('site.sections.hero_compact', [
        'section' => array_merge(['type' => 'hero'], $section),
        'sectionIndex' => 0,
        'pageId' => 1,
        'emitMarkers' => false,
        'profile' => [],
        'heroImageUrl' => null,
    ])->render();
}

test('home hero wraps accent_word in the title with the accent-word span', function () {
    $html = renderHeroWithAccent(['title' => 'Your Trusted Plumbing Partner', 'accent_word' => 'Plumbing']);

    expect($html)->toContain('<span class="accent-word"');
    expect($html)->toContain('color: var(--color-accent)');
});

test('hero_compact wraps accent_word in the title', function () {
    $html = renderHeroCompactWithAccent(['title' => 'Boiler Repairs in Bristol', 'accent_word' => 'Boiler']);

    expect($html)->toContain('<span class="accent-word"');
});

test('services section wraps accent_word in its heading', function () {
    $html = renderServicesWithAccent(['title' => 'What Our Plumbing Covers', 'accent_word' => 'Plumbing']);

    expect($html)->toContain('<span class="accent-word"');
});

test('cta section renders title without an accent-word span (monotone on primary band)', function () {
    // cta.blade.php sits on --brand-primary. An accent-coloured highlight
    // would be cyan-on-cyan and disappear. The band itself is already the
    // "highlight" — the headline stays monotone on purpose.
    $html = renderCtaWithAccent(['title' => 'Book Your Boiler Service Today', 'accent_word' => 'Boiler']);

    expect($html)->not->toContain('accent-word');
    expect($html)->toContain('Book Your Boiler Service Today');
});

test('missing accent_word leaves the title rendered as plain text', function () {
    $html = renderCtaWithAccent(['title' => 'Book Your Boiler Service Today']);

    expect($html)->not->toContain('accent-word');
    expect($html)->toContain('Book Your Boiler Service Today');
});

test('accent_word that is not in the title is a no-op (back-compat safety)', function () {
    $html = renderServicesWithAccent(['title' => 'What we do', 'accent_word' => 'Plumbing']);

    expect($html)->not->toContain('accent-word');
    expect($html)->toContain('What we do');
});

test('accent_word rendering escapes a malicious title', function () {
    // cta.blade.php deliberately does NOT wrap an accent span (band is on
    // --brand-primary and an accent-coloured word would be near-invisible
    // cyan-on-cyan). Use the hero renderer to cover the XSS-through-
    // accent-word path, since hero IS the canonical accent-word consumer.
    $html = renderHeroWithAccent(['title' => '<script>alert(1)</script> Hero', 'accent_word' => 'Hero']);

    expect($html)->not->toContain('<script>alert(1)</script>');
    expect($html)->toContain('&lt;script&gt;');
    expect($html)->toContain('<span class="accent-word"');
});

test('accent_ranges wrap the second occurrence of a repeated phrase', function () {
    $title = 'Plumbing Partners — Plumbing Experts';
    $first = mb_stripos($title, 'Plumbing');
    $second = mb_stripos($title, 'Plumbing', $first + 1);
    $html = renderHeroWithAccent([
        'title' => $title,
        'accent_ranges' => [['start' => $second, 'length' => mb_strlen('Plumbing')]],
    ]);

    expect($html)->toContain('Plumbing Partners — <span class="accent-word"');
    expect(substr_count($html, '<span class="accent-word"'))->toBe(1);
});

test('accent_ranges index a multibyte title by codepoint', function () {
    $title = 'Café plumbing';
    $html = renderHeroWithAccent([
        'title' => $title,
        'accent_ranges' => [['start' => mb_strpos($title, 'plumbing'), 'length' => mb_strlen('plumbing')]],
    ]);

    expect($html)->toContain('Café <span class="accent-word"');
    expect($html)->toContain('>plumbing</span>');
});

test('accent_ranges at the title boundary wrap the last word', function () {
    $title = 'Trusted Plumbing';
    $word = 'Plumbing';
    $html = renderHeroWithAccent([
        'title' => $title,
        'accent_ranges' => [['start' => mb_strlen($title) - mb_strlen($word), 'length' => mb_strlen($word)]],
    ]);

    expect($html)->toContain('Trusted <span class="accent-word"');
    expect($html)->toContain('>Plumbing</span>');
});

test('stale accent_ranges that no longer fit the title are not applied', function () {
    $html = renderHeroWithAccent([
        'title' => 'Hi',
        'accent_ranges' => [['start' => 0, 'length' => 50]],
    ]);

    expect($html)->not->toContain('<span class="accent-word"');
    expect($html)->toContain('Hi');
});

test('accent_ranges wrapping escapes a malicious title including the accented slice', function () {
    $title = '<script>alert(1)</script> Hero';
    $start = mb_strpos($title, 'alert');
    $html = renderHeroWithAccent([
        'title' => $title,
        'accent_ranges' => [['start' => $start, 'length' => mb_strlen('alert')]],
    ]);

    expect($html)->not->toContain('<script>');
    expect($html)->toContain('&lt;script&gt;');
    expect($html)->toContain('<span class="accent-word"');
    expect($html)->toContain('>alert</span>');
});
