<?php

use App\Enums\AgentRole;
use App\Models\Site;
use App\Models\SiteMedia;
use App\Models\User;
use App\Support\Media\MediaKind;
use App\Support\Media\MediaOrigin;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function mediaPickerAgentSite(): array
{
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    return [$agent, $site];
}

function mediaPickerPngUpload(string $name = 'picker.png'): UploadedFile
{
    $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAQAAAAEAQMAAACTPww9AAAAIGNIUk0AAHomAACAhAAA+gAAAIDoAAB1MAAA6mAAADqYAAAXcJy6UTwAAAAGUExURf8AAP///0EdNBEAAAABYktHRAH/Ai3eAAAAB3RJTUUH6ggcBTkHx5/rUAAAAAtJREFUCNdjYIAAAAAIAAEvIN0xAAAAAElFTkSuQmCC');

    return UploadedFile::fake()->createWithContent($name, $bytes);
}

beforeEach(function () {
    Storage::fake(config('filesystems.default'));
});

it('opens a modal with Library and Upload tabs and lists selectable library images', function () {
    [$agent, $site] = mediaPickerAgentSite();
    SiteMedia::factory()->for($site)->create([
        'title' => 'Courtyard',
        'kind' => MediaKind::Image,
        'origin' => MediaOrigin::Uploaded,
    ]);
    SiteMedia::factory()->for($site)->create([
        'title' => 'Rate card',
        'kind' => MediaKind::Document,
    ]);
    SiteMedia::factory()->for($site)->create([
        'title' => 'Scratch',
        'provisional' => true,
        'kind' => MediaKind::Image,
    ]);

    Livewire::actingAs($agent)
        ->test('media.picker', [
            'siteId' => $site->id,
            'model' => 'brandImageMediaId',
            'kinds' => 'image',
            'slotLabel' => 'Brand row',
        ])
        ->call('openPicker')
        ->assertSee('Library')
        ->assertSee('Upload')
        ->assertDontSee('Generate')
        ->assertSee('Courtyard')
        ->assertDontSee('Rate card')
        ->assertDontSee('Scratch');
});

it('defaults the picker kinds filter to image', function () {
    [$agent, $site] = mediaPickerAgentSite();

    Livewire::actingAs($agent)
        ->test('media.picker', ['siteId' => $site->id])
        ->assertSet('kinds', 'image');
});

it('emits media-selected with the chosen library id', function () {
    [$agent, $site] = mediaPickerAgentSite();
    $media = SiteMedia::factory()->for($site)->create(['title' => 'Courtyard']);

    Livewire::actingAs($agent)
        ->test('media.picker', ['siteId' => $site->id, 'model' => 'brandImageMediaId'])
        ->call('openPicker')
        ->call('selectMedia', $media->id)
        ->assertDispatched('media-selected');
});

it('uploads on the Upload tab and then selects the new asset', function () {
    [$agent, $site] = mediaPickerAgentSite();

    $component = Livewire::actingAs($agent)
        ->test('media.picker', ['siteId' => $site->id, 'model' => 'brandImageMediaId'])
        ->call('openPicker')
        ->call('setTab', 'upload')
        ->set('uploads', [mediaPickerPngUpload('brand-bg.png')])
        ->assertHasNoErrors()
        ->assertDispatched('media-selected');

    expect(SiteMedia::query()->where('site_id', $site->id)->count())->toBe(1)
        ->and(SiteMedia::query()->where('site_id', $site->id)->sole()->title)->toBe('brand-bg');
});

it('Keep on a provisional image selects it', function () {
    [$agent, $site] = mediaPickerAgentSite();
    $scratch = SiteMedia::factory()->for($site)->create([
        'title' => 'AI courtyard',
        'prompt' => 'a cobbled courtyard at dusk',
        'provisional' => true,
        'metadata' => ['aspect' => '16:9'],
    ]);

    Livewire::actingAs($agent)
        ->test('media.picker', ['siteId' => $site->id, 'model' => 'brandImageMediaId', 'aspect' => '16:9'])
        ->call('openPicker')
        ->call('keepAndSelect', $scratch->id)
        ->assertDispatched('media-selected');

    expect($scratch->fresh()->provisional)->toBeFalse();
});
