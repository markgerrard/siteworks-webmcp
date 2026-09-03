<?php

use App\Enums\AgentRole;
use App\Enums\LogoConceptSource;
use App\Models\LogoConcept;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    Redis::command('FLUSHDB');
    Storage::fake('s3');
    $this->staff = User::factory()->staff(AgentRole::Admin)->create();
});

function invertPickerConcept(User $staff, array $overrides = []): array
{
    $site = Site::factory()->create(['created_by_user_id' => $staff->id]);
    $path = "sites/{$site->id}/logo/source.png";
    Storage::disk('s3')->put($path, 'SOURCE-BYTES', 'public');

    $concept = LogoConcept::factory()->create(array_merge([
        'site_id' => $site->id,
        'source' => LogoConceptSource::Generated,
        'version' => 1,
        'path' => $path,
        'is_selected' => true,
    ], $overrides));

    return [$site, $concept];
}

it('shows Make inverted copy on each concept including the selected card', function () {
    [$site] = invertPickerConcept($this->staff);

    Livewire::actingAs($this->staff)
        ->test('logo-picker', ['siteId' => $site->id])
        ->assertSee('Make inverted copy')
        ->assertSee('Selected');
});

it('hides Make inverted copy on concepts that are already inverted', function () {
    [$site] = invertPickerConcept($this->staff, [
        'metadata' => ['transparent' => true, 'variant' => 'inverted'],
    ]);

    Livewire::actingAs($this->staff)
        ->test('logo-picker', ['siteId' => $site->id])
        ->assertDontSee('Make inverted copy');
});

it('shows Use on overlay on transparent concepts and Clear overlay logo when set', function () {
    [$site, $concept] = invertPickerConcept($this->staff, [
        'metadata' => ['transparent' => true],
    ]);

    Livewire::actingAs($this->staff)
        ->test('logo-picker', ['siteId' => $site->id])
        ->assertSee('Use on overlay')
        ->assertDontSee('Clear overlay logo')
        ->call('useOnOverlay', $concept->id)
        ->assertSee('Clear overlay logo');

    expect($site->fresh()->overlay_logo_concept_id)->toBe($concept->id);
});

it('clears the overlay logo through AuthorizesSiteAccess', function () {
    [$site, $concept] = invertPickerConcept($this->staff, [
        'metadata' => ['transparent' => true],
    ]);
    $site->update(['overlay_logo_concept_id' => $concept->id]);

    Livewire::actingAs($this->staff)
        ->test('logo-picker', ['siteId' => $site->id])
        ->call('clearOverlayLogo')
        ->assertHasNoErrors();

    expect($site->fresh()->overlay_logo_concept_id)->toBeNull();
});

it('silently ignores Use on overlay for a concept on another tenant', function () {
    Queue::fake();
    [$site] = invertPickerConcept($this->staff, [
        'metadata' => ['transparent' => true],
    ]);
    $other = Site::factory()->create();
    $foreign = LogoConcept::factory()->create([
        'site_id' => $other->id,
        'path' => "sites/{$other->id}/logo/foreign.png",
        'metadata' => ['transparent' => true],
    ]);

    Livewire::actingAs($this->staff)
        ->test('logo-picker', ['siteId' => $site->id])
        ->call('useOnOverlay', $foreign->id)
        ->assertOk()
        ->assertHasNoErrors();

    Queue::assertNothingPushed();
    expect($site->fresh()->overlay_logo_concept_id)->toBeNull();
});

it('does not show Use on overlay on an opaque concept', function () {
    [$site] = invertPickerConcept($this->staff);

    Livewire::actingAs($this->staff)
        ->test('logo-picker', ['siteId' => $site->id])
        ->assertDontSee('Use on overlay');
});

it('does not set overlay from an opaque concept even if the action is called', function () {
    [$site, $concept] = invertPickerConcept($this->staff);

    Livewire::actingAs($this->staff)
        ->test('logo-picker', ['siteId' => $site->id])
        ->call('useOnOverlay', $concept->id);

    expect($site->fresh()->overlay_logo_concept_id)->toBeNull();
});

it('denies an agent who does not own the site from setting the overlay logo', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    [$site, $concept] = invertPickerConcept($this->staff, [
        'metadata' => ['transparent' => true],
    ]);

    Livewire::actingAs($agent)
        ->test('logo-picker', ['siteId' => $site->id])
        ->call('useOnOverlay', $concept->id);

    expect($site->fresh()->overlay_logo_concept_id)->toBeNull();
});

it('locks pending inverted-copy poll state against client writes', function (string $prop, mixed $value) {
    [$site] = invertPickerConcept($this->staff);

    $component = Livewire::actingAs($this->staff)
        ->test('logo-picker', ['siteId' => $site->id]);

    expect(fn () => $component->set($prop, $value))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    'pendingInvertedIds' => ['pendingInvertedIds', [1]],
    'invertedPollCount' => ['invertedPollCount', 0],
]);
