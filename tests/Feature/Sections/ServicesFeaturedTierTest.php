<?php

function renderServicesForFeaturedTier(array $section): string
{
    return view('site.sections.services', [
        'section' => array_merge(['type' => 'services'], $section),
        'sectionIndex' => 0,
        'pageId' => 1,
        'emitMarkers' => false,
        'pagesBySlug' => [],
        'profile' => [],
        'site' => null,
    ])->render();
}

// ───── featured: true with default label ─────────────────────────────────

test('service item with featured true gets the default "Most popular" label', function () {
    $html = renderServicesForFeaturedTier([
        'items' => [
            ['title' => 'Basic', 'body' => 'Good for starters', 'featured' => false],
            ['title' => 'Pro', 'body' => 'Best for teams', 'featured' => true],
            ['title' => 'Enterprise', 'body' => 'For large orgs'],
        ],
    ]);

    expect($html)->toContain('Most popular');
});

test('service item with featured true and custom featured_label uses the custom label', function () {
    $html = renderServicesForFeaturedTier([
        'items' => [
            ['title' => 'Starter', 'body' => 'Free tier', 'featured' => false],
            ['title' => 'Growth', 'body' => 'Best value', 'featured' => true, 'featured_label' => 'Best value'],
        ],
    ]);

    expect($html)->toContain('Best value');
    expect($html)->not->toContain('Most popular');
});

test('service item with featured true but empty featured_label falls back to "Most popular"', function () {
    $html = renderServicesForFeaturedTier([
        'items' => [
            ['title' => 'Starter', 'body' => 'Go', 'featured' => true, 'featured_label' => ''],
        ],
    ]);

    expect($html)->toContain('Most popular');
});

// ───── featured: false or absent — standard treatment ────────────────────

test('service items without featured flag render without featured pill', function () {
    $html = renderServicesForFeaturedTier([
        'items' => [
            ['title' => 'Starter', 'body' => 'Entry level'],
            ['title' => 'Pro', 'body' => 'Power users'],
        ],
    ]);

    expect($html)->not->toContain('Most popular');
    // Both cards still render normally.
    expect($html)->toContain('Starter');
    expect($html)->toContain('Pro');
});

test('service item with featured false does not render the featured pill', function () {
    $html = renderServicesForFeaturedTier([
        'items' => [
            ['title' => 'Free', 'body' => 'No cost', 'featured' => false],
        ],
    ]);

    expect($html)->not->toContain('Most popular');
    expect($html)->toContain('Free');
});

// ───── contact_cta cards are not affected by featured logic ──────────────

test('contact_cta card is not treated as featured even when featured is true', function () {
    // contact_cta takes priority — its styling must not be overridden by featured.
    $html = renderServicesForFeaturedTier([
        'items' => [
            ['title' => 'Get in touch', 'body' => 'Contact us', 'contact_cta' => true, 'featured' => true],
        ],
    ]);

    // The featured pill must not appear on a contact_cta card.
    expect($html)->not->toContain('Most popular');
    expect($html)->toContain('Get in touch');
});

// ───── backwards compatibility ────────────────────────────────────────────

test('existing service sections without featured fields render identically to before', function () {
    $html = renderServicesForFeaturedTier([
        'title' => 'What We Do',
        'items' => [
            ['icon' => 'wrench', 'title' => 'Plumbing', 'body' => 'All plumbing work'],
            ['icon' => 'zap', 'title' => 'Electrical', 'body' => 'Safe electrical'],
            ['icon' => 'home', 'title' => 'Roofing', 'body' => 'Roof repairs'],
        ],
    ]);

    expect($html)->toContain('Plumbing');
    expect($html)->toContain('Electrical');
    expect($html)->toContain('Roofing');
    expect($html)->not->toContain('Most popular');
});
