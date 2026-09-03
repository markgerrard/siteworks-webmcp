<?php

function renderServicesGrid(array $items): string
{
    return view('site.sections.services', [
        'section' => ['type' => 'services', 'title' => 'Services', 'items' => $items],
        'sectionIndex' => 0,
        'pageId' => 1,
        'emitMarkers' => false,
        'pagesBySlug' => [],
    ])->render();
}

function servicesItem(array $overrides = []): array
{
    return array_merge([
        'title' => 'Boiler repair',
        'body' => 'Fast, reliable.',
    ], $overrides);
}

test('services grid cards use radius-card token, not hardcoded rounded-lg', function () {
    $html = renderServicesGrid([servicesItem(), servicesItem(), servicesItem()]);

    expect($html)->toContain('border-radius: var(--radius-card)');
    // The old hardcoded class should no longer wrap cards; this keyword
    // appeared on the card wrapper in the legacy template.
    expect($html)->not->toContain('rounded-lg');
});

test('services grid cards carry a subtle border using the color-border token', function () {
    $html = renderServicesGrid([servicesItem()]);

    expect($html)->toContain('border: 1px solid var(--color-border)');
});

test('contact-CTA variant renders inverted colours and a contact link', function () {
    $html = renderServicesGrid([
        servicesItem(),
        servicesItem(),
        servicesItem(['title' => 'Talk to us', 'body' => 'Book a visit', 'contact_cta' => true, 'cta_label' => 'Get in touch']),
    ]);

    expect($html)->toContain('background-color: var(--brand-primary)');
    expect($html)->toContain('href="#contact"');
    expect($html)->toContain('Get in touch');
});

test('contact-CTA uses default cta_label when none supplied', function () {
    $html = renderServicesGrid([
        servicesItem(['title' => 'Chat to the team', 'contact_cta' => true]),
    ]);

    expect($html)->toContain('Get in touch');
});

test('6-card layout uses the default 33.333 percent flex basis (3 cols)', function () {
    $items = array_map(fn ($i) => servicesItem(['title' => "Service {$i}"]), range(1, 6));
    $html = renderServicesGrid($items);

    expect($html)->toContain('flex: 0 1 calc(33.333% - 1.34rem)');
    expect($html)->not->toContain('flex: 0 1 calc(25% - 1.5rem)');
});

test('8-card layout switches to the 25 percent flex basis (4 cols)', function () {
    $items = array_map(fn ($i) => servicesItem(['title' => "Service {$i}"]), range(1, 8));
    $html = renderServicesGrid($items);

    expect($html)->toContain('flex: 0 1 calc(25% - 1.5rem)');
});

test('4-card layout switches to the 50 percent flex basis (2 cols)', function () {
    $items = array_map(fn ($i) => servicesItem(['title' => "Service {$i}"]), range(1, 4));
    $html = renderServicesGrid($items);

    expect($html)->toContain('flex: 0 1 calc(50% - 1rem)');
});
