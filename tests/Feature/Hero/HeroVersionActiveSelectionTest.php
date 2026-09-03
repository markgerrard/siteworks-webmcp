<?php

use App\Models\HeroVersion;
use App\Models\Site;

/**
 * Regression coverage for the hero_versions partial unique indexes
 * (`hero_versions_one_active_per_slot` + the
 * `hero_versions_active_by_run_slot_unique` per-pipeline-run sibling)
 * and the HeroVersion::deleting hook that clears is_active before
 * soft-delete.
 *
 * Mirrors LogoConceptSelectionTest's coverage pattern. Original bug:
 * soft-deleting an active hero version stranded the unique slot —
 * subsequent activations on the same site/page/slot failed with
 * SQLSTATE[23505] until the orphaned row was force-deleted or its
 * is_active was cleared.
 */

it('allows activating a new hero version after the previously active one is soft-deleted', function () {
    $site = Site::factory()->create();

    $first = HeroVersion::factory()->create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'slot' => 'hero',
        'is_active' => true,
    ]);
    $second = HeroVersion::factory()->create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'slot' => 'hero',
        'is_active' => false,
    ]);

    $first->delete();

    $second->is_active = true;
    $second->save();

    expect($second->fresh()->is_active)->toBeTrue()
        ->and($first->fresh()->is_active)->toBeFalse();
});

it('prevents two concurrently active hero versions on the same site/page/slot', function () {
    $site = Site::factory()->create();

    HeroVersion::factory()->create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'slot' => 'hero',
        'is_active' => true,
    ]);

    expect(fn () => HeroVersion::factory()->create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'slot' => 'hero',
        'is_active' => true,
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('allows two active hero versions on different slots of the same page', function () {
    $site = Site::factory()->create();

    HeroVersion::factory()->create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'slot' => 'hero',
        'is_active' => true,
    ]);
    HeroVersion::factory()->create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'slot' => 'intro',
        'is_active' => true,
    ]);

    expect(HeroVersion::where('is_active', true)->count())->toBe(2);
});

it('clears is_active on the model before soft-deleting', function () {
    $site = Site::factory()->create();
    $version = HeroVersion::factory()->create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'slot' => 'hero',
        'is_active' => true,
    ]);

    $version->delete();

    expect($version->fresh()->is_active)->toBeFalse()
        ->and($version->fresh()->trashed())->toBeTrue();
});
