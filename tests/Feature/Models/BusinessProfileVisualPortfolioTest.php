<?php

use App\Models\BusinessProfile;

it('persists has_visual_portfolio as nullable boolean', function () {
    $profile = BusinessProfile::factory()->create(['has_visual_portfolio' => null]);
    expect($profile->fresh()->has_visual_portfolio)->toBeNull();

    $profile->update(['has_visual_portfolio' => true]);
    expect($profile->fresh()->has_visual_portfolio)->toBeTrue();

    $profile->update(['has_visual_portfolio' => false]);
    expect($profile->fresh()->has_visual_portfolio)->toBeFalse();
});
