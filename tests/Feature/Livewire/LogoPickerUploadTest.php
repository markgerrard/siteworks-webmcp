<?php

use App\Enums\AgentRole;
use App\Enums\LogoConceptSource;
use App\Models\LogoConcept;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('s3');
    $this->staff = User::factory()->staff(AgentRole::Admin)->create();
});

/** Real PNG bytes via Imagick — the container has no GD, so fake()->image() throws. */
function fakePngUpload(string $name = 'client-logo.png'): UploadedFile
{
    $im = new Imagick;
    $im->newImage(60, 20, 'red');
    $im->setImageFormat('png');

    return UploadedFile::fake()->createWithContent($name, $im->getImageBlob());
}

/**
 * "Upload your own logo" — clients that already have a logo shouldn't be
 * forced through AI concepts. An upload becomes a LogoConcept (source:
 * uploaded, version 0 so it always co-shows in the baseline batch) and is
 * auto-selected through the same locked selection path as the grid.
 */
it('stores an uploaded logo as a selected version-0 concept', function () {
    $site = Site::factory()->create(['created_by_user_id' => $this->staff->id]);
    $existing = LogoConcept::factory()->create([
        'site_id' => $site->id,
        'is_selected' => true,
    ]);

    Livewire::actingAs($this->staff)
        ->test('logo-picker', ['siteId' => $site->id])
        ->set('logoUpload', fakePngUpload())
        ->assertHasNoErrors();

    $concept = LogoConcept::where('site_id', $site->id)
        ->where('source', LogoConceptSource::Uploaded)
        ->first();

    expect($concept)->not->toBeNull()
        ->and($concept->version)->toBe(0)
        ->and($concept->is_selected)->toBeTrue()
        ->and($existing->fresh()->is_selected)->toBeFalse();

    expect(Storage::disk('s3')->exists($concept->path))->toBeTrue();
});

it('rejects svg uploads instead of hosting them on Spaces', function () {
    $site = Site::factory()->create(['created_by_user_id' => $this->staff->id]);

    Livewire::actingAs($this->staff)
        ->test('logo-picker', ['siteId' => $site->id])
        ->set('logoUpload', UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'
        ))
        ->assertHasErrors(['logoUpload']);

    expect(LogoConcept::where('site_id', $site->id)->count())->toBe(0)
        ->and(Storage::disk('s3')->allFiles())->toBeEmpty();
});

it('rejects oversized uploads', function () {
    $site = Site::factory()->create(['created_by_user_id' => $this->staff->id]);

    Livewire::actingAs($this->staff)
        ->test('logo-picker', ['siteId' => $site->id])
        ->set('logoUpload', UploadedFile::fake()->create('huge.png', 5000, 'image/png'))
        ->assertHasErrors(['logoUpload']);

    expect(LogoConcept::where('site_id', $site->id)->count())->toBe(0);
});
