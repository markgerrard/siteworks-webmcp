<?php

use App\Models\GeneratedPage;
use App\Models\Site;

it('cascades soft-delete to children and restores them in lockstep', function () {
    $site = Site::factory()->create();
    GeneratedPage::factory()->count(2)->for($site)->create();

    $site->delete();

    expect($site->fresh()->trashed())->toBeTrue();
    expect(GeneratedPage::where('site_id', $site->id)->count())->toBe(0);
    expect(GeneratedPage::onlyTrashed()->where('site_id', $site->id)->count())->toBe(2);

    $site->restore();

    expect($site->fresh()->trashed())->toBeFalse();
    expect(GeneratedPage::where('site_id', $site->id)->count())->toBe(2);
});

it('skips cascade on force-delete (FK ON DELETE handles the subtree)', function () {
    $site = Site::factory()->create();
    GeneratedPage::factory()->count(2)->for($site)->create();

    $site->forceDelete();

    expect(Site::withTrashed()->find($site->id))->toBeNull();
    expect(GeneratedPage::withTrashed()->where('site_id', $site->id)->count())->toBe(0);
});
