<?php

use App\Models\LogoConcept;
use App\Models\Site;

/**
 * Regression coverage for the
 * `logo_concepts_one_selected_per_site` partial unique index +
 * the LogoConcept::deleting hook that clears is_selected before
 * soft-delete.
 *
 * Original bug: soft-deleting a selected logo concept stranded the
 * unique slot — every subsequent attempt to select another concept
 * on the same site failed with `SQLSTATE[23505]`.
 */

it('allows selecting a new concept after the previously selected one is soft-deleted', function () {
    $site = Site::factory()->create();

    $first = LogoConcept::factory()->create([
        'site_id' => $site->id,
        'is_selected' => true,
    ]);
    $second = LogoConcept::factory()->create([
        'site_id' => $site->id,
        'is_selected' => false,
    ]);

    $first->delete();

    $second->is_selected = true;
    $second->save();

    expect($second->fresh()->is_selected)->toBeTrue()
        ->and($first->fresh()->is_selected)->toBeFalse();
});

it('prevents two concurrently selected logos on the same site', function () {
    $site = Site::factory()->create();

    LogoConcept::factory()->create([
        'site_id' => $site->id,
        'is_selected' => true,
    ]);

    expect(fn () => LogoConcept::factory()->create([
        'site_id' => $site->id,
        'is_selected' => true,
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('allows two selected concepts on different sites', function () {
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();

    LogoConcept::factory()->create(['site_id' => $siteA->id, 'is_selected' => true]);
    LogoConcept::factory()->create(['site_id' => $siteB->id, 'is_selected' => true]);

    expect(LogoConcept::where('is_selected', true)->count())->toBe(2);
});

it('clears is_selected on the model before soft-deleting', function () {
    $site = Site::factory()->create();
    $concept = LogoConcept::factory()->create([
        'site_id' => $site->id,
        'is_selected' => true,
    ]);

    $concept->delete();

    expect($concept->fresh()->is_selected)->toBeFalse()
        ->and($concept->fresh()->trashed())->toBeTrue();
});

/*
 * Clicking the ALREADY-selected concept must keep it selected. select()
 * bulk-deselects at the DB level, then re-selects via the in-memory
 * model — which still believes is_selected=true, so Eloquent found no
 * dirty attributes and skipped the write. Net effect: the site's logo
 * silently vanished (zero selected concepts) when an agent clicked the
 * logo that was already active.
 */
it('keeps the logo selected when the selected concept is clicked again', function () {
    \Illuminate\Support\Facades\Storage::fake('s3');
    $staff = \App\Models\User::factory()->staff(\App\Enums\AgentRole::Admin)->create();
    $site = Site::factory()->create(['created_by_user_id' => $staff->id]);
    $concept = LogoConcept::factory()->create([
        'site_id' => $site->id,
        'is_selected' => true,
    ]);

    \Livewire\Livewire::actingAs($staff)
        ->test('logo-picker', ['siteId' => $site->id])
        ->call('select', $concept->id);

    expect($concept->fresh()->is_selected)->toBeTrue()
        ->and(LogoConcept::where('site_id', $site->id)->where('is_selected', true)->count())->toBe(1);
});
