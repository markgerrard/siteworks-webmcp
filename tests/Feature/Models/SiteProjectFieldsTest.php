<?php

use App\Models\Site;

it('persists projects_page_enabled as nullable boolean', function () {
    $site = Site::factory()->create(['projects_page_enabled' => null]);
    expect($site->fresh()->projects_page_enabled)->toBeNull();

    $site->update(['projects_page_enabled' => true]);
    expect($site->fresh()->projects_page_enabled)->toBeTrue();
});

it('persists project_categories as a string array', function () {
    $site = Site::factory()->create([
        'project_categories' => ['Residential', 'Commercial', 'Heritage'],
    ]);
    expect($site->fresh()->project_categories)->toBe(['Residential', 'Commercial', 'Heritage']);
});
