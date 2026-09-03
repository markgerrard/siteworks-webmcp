<?php

use App\Enums\AgentRole;
use App\Models\Site;
use App\Models\SiteMedia;
use App\Models\User;
use App\Services\Media\MediaAssignService;
use App\Support\Media\MediaKind;
use App\Support\Media\MediaOrigin;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function mediaLibraryAgentSite(): array
{
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    return [$agent, $site];
}

function mediaLibraryPngUpload(string $name = 'workshop-floor.png'): UploadedFile
{
    $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAQAAAAEAQMAAACTPww9AAAAIGNIUk0AAHomAACAhAAA+gAAAIDoAAB1MAAA6mAAADqYAAAXcJy6UTwAAAAGUExURf8AAP///0EdNBEAAAABYktHRAH/Ai3eAAAAB3RJTUUH6ggcBTkHx5/rUAAAAAtJREFUCNdjYIAAAAAIAAEvIN0xAAAAAElFTkSuQmCC');

    return UploadedFile::fake()->createWithContent($name, $bytes);
}

beforeEach(function () {
    Storage::fake(config('filesystems.default'));
});

it('forbids mount when the agent cannot access the site', function () {
    [$owner, $site] = mediaLibraryAgentSite();
    $outsider = User::factory()->staff(AgentRole::Agent)->create();

    Livewire::actingAs($outsider)
        ->test('media.library', ['siteId' => $site->id])
        ->assertStatus(403);
});

it('renders library items with title, kind, origin, and dimensions, and hides provisional rows', function () {
    [$agent, $site] = mediaLibraryAgentSite();
    SiteMedia::factory()->for($site)->create([
        'title' => 'North workshop',
        'kind' => MediaKind::Image,
        'origin' => MediaOrigin::Uploaded,
        'width' => 1600,
        'height' => 900,
        's3_key' => 'site-media/north.webp',
        'url' => 'https://cdn.example/north.webp',
    ]);
    SiteMedia::factory()->for($site)->create([
        'title' => 'Scratch generation',
        'provisional' => true,
    ]);

    Livewire::actingAs($agent)
        ->test('media.library', ['siteId' => $site->id])
        ->assertSee('North workshop')
        ->assertSee('image')
        ->assertSee('uploaded')
        ->assertSee('1600')
        ->assertSee('900')
        ->assertDontSee('Scratch generation');
});

it('filters the grid by kind, origin, tag, usage, and text search', function () {
    [$agent, $site] = mediaLibraryAgentSite();
    $used = SiteMedia::factory()->for($site)->create([
        'title' => 'Brand courtyard',
        'kind' => MediaKind::Image,
        'origin' => MediaOrigin::Generated,
        'alt_text' => 'Cobbled yard',
        'tags' => ['exterior', 'dusk'],
    ]);
    SiteMedia::factory()->for($site)->create([
        'title' => 'Rate card',
        'kind' => MediaKind::Document,
        'origin' => MediaOrigin::Uploaded,
        'alt_text' => 'PDF',
        'tags' => ['docs'],
    ]);
    app(MediaAssignService::class)->assign($used, $site, 'brand_row');

    Livewire::actingAs($agent)
        ->test('media.library', ['siteId' => $site->id])
        ->set('kind', 'image')
        ->assertSee('Brand courtyard')
        ->assertDontSee('Rate card')
        ->set('kind', '')
        ->set('origin', 'uploaded')
        ->assertSee('Rate card')
        ->assertDontSee('Brand courtyard')
        ->set('origin', '')
        ->set('tag', 'dusk')
        ->assertSee('Brand courtyard')
        ->assertDontSee('Rate card')
        ->set('tag', '')
        ->set('usage', 'used')
        ->assertSee('Brand courtyard')
        ->assertDontSee('Rate card')
        ->set('usage', 'unused')
        ->assertSee('Rate card')
        ->assertDontSee('Brand courtyard')
        ->set('usage', '')
        ->set('search', 'cobbled')
        ->assertSee('Brand courtyard')
        ->assertDontSee('Rate card');
});

it('uploads dropped files into the library', function () {
    [$agent, $site] = mediaLibraryAgentSite();

    Livewire::actingAs($agent)
        ->test('media.library', ['siteId' => $site->id])
        ->set('uploads', [mediaLibraryPngUpload('yard.png')])
        ->assertHasNoErrors()
        ->assertSee('yard');

    $media = SiteMedia::query()->where('site_id', $site->id)->sole();
    expect($media->title)->toBe('yard')
        ->and($media->provisional)->toBeFalse()
        ->and($media->kind)->toBe(MediaKind::Image);
});

it('saves title, decorative empty alt, and tags from the edit drawer and lists usages', function () {
    [$agent, $site] = mediaLibraryAgentSite();
    $media = SiteMedia::factory()->for($site)->create([
        'title' => 'Old title',
        'alt_text' => 'Workshop',
        'tags' => [],
    ]);
    app(MediaAssignService::class)->assign($media, $site, 'brand_row');

    Livewire::actingAs($agent)
        ->test('media.library', ['siteId' => $site->id])
        ->call('openEdit', $media->id)
        ->assertSee('Used in')
        ->assertSee('brand_row')
        ->set('editTitle', 'North workshop')
        ->set('editDecorative', true)
        ->set('tagInput', 'interior')
        ->call('addTag')
        ->call('saveEdit')
        ->assertHasNoErrors()
        ->assertSee('North workshop');

    $fresh = $media->fresh();
    expect($fresh->title)->toBe('North workshop')
        ->and($fresh->alt_text)->toBe('')
        ->and($fresh->isDecorative())->toBeTrue()
        ->and($fresh->tags)->toBe(['interior']);
});

it('deletes unused media and surfaces the in-use refusal', function () {
    [$agent, $site] = mediaLibraryAgentSite();
    $unused = SiteMedia::factory()->for($site)->create(['title' => 'Spare file']);
    $used = SiteMedia::factory()->for($site)->create(['title' => 'Pinned brand']);
    app(MediaAssignService::class)->assign($used, $site, 'brand_row');

    $component = Livewire::actingAs($agent)
        ->test('media.library', ['siteId' => $site->id])
        ->call('deleteMedia', $unused->id)
        ->assertHasNoErrors()
        ->assertDontSee('Spare file');

    expect(SiteMedia::query()->whereKey($unused->id)->exists())->toBeFalse();

    $component->call('openEdit', $used->id)->call('deleteMedia', $used->id);
    expect(SiteMedia::query()->whereKey($used->id)->exists())->toBeTrue();
    $component->assertSee('cannot be deleted')->assertSee('brand_row');
});

it('offers only reachable kind filter options', function () {
    [$agent, $site] = mediaLibraryAgentSite();

    $options = Livewire::actingAs($agent)
        ->test('media.library', ['siteId' => $site->id])
        ->instance()
        ->kindOptions();

    expect($options)->toBe([MediaKind::Image->value])
        ->and($options)->not->toContain(MediaKind::Video->value)
        ->and($options)->not->toContain(MediaKind::Document->value);
});

it('does not list another site’s media', function () {
    [$agent, $site] = mediaLibraryAgentSite();
    SiteMedia::factory()->create(['title' => 'Foreign asset']);
    SiteMedia::factory()->for($site)->create(['title' => 'Ours']);

    Livewire::actingAs($agent)
        ->test('media.library', ['siteId' => $site->id])
        ->assertSee('Ours')
        ->assertDontSee('Foreign asset');
});
