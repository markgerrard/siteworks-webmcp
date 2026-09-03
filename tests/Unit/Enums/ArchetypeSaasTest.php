<?php

use App\Enums\Archetype;
use App\Enums\LeadFormPolicy;

test('SaasPlatform case exists with expected string value', function () {
    expect(Archetype::SaasPlatform->value)->toBe('saas_platform');
});

test('SaasPlatform parses back from its own value', function () {
    expect(Archetype::fromProfile('saas_platform'))->toBe(Archetype::SaasPlatform);
});

test('SaasPlatform phoneCtaCopy returns demo-focused framing with required keys', function () {
    $copy = Archetype::SaasPlatform->phoneCtaCopy();

    expect($copy)->toHaveKeys(['title', 'subtitle']);
    expect($copy['title'])->not->toBe('');
    expect($copy['subtitle'])->not->toBe('');
});

test('SaasPlatform phoneCtaCopy does not bleed emergency framing', function () {
    $copy = Archetype::SaasPlatform->phoneCtaCopy();

    expect($copy['title'])->not->toContain('24/7');
    expect($copy['title'])->not->toContain('Emergency');
    expect($copy['subtitle'])->not->toContain('Rapid response');
});

test('SaasPlatform phoneCtaCopy uses demo-oriented language', function () {
    $copy = Archetype::SaasPlatform->phoneCtaCopy();

    // Should reflect a "book a demo / see it in action" framing
    expect(strtolower($copy['title']))->toContain('demo');
});

test('existing phoneCtaCopy loop covers SaasPlatform without exhaustion exception', function () {
    // This guard catches the case where a new archetype is added to the enum
    // but its phoneCtaCopy() arm is missing — PHP throws UnhandledMatchError.
    foreach (Archetype::cases() as $case) {
        $copy = $case->phoneCtaCopy();
        expect($copy)->toHaveKeys(['title', 'subtitle']);
    }
});

test('LeadFormPolicy default for SaasPlatform is Home', function () {
    expect(LeadFormPolicy::defaultForArchetype(Archetype::SaasPlatform))->toBe(LeadFormPolicy::Home);
});
