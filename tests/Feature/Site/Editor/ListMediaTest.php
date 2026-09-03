<?php

use App\Models\SiteMedia;
use App\Models\SiteMediaUsage;
use App\Support\Media\MediaKind;
use App\Support\Media\MediaOrigin;
use Tests\Support\EditorSeeds;

beforeEach(function () {
    config(['editor.operations.enabled' => true, 'editor.agent_tools.enabled' => true]);
});

it('lists library assets for the site and hides provisional and foreign rows', function () {
    [$user, $site] = EditorSeeds::homeWithHero();
    $kept = SiteMedia::factory()->for($site)->create([
        'title' => 'North workshop',
        'kind' => MediaKind::Image,
        'origin' => MediaOrigin::Uploaded,
        'alt_text' => 'Workshop floor',
        'tags' => ['interior'],
    ]);
    SiteMedia::factory()->for($site)->create([
        'title' => 'Scratch',
        'provisional' => true,
    ]);
    SiteMedia::factory()->create(['title' => 'Other site']);

    $result = EditorSeeds::run($user, $site, 'list_media', []);

    expect($result->ok)->toBeTrue()
        ->and(collect($result->data['media'])->pluck('id')->all())->toBe([$kept->id])
        ->and($result->data['media'][0]['title'])->toBe('North workshop')
        ->and($result->data['media'][0]['kind'])->toBe('image')
        ->and($result->data['media'][0]['origin'])->toBe('uploaded')
        ->and($result->data['media'][0]['used'])->toBeFalse();
});

it('filters by kind, usage, and text search like the library grid', function () {
    [$user, $site] = EditorSeeds::homeWithHero();
    $used = SiteMedia::factory()->for($site)->create([
        'title' => 'Brand courtyard',
        'kind' => MediaKind::Image,
        'origin' => MediaOrigin::Generated,
        'alt_text' => 'Cobbled yard',
        'tags' => ['dusk'],
    ]);
    SiteMedia::factory()->for($site)->create([
        'title' => 'Rate card',
        'kind' => MediaKind::Document,
        'origin' => MediaOrigin::Uploaded,
        'tags' => ['docs'],
    ]);
    SiteMediaUsage::query()->create([
        'site_media_id' => $used->id,
        'usable_type' => $site->getMorphClass(),
        'usable_id' => $site->id,
        'slot' => 'brand_row',
    ]);

    $byKind = EditorSeeds::run($user, $site, 'list_media', ['kind' => 'image']);
    $byUsage = EditorSeeds::run($user, $site, 'list_media', ['usage' => 'used']);
    $bySearch = EditorSeeds::run($user, $site, 'list_media', ['q' => 'cobbled']);

    expect($byKind->ok)->toBeTrue()
        ->and(collect($byKind->data['media'])->pluck('title')->all())->toBe(['Brand courtyard'])
        ->and($byUsage->ok)->toBeTrue()
        ->and(collect($byUsage->data['media'])->pluck('id')->all())->toBe([$used->id])
        ->and($byUsage->data['media'][0]['used'])->toBeTrue()
        ->and($bySearch->ok)->toBeTrue()
        ->and(collect($bySearch->data['media'])->pluck('id')->all())->toBe([$used->id]);
});

it('refuses an invalid kind filter', function (string $kind) {
    [$user, $site] = EditorSeeds::homeWithHero();

    $result = EditorSeeds::run($user, $site, 'list_media', ['kind' => $kind]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($result->error['fields']['kind'])->toBe(['must be image']);
})->with(['audio', 'video', 'document']);

it('documents only reachable kind values', function () {
    $schema = app(\App\Services\Site\Editor\Operations\ListMediaOperation::class)->inputSchema();

    expect($schema['properties']['kind']['enum'])->toBe([MediaKind::Image->value]);
});
