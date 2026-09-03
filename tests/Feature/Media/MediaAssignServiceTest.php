<?php

use App\Models\Site;
use App\Models\SiteMedia;
use App\Models\SiteMediaUsage;
use App\Services\Media\MediaAssignService;

test('assign writes a usage for the model slot and release removes it', function () {
    $site = Site::factory()->create();
    $media = SiteMedia::factory()->for($site)->create();

    $usage = app(MediaAssignService::class)->assign($media, $site, 'brand_row');

    expect($usage)->toBeInstanceOf(SiteMediaUsage::class)
        ->and($usage->site_media_id)->toBe($media->id)
        ->and($usage->usable_type)->toBe($site->getMorphClass())
        ->and($usage->usable_id)->toBe($site->id)
        ->and($usage->slot)->toBe('brand_row')
        ->and($media->fresh()->usages)->toHaveCount(1);

    app(MediaAssignService::class)->release($site, 'brand_row');

    expect(SiteMediaUsage::query()->count())->toBe(0)
        ->and($media->fresh()->usages)->toHaveCount(0);
});

test('assign replaces the media occupying an existing slot', function () {
    $site = Site::factory()->create();
    $first = SiteMedia::factory()->for($site)->create();
    $second = SiteMedia::factory()->for($site)->create();

    app(MediaAssignService::class)->assign($first, $site, 'brand_row');
    app(MediaAssignService::class)->assign($second, $site, 'brand_row');

    expect(SiteMediaUsage::query()->count())->toBe(1)
        ->and(SiteMediaUsage::query()->sole()->site_media_id)->toBe($second->id);
});

test('assign refuses media from a different site', function () {
    $site = Site::factory()->create();
    $foreign = SiteMedia::factory()->create();

    expect(fn () => app(MediaAssignService::class)->assign($foreign, $site, 'brand_row'))
        ->toThrow(InvalidArgumentException::class);
});
