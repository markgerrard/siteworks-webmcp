<?php

use App\Models\BusinessProfile;
use App\Models\Site;
use App\Services\Site\CountryResolver;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->resolver = app(CountryResolver::class);
});

it('returns Australia for sites with a +61 phone', function () {
    $site = Site::factory()->create(['location' => 'Perth']);
    BusinessProfile::factory()->create([
        'site_id' => $site->id,
        'profile_data' => [
            'contact' => ['phones' => ['+61 400 280 806']],
        ],
    ]);

    expect($this->resolver->resolveLabel($site->fresh()))->toBe('Australia');
});

it('returns Australia for ambiguous "Perth" when audience names Tasmania', function () {
    $site = Site::factory()->create(['location' => 'Perth']);
    BusinessProfile::factory()->create([
        'site_id' => $site->id,
        'profile_data' => [
            'audience' => 'Commercial and industrial clients across Tasmania',
        ],
    ]);

    expect($this->resolver->resolveLabel($site->fresh()))->toBe('Australia');
});

it('returns UK for ambiguous "Perth" when audience names Scotland', function () {
    $site = Site::factory()->create(['location' => 'Perth']);
    BusinessProfile::factory()->create([
        'site_id' => $site->id,
        'profile_data' => [
            'audience' => 'Domestic clients across Scotland and the Highlands',
        ],
    ]);

    expect($this->resolver->resolveLabel($site->fresh()))->toBe('UK');
});

it('returns New Zealand for an Auckland-anchored site', function () {
    $site = Site::factory()->create(['location' => 'Auckland']);

    expect($this->resolver->resolveLabel($site))->toBe('New Zealand');
});

it('returns Ireland for a Dublin-anchored site with +353 phone', function () {
    $site = Site::factory()->create(['location' => 'Dublin']);
    BusinessProfile::factory()->create([
        'site_id' => $site->id,
        'profile_data' => [
            'contact' => ['phones' => ['+353 1 555 0123']],
        ],
    ]);

    expect($this->resolver->resolveLabel($site->fresh()))->toBe('Ireland');
});

it('falls back to UK when no signal is available', function () {
    $site = Site::factory()->create(['location' => 'Somewhere']);

    expect($this->resolver->resolveLabel($site))->toBe('UK');
});

it('honours an explicit site.country override when present', function () {
    $site = Site::factory()->create(['location' => 'Perth']);
    // The country column may not exist yet on every environment;
    // simulate the override via the model's accessor regardless.
    $site->setAttribute('country', 'au');

    expect($this->resolver->resolveLabel($site))->toBe('Australia');
});

it('returns UK for Belfast (Northern Ireland is part of the UK)', function () {
    $site = Site::factory()->create(['location' => 'Belfast']);

    expect($this->resolver->resolveLabel($site))->toBe('UK');
});

it('returns UK for "Perth, Scotland" — UK token in location beats bare AU match', function () {
    $site = Site::factory()->create(['location' => 'Perth, Scotland']);

    expect($this->resolver->resolveLabel($site))->toBe('UK');
});

it('returns UK for "Newcastle upon Tyne"', function () {
    $site = Site::factory()->create(['location' => 'Newcastle upon Tyne']);

    expect($this->resolver->resolveLabel($site))->toBe('UK');
});
