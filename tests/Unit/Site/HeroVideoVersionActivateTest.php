<?php

use App\Models\HeroVideoVersion;
use App\Models\Site;
use Illuminate\Support\Facades\Log;


it('activate() always re-marks this row as active even when in-memory is_active is stale', function () {
    $site = Site::factory()->create();

    $a = HeroVideoVersion::create([
        'site_id' => $site->id,
        's3_key' => 'tests/a.mp4',
        'prompt' => 'a',
        'provider' => 'test',
        'resolution' => '720p',
        'duration_secs' => 5,

        'source' => 'ai_generated',
        'metadata' => [],
        'is_active' => true,
    ]);

    // Simulate a concurrent activation flipping A to inactive in the DB
    // while our $a model instance still carries is_active=true.
    HeroVideoVersion::whereKey($a->id)->update(['is_active' => false]);
    expect($a->is_active)->toBeTrue(); // stale in memory
    expect(HeroVideoVersion::find($a->id)->is_active)->toBeFalse(); // truth in DB

    // activate() must still produce A=active in the DB (and not skip the
    // re-mark because of the stale in-memory flag).
    $a->activate();

    expect(HeroVideoVersion::find($a->id)->is_active)->toBeTrue();
});

it('activate() leaves exactly one active row per site under back-to-back activations', function () {
    $site = Site::factory()->create();

    $a = HeroVideoVersion::create([
        'site_id' => $site->id, 's3_key' => 'a.mp4', 'prompt' => 'a',
        'provider' => 't', 'resolution' => '720p', 'duration_secs' => 5,
'source' => 'ai_generated', 'metadata' => [], 'is_active' => false,
    ]);
    $b = HeroVideoVersion::create([
        'site_id' => $site->id, 's3_key' => 'b.mp4', 'prompt' => 'b',
        'provider' => 't', 'resolution' => '720p', 'duration_secs' => 5,
'source' => 'ai_generated', 'metadata' => [], 'is_active' => false,
    ]);

    $a->activate();
    $b->activate();

    expect(HeroVideoVersion::where('site_id', $site->id)->where('is_active', true)->count())->toBe(1);
    expect(HeroVideoVersion::find($b->id)->is_active)->toBeTrue();
    expect(HeroVideoVersion::find($a->id)->is_active)->toBeFalse();
});

it('activate() folds extraSiteUpdates into the same locked transaction', function () {
    $site = Site::factory()->create();
    $v = HeroVideoVersion::create([
        'site_id' => $site->id, 's3_key' => 'v.mp4', 'prompt' => 'v',
        'provider' => 't', 'resolution' => '720p', 'duration_secs' => 5,
'source' => 'ai_generated', 'metadata' => [], 'is_active' => false,
    ]);

    $v->activate([
        'home_hero_scene' => ['kind' => 'video', 'composite_video_id' => $v->id],
    ]);

    $site->refresh();
    expect($site->home_hero_video_path)->toBe('v.mp4');
    expect($site->home_hero_scene)->toMatchArray(['kind' => 'video', 'composite_video_id' => $v->id]);
});

it('activate() writes no site columns when the version row has been deleted', function () {
    $site = Site::factory()->create([
        'home_hero_video_path' => 'keep/live.mp4',
        'home_hero_video_provider' => 'keep-provider',
        'home_hero_video_tier' => '1080p',
        'home_hero_video_prompt' => 'keep-prompt',
        'home_hero_video_status' => 'ready',
        'home_hero_scene' => null,
    ]);
    $live = HeroVideoVersion::create([
        'site_id' => $site->id, 's3_key' => 'keep/live.mp4', 'prompt' => 'keep-prompt',
        'provider' => 'keep-provider', 'resolution' => '1080p', 'duration_secs' => 5,
'source' => 'ai_generated', 'metadata' => [], 'is_active' => true,
    ]);
    $stale = HeroVideoVersion::create([
        'site_id' => $site->id, 's3_key' => 'gone/stale.mp4', 'prompt' => 'stale-prompt',
        'provider' => 'stale-provider', 'resolution' => '720p', 'duration_secs' => 5,
'source' => 'ai_generated', 'metadata' => [], 'is_active' => false,
    ]);
    HeroVideoVersion::whereKey($stale->id)->delete();
    expect(HeroVideoVersion::find($stale->id))->toBeNull();

    Log::spy();
    $stale->activate([
        'home_hero_scene' => ['kind' => 'video', 'composite_video_id' => $stale->id],
    ]);

    $site->refresh();
    expect($site->home_hero_video_path)->toBe('keep/live.mp4')
        ->and($site->home_hero_video_provider)->toBe('keep-provider')
        ->and($site->home_hero_video_tier)->toBe('1080p')
        ->and($site->home_hero_video_prompt)->toBe('keep-prompt')
        ->and($site->home_hero_video_status)->toBe('ready')
        ->and($site->home_hero_scene)->toBeNull();
    expect(HeroVideoVersion::find($live->id)->is_active)->toBeTrue();
    Log::shouldHaveReceived('warning')->once();
});

it('activate() still publishes site columns when the row was concurrently deactivated', function () {
    $site = Site::factory()->create([
        'home_hero_video_path' => 'old/live.mp4',
        'home_hero_video_provider' => 'old-provider',
        'home_hero_video_tier' => '480p',
        'home_hero_video_prompt' => 'old-prompt',
        'home_hero_video_status' => 'generating',
    ]);
    $version = HeroVideoVersion::create([
        'site_id' => $site->id,
        's3_key' => 'tests/reactivated.mp4',
        'prompt' => 'new-prompt',
        'provider' => 'new-provider',
        'resolution' => '720p',
        'duration_secs' => 5,

        'source' => 'ai_generated',
        'metadata' => [],
        'is_active' => true,
    ]);

    HeroVideoVersion::whereKey($version->id)->update(['is_active' => false]);
    expect($version->is_active)->toBeTrue();
    expect(HeroVideoVersion::find($version->id)->is_active)->toBeFalse();

    $version->activate();

    expect(HeroVideoVersion::find($version->id)->is_active)->toBeTrue();
    $site->refresh();
    expect($site->home_hero_video_path)->toBe('tests/reactivated.mp4')
        ->and($site->home_hero_video_provider)->toBe('new-provider')
        ->and($site->home_hero_video_tier)->toBe('720p')
        ->and($site->home_hero_video_prompt)->toBe('new-prompt')
        ->and($site->home_hero_video_status)->toBe('ready');
});
