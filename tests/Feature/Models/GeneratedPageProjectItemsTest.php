<?php

use App\Models\GeneratedPage;
use App\Models\ProjectItem;

it('loads only items linked to this page via page_id', function () {
    $page = GeneratedPage::factory()->create(['page_type' => 'projects']);
    $linked = ProjectItem::factory()->count(3)->create([
        'site_id' => $page->site_id,
        'page_id' => $page->id,
    ]);
    ProjectItem::factory()->count(2)->create([
        'site_id' => $page->site_id,
        'page_id' => null,
    ]);

    expect($page->ownedProjectItems()->count())->toBe(3);
});
