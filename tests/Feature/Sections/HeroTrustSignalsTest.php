<?php

function renderHeroForTrustSignals(array $section, string $pageType = 'home'): string
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
        'pageType' => $pageType,
        'pagesBySlug' => [],
    ])->render();
}

// ───── trust_signals present ──────────────────────────────────────────────

test('hero with trust_signals renders each signal string in the output', function () {
    $html = renderHeroForTrustSignals([
        'title' => 'The smarter way to manage projects',
        'trust_signals' => ['No deposit', 'Go live today', 'Cancel anytime'],
    ]);

    expect($html)->toContain('No deposit');
    expect($html)->toContain('Go live today');
    expect($html)->toContain('Cancel anytime');
});

test('hero with two trust_signals renders both', function () {
    $html = renderHeroForTrustSignals([
        'title' => 'Software Hero',
        'trust_signals' => ['14-day free trial', 'No credit card required'],
    ]);

    expect($html)->toContain('14-day free trial');
    expect($html)->toContain('No credit card required');
});

// ───── trust_signals absent or empty ──────────────────────────────────────

test('hero without trust_signals key renders unchanged (no empty container leaked)', function () {
    $html = renderHeroForTrustSignals([
        'title' => 'Standard Hero',
    ]);

    // The trust signal wrapper div must not be present when no signals given.
    expect($html)->not->toContain('trust_signals');
    // Crucially the hero itself still renders.
    expect($html)->toContain('Standard Hero');
});

test('hero with empty trust_signals array renders unchanged', function () {
    $html = renderHeroForTrustSignals([
        'title' => 'Standard Hero',
        'trust_signals' => [],
    ]);

    expect($html)->not->toContain('trust_signals');
    expect($html)->toContain('Standard Hero');
});

// ───── inner page — trust_signals must not appear ─────────────────────────

test('hero trust_signals are suppressed on inner pages (not home)', function () {
    $html = renderHeroForTrustSignals([
        'title' => 'About Us',
        'trust_signals' => ['No deposit', 'Go live today'],
    ], 'about');

    // trust_signals only render on home — inner pages must stay untouched.
    expect($html)->not->toContain('No deposit');
    expect($html)->not->toContain('Go live today');
});

// ───── blank or non-string items are silently skipped ────────────────────

test('hero skips blank strings in trust_signals array', function () {
    $html = renderHeroForTrustSignals([
        'title' => 'Hero',
        'trust_signals' => ['', 'Real signal', ''],
    ]);

    expect($html)->toContain('Real signal');
});
