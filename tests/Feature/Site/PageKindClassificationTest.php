<?php

use App\Enums\PageKind;

it('treats every legal page naming variant as core, not service', function () {
    // A page wrongly classified as a service gets a lead form injected into
    // it by PageRenderer::injectServiceLeadForm() — a "get a free quote"
    // form on a privacy policy.
    foreach ([
        'privacy', 'privacy-policy', 'privacy-notice',
        'terms', 'terms-and-conditions', 'terms-of-service', 'terms-of-use',
        'cookies', 'cookie-policy', 'legal', 'disclaimer', 'accessibility',
    ] as $slug) {
        expect(PageKind::CORE_PAGE_TYPES)->toContain($slug);
    }
});

it('keeps the original core page types', function () {
    foreach (['home', 'about', 'contact', 'projects'] as $slug) {
        expect(PageKind::CORE_PAGE_TYPES)->toContain($slug);
    }
});

it('does not classify a real service page as core', function () {
    foreach ([
        'drainage-north-west-england',
        'boiler-installations-quainton',
        'block-paving-wigan',
    ] as $slug) {
        expect(PageKind::CORE_PAGE_TYPES)->not->toContain($slug);
    }
});

it('exposes core types as a flat list of strings', function () {
    expect(PageKind::CORE_PAGE_TYPES)->toBeArray()
        ->and(array_filter(PageKind::CORE_PAGE_TYPES, fn ($v) => ! is_string($v)))->toBeEmpty()
        ->and(PageKind::CORE_PAGE_TYPES)->toBe(array_values(array_unique(PageKind::CORE_PAGE_TYPES)));
});
