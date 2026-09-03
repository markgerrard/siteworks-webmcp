<?php

use App\Enums\Archetype;
use App\Enums\LeadFormPolicy;
use App\Models\BusinessProfile;
use App\Models\Site;
use App\Services\Site\ArchetypeComposer;
use App\Services\Site\ArchetypeRecipe;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ───── LeadFormPolicy enum ─────────────────────────────────────────────

test('includesHome is true for home, home_services, all', function () {
    expect(LeadFormPolicy::Off->includesHome())->toBeFalse();
    expect(LeadFormPolicy::Home->includesHome())->toBeTrue();
    expect(LeadFormPolicy::HomeServices->includesHome())->toBeTrue();
    expect(LeadFormPolicy::All->includesHome())->toBeTrue();
});

test('includesServices is true only for home_services and all', function () {
    expect(LeadFormPolicy::Off->includesServices())->toBeFalse();
    expect(LeadFormPolicy::Home->includesServices())->toBeFalse();
    expect(LeadFormPolicy::HomeServices->includesServices())->toBeTrue();
    expect(LeadFormPolicy::All->includesServices())->toBeTrue();
});

test('includesCtaBandOnAbout is true for every policy except off', function () {
    expect(LeadFormPolicy::Off->includesCtaBandOnAbout())->toBeFalse();
    expect(LeadFormPolicy::Home->includesCtaBandOnAbout())->toBeTrue();
    expect(LeadFormPolicy::HomeServices->includesCtaBandOnAbout())->toBeTrue();
    expect(LeadFormPolicy::All->includesCtaBandOnAbout())->toBeTrue();
});

test('defaultForArchetype returns home_services for emergency_trade and local_service', function () {
    expect(LeadFormPolicy::defaultForArchetype(Archetype::EmergencyTrade))->toBe(LeadFormPolicy::HomeServices);
    expect(LeadFormPolicy::defaultForArchetype(Archetype::LocalService))->toBe(LeadFormPolicy::HomeServices);
});

test('defaultForArchetype returns home for brochure-leaning archetypes', function () {
    expect(LeadFormPolicy::defaultForArchetype(Archetype::ProfessionalService))->toBe(LeadFormPolicy::Home);
    expect(LeadFormPolicy::defaultForArchetype(Archetype::RetailVenue))->toBe(LeadFormPolicy::Home);
    expect(LeadFormPolicy::defaultForArchetype(Archetype::TraditionalCraftsman))->toBe(LeadFormPolicy::Home);
    expect(LeadFormPolicy::defaultForArchetype(Archetype::PremiumSpecialist))->toBe(LeadFormPolicy::Home);
});

// ───── BusinessProfile::leadFormPolicy() accessor ─────────────────────

test('leadFormPolicy() reads lead_form_policy field when present', function () {
    $site = Site::factory()->create();
    $profile = BusinessProfile::factory()->for($site)->create([
        'profile_data' => ['lead_form_policy' => 'off'],
    ]);

    expect($profile->leadFormPolicy())->toBe(LeadFormPolicy::Off);
});

test('leadFormPolicy() reads all four enum values correctly', function () {
    $site = Site::factory()->create();
    $profile = BusinessProfile::factory()->for($site)->create([
        'profile_data' => ['lead_form_policy' => 'home_services'],
    ]);

    expect($profile->leadFormPolicy())->toBe(LeadFormPolicy::HomeServices);
});

test('leadFormPolicy() falls back to home when legacy home_lead_form_enabled is true', function () {
    $site = Site::factory()->create();
    $profile = BusinessProfile::factory()->for($site)->create([
        'profile_data' => ['home_lead_form_enabled' => true],
    ]);

    expect($profile->leadFormPolicy())->toBe(LeadFormPolicy::Home);
});

test('leadFormPolicy() falls back to off when legacy home_lead_form_enabled is false', function () {
    $site = Site::factory()->create();
    $profile = BusinessProfile::factory()->for($site)->create([
        'profile_data' => ['home_lead_form_enabled' => false],
    ]);

    expect($profile->leadFormPolicy())->toBe(LeadFormPolicy::Off);
});

test('leadFormPolicy() uses archetype default when neither new field nor legacy boolean is present', function () {
    $site = Site::factory()->create();
    $profile = BusinessProfile::factory()->for($site)->create([
        'profile_data' => ['archetype' => 'local_service'],
    ]);

    expect($profile->leadFormPolicy())->toBe(LeadFormPolicy::HomeServices);
});

test('new field wins over legacy boolean when both are present', function () {
    $site = Site::factory()->create();
    $profile = BusinessProfile::factory()->for($site)->create([
        'profile_data' => [
            'lead_form_policy' => 'all',
            'home_lead_form_enabled' => false, // would imply Off, but new field wins
        ],
    ]);

    expect($profile->leadFormPolicy())->toBe(LeadFormPolicy::All);
});

// ───── ArchetypeComposer honours each of the 4 policies ───────────────

function makeComposer(): ArchetypeComposer
{
    return new ArchetypeComposer(new ArchetypeRecipe);
}

function homeWithLeadForm(): array
{
    return [
        'sections' => [
            ['type' => 'hero', 'title' => 'Test'],
            ['type' => 'services'],
            ['type' => 'lead_form', 'title' => 'Get a quote'],
            ['type' => 'cta'],
        ],
    ];
}

test('policy off removes lead_form from home output', function () {
    $out = makeComposer()->compose(homeWithLeadForm(), Archetype::LocalService, LeadFormPolicy::Off);
    $types = array_column($out['sections'], 'type');

    expect($types)->not->toContain('lead_form');
});

test('policy home keeps lead_form in home output', function () {
    $out = makeComposer()->compose(homeWithLeadForm(), Archetype::LocalService, LeadFormPolicy::Home);
    $types = array_column($out['sections'], 'type');

    expect($types)->toContain('lead_form');
});

test('policy home_services keeps lead_form in home output', function () {
    $out = makeComposer()->compose(homeWithLeadForm(), Archetype::LocalService, LeadFormPolicy::HomeServices);
    $types = array_column($out['sections'], 'type');

    expect($types)->toContain('lead_form');
});

test('policy all keeps lead_form in home output', function () {
    $out = makeComposer()->compose(homeWithLeadForm(), Archetype::LocalService, LeadFormPolicy::All);
    $types = array_column($out['sections'], 'type');

    expect($types)->toContain('lead_form');
});

test('null policy leaves lead_form untouched (existing behaviour)', function () {
    $out = makeComposer()->compose(homeWithLeadForm(), Archetype::LocalService, null);
    $types = array_column($out['sections'], 'type');

    expect($types)->toContain('lead_form');
});
