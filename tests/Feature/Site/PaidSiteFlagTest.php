<?php

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a site is unpaid until it is marked paid', function () {
    // The default matters: every existing site is a speculative preview until
    // someone says otherwise, so the migration must not retro-flag the estate.
    $site = Site::factory()->create();

    expect($site->paid_at)->toBeNull()
        ->and($site->isPaid())->toBeFalse();
});

test('setting paid_at marks the site paid and records when', function () {
    // One column, not a boolean plus a date: two fields that both mean "this
    // customer pays" are exactly the drift the separate-flags decision is
    // already accepting against image_quality_tier. Do not add a third.
    $site = Site::factory()->create(['paid_at' => now()->subDays(3)]);

    expect($site->isPaid())->toBeTrue()
        ->and($site->paid_at)->toBeInstanceOf(Carbon\CarbonInterface::class);
});

test('paid and unpaid sites can be separated for reporting', function () {
    Site::factory()->count(2)->create();
    Site::factory()->count(3)->create(['paid_at' => now()]);

    expect(Site::paid()->count())->toBe(3)
        ->and(Site::unpaid()->count())->toBe(2);
});
