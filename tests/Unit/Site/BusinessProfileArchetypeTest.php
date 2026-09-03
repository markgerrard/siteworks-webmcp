<?php

use App\Enums\Archetype;
use App\Models\BusinessProfile;
use App\Models\Site;


test('archetype() returns the enum case matching profile_data', function () {
    $site = Site::factory()->create();
    $profile = BusinessProfile::factory()->for($site)->create([
        'profile_data' => ['archetype' => 'premium_specialist'],
    ]);

    expect($profile->archetype())->toBe(Archetype::PremiumSpecialist);
});

test('archetype() falls back to LocalService when the field is missing', function () {
    $site = Site::factory()->create();
    $profile = BusinessProfile::factory()->for($site)->create([
        'profile_data' => ['name' => 'Test Co'],
    ]);

    expect($profile->archetype())->toBe(Archetype::LocalService);
});

test('archetype() falls back to LocalService on an unknown value', function () {
    $site = Site::factory()->create();
    $profile = BusinessProfile::factory()->for($site)->create([
        'profile_data' => ['archetype' => 'neon_futurism'],
    ]);

    expect($profile->archetype())->toBe(Archetype::LocalService);
});

test('archetype() tolerates non-string archetype values', function () {
    $site = Site::factory()->create();
    $profile = BusinessProfile::factory()->for($site)->create([
        'profile_data' => ['archetype' => ['array', 'value']],
    ]);

    expect($profile->archetype())->toBe(Archetype::LocalService);
});
