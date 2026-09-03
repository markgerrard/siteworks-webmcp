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

function pickerConcept(User $staff, array $overrides = []): array
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

it('shows Make transparent copy on each concept including the selected card', function () {
    [$site] = pickerConcept($this->staff);

    Livewire::actingAs($this->staff)
        ->test('logo-picker', ['siteId' => $site->id])
        ->assertSee('Make transparent copy')
        ->assertSee('Selected');
});

it('hides Make transparent copy on concepts that are already transparent', function () {
    [$site] = pickerConcept($this->staff, [
        'metadata' => ['transparent' => true],
    ]);

    Livewire::actingAs($this->staff)
        ->test('logo-picker', ['siteId' => $site->id])
        ->assertDontSee('Make transparent copy')
        ->assertSee('Transparent');
});

it('locks pending transparent-copy poll state against client writes', function (string $prop, mixed $value) {
    [$site] = pickerConcept($this->staff);

    $component = Livewire::actingAs($this->staff)
        ->test('logo-picker', ['siteId' => $site->id]);

    expect(fn () => $component->set($prop, $value))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    'pendingTransparentIds' => ['pendingTransparentIds', [1]],
    'transparentPollCount' => ['transparentPollCount', 0],
]);
