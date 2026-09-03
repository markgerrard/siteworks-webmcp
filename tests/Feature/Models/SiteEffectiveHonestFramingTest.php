<?php

use App\Models\Site;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    config()->set('site.honest_project_framing', false);
});

it('defaults to config value when site override is null', function () {
    $site = Site::factory()->create(['honest_project_framing' => null]);
    expect($site->effectiveHonestFraming())->toBeFalse();

    config()->set('site.honest_project_framing', true);
    expect($site->fresh()->effectiveHonestFraming())->toBeTrue();
});

it('site override=true wins over config=false', function () {
    config()->set('site.honest_project_framing', false);
    $site = Site::factory()->create(['honest_project_framing' => true]);

    expect($site->effectiveHonestFraming())->toBeTrue();
});

it('site override=false wins over config=true', function () {
    config()->set('site.honest_project_framing', true);
    $site = Site::factory()->create(['honest_project_framing' => false]);

    expect($site->effectiveHonestFraming())->toBeFalse();
});
