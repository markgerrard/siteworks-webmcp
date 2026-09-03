<?php

use App\Models\Site;
use App\Models\SiteMedia;
use App\Models\SiteMediaUsage;
use App\Support\Media\MediaKind;
use App\Support\Media\MediaOrigin;

test('scopeLibrary excludes provisional and soft-deleted rows', function () {
    $site = Site::factory()->create();

    $kept = SiteMedia::factory()->for($site)->create([
        'title' => 'kept',
        'provisional' => false,
    ]);
    SiteMedia::factory()->for($site)->create([
        'title' => 'scratch',
        'provisional' => true,
    ]);
    $trashed = SiteMedia::factory()->for($site)->create([
        'title' => 'gone',
        'provisional' => false,
    ]);
    $trashed->delete();

    $ids = SiteMedia::query()->library()->where('site_id', $site->id)->pluck('id');

    expect($ids->all())->toBe([$kept->id])
        ->and(SiteMedia::withTrashed()->where('site_id', $site->id)->count())->toBe(3);
});

test('isDecorative is true only for an explicit empty alt string', function () {
    $decorative = SiteMedia::factory()->create(['alt_text' => '']);
    $missing = SiteMedia::factory()->create(['alt_text' => null]);
    $labelled = SiteMedia::factory()->create(['alt_text' => 'Workshop floor']);

    expect($decorative->isDecorative())->toBeTrue()
        ->and($missing->isDecorative())->toBeFalse()
        ->and($labelled->isDecorative())->toBeFalse();
});

test('usages relation returns polymorphic site_media_usages rows', function () {
    $site = Site::factory()->create();
    $media = SiteMedia::factory()->for($site)->create();

    $usage = SiteMediaUsage::query()->create([
        'site_media_id' => $media->id,
        'usable_type' => $site->getMorphClass(),
        'usable_id' => $site->id,
        'slot' => 'brand_row',
    ]);

    expect($media->usages)->toHaveCount(1)
        ->and($media->usages->first()->is($usage))->toBeTrue()
        ->and($media->usages->first()->usable->is($site))->toBeTrue();
});

test('new rows default to image kind, uploaded origin, and not provisional', function () {
    $media = SiteMedia::factory()->create();

    expect($media->kind)->toBe(MediaKind::Image)
        ->and($media->origin)->toBe(MediaOrigin::Uploaded)
        ->and($media->provisional)->toBeFalse()
        ->and($media->tags)->toBe([]);
});

test('sites.brand_image_media_id belongs to a library asset', function () {
    $site = Site::factory()->create();
    $media = SiteMedia::factory()->for($site)->create();

    $site->update(['brand_image_media_id' => $media->id]);

    expect($site->fresh()->brandImageMedia->is($media))->toBeTrue();
});
