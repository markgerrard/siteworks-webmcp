<?php

use App\Models\Site;
use App\Models\Site\SiteDraft;
use App\Models\SiteMedia;
use App\Models\SiteMediaUsage;
use App\Services\Site\CompositionService;
use App\Support\Media\MediaKind;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EditorSeeds;

beforeEach(function () {
    config(['editor.operations.enabled' => true, 'editor.agent_tools.enabled' => true]);
    Storage::fake('s3');
});

function assignMediaRevision(Site $site): int
{
    app(CompositionService::class)->ensureDraftRow($site, $site->created_by_user_id);

    return (int) SiteDraft::query()->where('site_id', $site->id)->value('admin_revision');
}

it('assigns brand_row media, writes both columns, and registers usage', function () {
    [$user, $site] = EditorSeeds::homeWithHero();
    $key = 'site-media/'.$site->id.'/courtyard.webp';
    Storage::disk('s3')->put($key, 'library-bytes');
    $media = SiteMedia::factory()->for($site)->create([
        's3_key' => $key,
        'kind' => MediaKind::Image,
    ]);
    $compositionRevision = assignMediaRevision($site);

    $result = EditorSeeds::run($user, $site, 'assign_media', [
        'target' => 'brand_row',
        'media_id' => $media->id,
        'composition_revision' => $compositionRevision,
    ]);

    $fresh = $site->fresh();
    expect($result->ok)->toBeTrue()
        ->and($result->data['media_id'])->toBe($media->id)
        ->and($result->data['target'])->toBe('brand_row')
        ->and($fresh->brand_image_media_id)->toBe($media->id)
        ->and($fresh->brand_image_path)->toStartWith('sites/'.$site->id.'/brand/')
        ->and(Storage::disk('s3')->get($fresh->brand_image_path))->toBe('library-bytes')
        ->and(SiteMediaUsage::query()->where('site_media_id', $media->id)->where('slot', 'brand_row')->exists())->toBeTrue();
});

it('returns not_found for media that belongs to another site', function () {
    [$user, $site] = EditorSeeds::homeWithHero();
    $foreign = SiteMedia::factory()->create(['s3_key' => 'other-site/x.webp']);
    $compositionRevision = assignMediaRevision($site);

    $result = EditorSeeds::run($user, $site, 'assign_media', [
        'target' => 'brand_row',
        'media_id' => $foreign->id,
        'composition_revision' => $compositionRevision,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('not_found')
        ->and($site->fresh()->brand_image_media_id)->toBeNull()
        ->and(SiteMediaUsage::query()->exists())->toBeFalse();
});

it('refuses a target outside the brand_row allowlist', function () {
    [$user, $site] = EditorSeeds::homeWithHero();
    $media = SiteMedia::factory()->for($site)->create();
    $compositionRevision = assignMediaRevision($site);

    $result = EditorSeeds::run($user, $site, 'assign_media', [
        'target' => 'hero',
        'media_id' => $media->id,
        'composition_revision' => $compositionRevision,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($site->fresh()->brand_image_media_id)->toBeNull();
});

it('refuses a provisional row', function () {
    [$user, $site] = EditorSeeds::homeWithHero();
    $media = SiteMedia::factory()->for($site)->create(['provisional' => true]);
    $compositionRevision = assignMediaRevision($site);

    $result = EditorSeeds::run($user, $site, 'assign_media', [
        'target' => 'brand_row',
        'media_id' => $media->id,
        'composition_revision' => $compositionRevision,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('not_found');
});
