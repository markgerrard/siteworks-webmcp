<?php

use App\Enums\Archetype;

test('all 6 archetype values parse back to themselves', function () {
    foreach (Archetype::cases() as $case) {
        expect(Archetype::fromProfile($case->value))->toBe($case);
    }
});

test('fromProfile defaults to LocalService on unknown values', function () {
    expect(Archetype::fromProfile('unknown'))->toBe(Archetype::LocalService);
    expect(Archetype::fromProfile('emergency_trader'))->toBe(Archetype::LocalService);
    expect(Archetype::fromProfile(''))->toBe(Archetype::LocalService);
});

test('fromProfile defaults to LocalService on null', function () {
    expect(Archetype::fromProfile(null))->toBe(Archetype::LocalService);
});

test('Contract 3 string values are exact', function () {
    expect(Archetype::EmergencyTrade->value)->toBe('emergency_trade');
    expect(Archetype::TraditionalCraftsman->value)->toBe('traditional_craftsman');
    expect(Archetype::PremiumSpecialist->value)->toBe('premium_specialist');
    expect(Archetype::LocalService->value)->toBe('local_service');
    expect(Archetype::RetailVenue->value)->toBe('retail_venue');
    expect(Archetype::ProfessionalService->value)->toBe('professional_service');
});

test('phoneCtaCopy returns archetype-specific framing for every case', function () {
    // Each archetype must declare its own copy. EmergencyTrade keeps the
    // 24/7 framing — the rest must NOT.
    foreach (Archetype::cases() as $case) {
        $copy = $case->phoneCtaCopy();
        expect($copy)->toHaveKeys(['title', 'subtitle']);
        expect($copy['title'])->not->toBe('');
    }

    expect(Archetype::EmergencyTrade->phoneCtaCopy()['title'])->toContain('24/7');

    // Non-emergency archetypes must not leak the emergency framing.
    foreach ([Archetype::TraditionalCraftsman, Archetype::PremiumSpecialist, Archetype::LocalService, Archetype::RetailVenue, Archetype::ProfessionalService] as $case) {
        expect($case->phoneCtaCopy()['title'])->not->toContain('24/7');
        expect($case->phoneCtaCopy()['title'])->not->toContain('Emergency');
        expect($case->phoneCtaCopy()['subtitle'])->not->toContain('Rapid response');
    }
});
