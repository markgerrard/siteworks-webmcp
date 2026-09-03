<?php

use App\Enums\Archetype;
use App\Services\Site\ArchetypeRecipe;

beforeEach(fn () => $this->recipe = new ArchetypeRecipe);

test('each enum case returns a non-empty sections list', function () {
    foreach (Archetype::cases() as $case) {
        $recipe = $this->recipe->for($case);
        expect($recipe['sections'])->toBeArray()->not->toBeEmpty();
        expect($recipe['weights'])->toBeArray();
    }
});

test('emergency_trade includes phone_cta_strip + suburb_list', function () {
    $recipe = $this->recipe->for(Archetype::EmergencyTrade);

    expect($recipe['sections'])->toContain('phone_cta_strip');
    expect($recipe['sections'])->toContain('suburb_list');
    expect($recipe['weights']['hero']['emergency_variant'] ?? null)->toBeTrue();
});

test('traditional_craftsman includes portfolio_strip', function () {
    $recipe = $this->recipe->for(Archetype::TraditionalCraftsman);

    expect($recipe['sections'])->toContain('portfolio_strip');
});

test('local_service recipe includes lead_form and service_area_card', function () {
    $recipe = $this->recipe->for(Archetype::LocalService);

    expect($recipe['sections'])->toContain('lead_form');
    expect($recipe['sections'])->toContain('service_area_card');
});

test('retail_venue includes opening_hours_strip', function () {
    $recipe = $this->recipe->for(Archetype::RetailVenue);

    expect($recipe['sections'])->toContain('opening_hours_strip');
});

test('premium_specialist includes case_study_teaser', function () {
    $recipe = $this->recipe->for(Archetype::PremiumSpecialist);

    expect($recipe['sections'])->toContain('case_study_teaser');
    expect($recipe['weights']['trust']['emphasise'] ?? null)->toBe('credentials');
});

test('professional_service includes who_we_help_strip', function () {
    $recipe = $this->recipe->for(Archetype::ProfessionalService);

    expect($recipe['sections'])->toContain('who_we_help_strip');
});

test('every recipe starts with hero and ends with cta', function () {
    foreach (Archetype::cases() as $case) {
        $recipe = $this->recipe->for($case);
        expect($recipe['sections'][0])->toBe('hero');
        expect(end($recipe['sections']))->toBe('cta');
    }
});
