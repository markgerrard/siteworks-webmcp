<?php

use App\Models\BusinessProfile;
use App\Models\Site;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('writes the resolved country to sites.country for sites that have none', function () {
    $tasmania = Site::factory()->create(['location' => 'Hobart', 'country' => null]);
    BusinessProfile::factory()->create([
        'site_id' => $tasmania->id,
        'profile_data' => ['audience' => 'commercial clients across Tasmania'],
    ]);

    $cornwall = Site::factory()->create(['location' => 'Penzance', 'country' => null]);

    $this->artisan('site:backfill-country')->assertSuccessful();

    expect($tasmania->fresh()->country)->toBe('Australia');
    expect($cornwall->fresh()->country)->toBe('UK');
});

it('skips sites that already have country set unless --overwrite is given', function () {
    $site = Site::factory()->create(['location' => 'Penzance', 'country' => 'UK']);

    // Force a value that wouldn't naturally resolve, to prove --overwrite changes it.
    $site->update(['country' => 'Other']);

    $this->artisan('site:backfill-country')->assertSuccessful();
    expect($site->fresh()->country)->toBe('Other');

    $this->artisan('site:backfill-country', ['--overwrite' => true])->assertSuccessful();
    expect($site->fresh()->country)->toBe('UK');
});

it('respects --dry-run by reporting changes without persisting them', function () {
    $site = Site::factory()->create(['location' => 'Auckland', 'country' => null]);

    $this->artisan('site:backfill-country', ['--dry-run' => true])->assertSuccessful();

    expect($site->fresh()->country)->toBeNull();
});

it('targets a single site when --site=ID is given', function () {
    $a = Site::factory()->create(['location' => 'Penzance', 'country' => null]);
    $b = Site::factory()->create(['location' => 'Auckland', 'country' => null]);

    $this->artisan('site:backfill-country', ['--site' => $a->id])->assertSuccessful();

    expect($a->fresh()->country)->toBe('UK');
    expect($b->fresh()->country)->toBeNull();
});
