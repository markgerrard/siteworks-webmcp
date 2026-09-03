<?php

use App\Models\BusinessProfile;
use App\Models\Site;
use App\Services\Site\ArchetypeComposer;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('returns false when neither override nor profile hint is set', function () {
    $site = Site::factory()->create(['projects_page_enabled' => null]);
    BusinessProfile::factory()->create(['site_id' => $site->id, 'has_visual_portfolio' => null]);

    expect(app(ArchetypeComposer::class)->includesProjectsPage($site->fresh()))->toBeFalse();
});

it('returns true when profile hint is true and no override', function () {
    $site = Site::factory()->create(['projects_page_enabled' => null]);
    BusinessProfile::factory()->create(['site_id' => $site->id, 'has_visual_portfolio' => true]);

    expect(app(ArchetypeComposer::class)->includesProjectsPage($site->fresh()))->toBeTrue();
});

it('override=true wins over profile hint=false', function () {
    $site = Site::factory()->create(['projects_page_enabled' => true]);
    BusinessProfile::factory()->create(['site_id' => $site->id, 'has_visual_portfolio' => false]);

    expect(app(ArchetypeComposer::class)->includesProjectsPage($site->fresh()))->toBeTrue();
});

it('override=false wins over profile hint=true', function () {
    $site = Site::factory()->create(['projects_page_enabled' => false]);
    BusinessProfile::factory()->create(['site_id' => $site->id, 'has_visual_portfolio' => true]);

    expect(app(ArchetypeComposer::class)->includesProjectsPage($site->fresh()))->toBeFalse();
});

it('returns false when site has no business profile at all', function () {
    $site = Site::factory()->create(['projects_page_enabled' => null]);

    expect(app(ArchetypeComposer::class)->includesProjectsPage($site->fresh()))->toBeFalse();
});
