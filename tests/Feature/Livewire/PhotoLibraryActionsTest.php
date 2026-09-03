<?php

use App\Enums\AgentRole;
use App\Enums\HeroVersionSource;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\ImportedMedia;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\SiteMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Redis::command('FLUSHDB');
    Storage::fake('s3');
    $this->staff = User::factory()->staff(AgentRole::Admin)->create();
});

it('uses an imported photo as an inactive hero picker variant', function () {
    $site = setupPhotoLibrarySite();
    $home = $site->generatedPages()->where('page_type', 'home')->first();
    $media = createImportedPhoto($site, '301');

    Livewire::actingAs($this->staff)
        ->test('personalise-tab', ['siteId' => $site->id])
        ->call('useAsHero', $media->id, $home->id);

    $hero = HeroVersion::where('site_id', $site->id)->first();
    expect($hero)->not->toBeNull()
        ->and($hero->source)->toBe(HeroVersionSource::FacebookImport)
        ->and($hero->is_active)->toBeFalse()
        ->and($hero->page_type)->toBe('home')
        ->and($media->fresh()->assigned_to)->toBe('hero')
        ->and($media->fresh()->assigned_page_id)->toBe($home->id);
});

it('discards an imported photo without deleting it', function () {
    $site = setupPhotoLibrarySite();
    $media = createImportedPhoto($site, '601');

    Livewire::actingAs($this->staff)
        ->test('personalise-tab', ['siteId' => $site->id])
        ->call('discardImportedPhoto', $media->id);

    expect($media->fresh())->not->toBeNull()
        ->and($media->fresh()->assigned_to)->toBe('discarded');
});

function setupPhotoLibrarySite(array $attributes = []): Site
{
    $site = Site::factory()->create(array_merge(['business_type' => 'Tiler'], $attributes));

    GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'nav_label' => 'Home',
        'content_data' => [],
        'sort_order' => 0,
        'version' => 1,
    ]);

    GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'projects',
        'nav_label' => 'Our Work',
        'content_data' => [],
        'sort_order' => 10,
        'version' => 1,
    ]);

    return $site;
}

function createImportedPhoto(Site $site, string $fbid, ?string $bytes = null): ImportedMedia
{
    $bytes ??= "bytes-{$fbid}";
    $path = "sites/{$site->id}/imported/facebook/{$fbid}.jpg";
    Storage::disk('s3')->put($path, $bytes, 'public');

    return ImportedMedia::create([
        'site_id' => $site->id,
        'source' => 'facebook',
        'external_id' => $fbid,
        'url' => Storage::disk('s3')->url($path),
        'width' => 1024,
        'height' => 512,
        'caption' => "Photo {$fbid}",
        'imported_at' => now(),
        'placement' => [
            's3_key' => $path,
            'alt_text' => "Alt {$fbid}",
            'source_permalink' => "https://www.facebook.com/photo/?fbid={$fbid}",
        ],
    ]);
}
