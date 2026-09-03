<?php

use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\HeroVideoVersion;
use App\Models\Preview;
use App\Models\Site\SiteDraft;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\Editor\DraftAssetSelections;
use App\Services\Site\HeroResolution;
use App\Services\Site\PageRenderer;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EditorSeeds;

/**
 * @return array{0: \App\Models\Site, 1: GeneratedPage}
 */
function publishedHeroPage(array $siteAttributes = []): array
{
    [, $site, $page] = EditorSeeds::homeWithHero();
    $site->update($siteAttributes);

    $composition = [
        'nav' => ['items' => []],
        'footer' => ['columns' => [], 'show_credit' => true],
        'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
        'homepage_page_id' => $page->id,
    ];
    $version = SiteVersion::query()->create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => $composition,
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $page->published_revision_id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::query()->create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);
    SiteDraft::query()->create([
        'site_id' => $site->id,
        'composition' => $composition,
        'updated_at' => now(),
    ]);

    return [$site->fresh(), $page->fresh()];
}

it('loads a drafted video from the video family when its id collides with an image', function () {
    [$user, $site, $page] = EditorSeeds::homeWithHero();
    Storage::fake('s3');
    $collisionId = 800_000_000 + $site->id;
    HeroVersion::factory()->for($site)->create([
        'id' => $collisionId,
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/wrong-family.jpg',
    ]);
    $video = HeroVideoVersion::factory()->for($site)->create([
        'id' => $collisionId,
        's3_key' => 'drafts/correct-family.mp4',
    ]);
    Storage::disk('s3')->put($video->s3_key, 'video');
    app(DraftAssetSelections::class)->setHeroVideo($site, $video, 'on', $user->id);

    $state = app(HeroResolution::class)->for($site, $page, true);

    expect($state->mode)->toBe('video')
        ->and($state->video_version_id)->toBe($video->id)
        ->and($state->video_url)->toBe(Storage::disk('s3')->url($video->s3_key))
        ->and($state->image_url)->not->toBe('https://cdn.example/wrong-family.jpg')
        ->and($state->reason)->toBe('video_mode_active');
});

it('uses drafted placement before version placement', function () {
    [$user, $site, $page] = EditorSeeds::homeWithHero();
    $version = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'placement' => ['text_zone' => 'top-left', 'bg_position_y' => 12],
    ]);
    app(DraftAssetSelections::class)->setHero(
        $site,
        'home',
        'hero',
        $version,
        $user->id,
        ['text_zone' => 'bottom-right', 'bg_position_y' => 83],
    );

    $drafted = app(HeroResolution::class)->for($site, $page, true);

    expect($drafted->placement)->toBe(['text_zone' => 'bottom-right', 'bg_position_y' => 83])
        ->and($drafted->image_version_id)->toBe($version->id)
        ->and($drafted->reason)->toBe('draft_selection');
});

it('falls back to version placement when the drafted selection has none', function () {
    [$user, $site, $page] = EditorSeeds::homeWithHero();
    $version = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'placement' => ['text_zone' => 'middle-center', 'bg_position_y' => 41],
    ]);
    app(DraftAssetSelections::class)->setHero($site, 'home', 'hero', $version, $user->id);

    expect(app(HeroResolution::class)->for($site, $page, true)->placement)
        ->toBe(['text_zone' => 'middle-center', 'bg_position_y' => 41]);
});

it('keeps a configured scene active when drafted video mode is off', function () {
    [$user, $site, $page] = EditorSeeds::homeWithHero();
    $slide = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/scene-slide.jpg',
    ]);
    $draftImage = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/draft-image.jpg',
    ]);
    $scene = [
        'kind' => 'image',
        'slides' => [[
            'asset_type' => 'hero_version',
            'asset_id' => $slide->id,
            'heading' => 'Authored scene',
        ]],
        'transitions' => [],
    ];
    $site->update(['home_hero_scene' => $scene]);
    app(DraftAssetSelections::class)->setHero($site, 'home', 'hero', $draftImage, $user->id);
    app(DraftAssetSelections::class)->setHeroVideo($site, null, 'off', $user->id);

    $state = app(HeroResolution::class)->for($site->fresh(), $page, true);

    expect($state->mode)->toBe('scene')
        ->and($state->scene)->toBe($scene)
        ->and($state->image_version_id)->toBe($draftImage->id)
        ->and($state->reason)->toBe('scene_active');
});

it('resolves the existing active hero unchanged when there are no drafted rows', function () {
    [, $site, $page] = EditorSeeds::homeWithHero();
    $active = HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/published-image.jpg',
        'placement' => ['text_zone' => 'middle-left', 'bg_position_y' => 29],
    ]);

    $state = app(HeroResolution::class)->for($site, $page, true);

    expect($state->mode)->toBe('image')
        ->and($state->image_version_id)->toBe($active->id)
        ->and($state->image_url)->toBe($active->url)
        ->and($state->placement)->toBe($active->placement)
        ->and($state->video_version_id)->toBeNull()
        ->and($state->scene)->toBeNull()
        ->and($state->reason)->toBe('hero_version_active');
});

it('ranks a configured scene above a pathless enabled canonical video on the published half', function () {
    Storage::fake('s3');
    [$site, $page] = publishedHeroPage();
    $slide = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/published-scene-over-canonical-video.jpg',
    ]);
    $canonicalKey = 'dev-previews/'.$site->id.'/hero-home-video.mp4';
    Storage::disk('s3')->put($canonicalKey, 'canonical-hero-video-bytes');
    $site->update([
        'home_hero_video_enabled' => true,
        'home_hero_video_path' => null,
        'home_hero_scene' => [
            'kind' => 'image',
            'slides' => [[
                'asset_type' => 'hero_version',
                'asset_id' => $slide->id,
                'heading' => 'Published scene over pathless video',
            ]],
            'transitions' => [],
        ],
    ]);

    $published = app(HeroResolution::class)->for($site->fresh(), $page, false);
    $drafted = app(HeroResolution::class)->for($site->fresh(), $page, true);

    expect($published->mode)->toBe('scene')
        ->and($published->reason)->toBe('scene_active')
        ->and($drafted->mode)->toBe('video')
        ->and($drafted->reason)->toBe('video_mode_active')
        ->and($drafted->video_url)->toBe(Storage::disk('s3')->url($canonicalKey));
});

it('resolves the canonical video key for a pre-versioning site', function () {
    [, $site, $page] = EditorSeeds::homeWithHero();
    Storage::fake('s3');
    $canonicalKey = 'dev-previews/'.$site->id.'/hero-home-video.mp4';
    Storage::disk('s3')->put($canonicalKey, 'legacy video');
    $site->update([
        'home_hero_video_enabled' => true,
        'home_hero_video_path' => null,
    ]);

    $state = app(HeroResolution::class)->for($site->fresh(), $page, true);

    expect($state->mode)->toBe('video')
        ->and($state->video_version_id)->toBeNull()
        ->and($state->video_url)->toBe(Storage::disk('s3')->url($canonicalKey));
});

it('uses the latest legacy snapshot watermark setting when the profile has none', function () {
    [, $site, $page] = EditorSeeds::homeWithHero();
    $active = HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/unwatermarked-hero.jpg',
        'watermark_url' => 'https://cdn.example/watermarked-hero.jpg',
    ]);
    Preview::factory()->for($site)->create([
        'snapshot' => ['watermark_enabled' => false],
    ]);

    $state = app(HeroResolution::class)->for($site->fresh(), $page, false);

    expect($state->image_version_id)->toBe($active->id)
        ->and($state->image_url)->toBe($active->url)
        ->not->toBe($active->watermark_url);
});

it('renders a pending video instead of the different published video in draft mode', function () {
    [$user, $site, $page] = EditorSeeds::homeWithHero();
    Storage::fake('s3');
    $live = HeroVideoVersion::factory()->for($site)->active()->create(['s3_key' => 'live/video.mp4']);
    $draft = HeroVideoVersion::factory()->for($site)->create(['s3_key' => 'draft/video.mp4']);
    Storage::disk('s3')->put($live->s3_key, 'live');
    Storage::disk('s3')->put($draft->s3_key, 'draft');
    $site->update([
        'home_hero_video_enabled' => true,
        'home_hero_video_path' => $live->s3_key,
    ]);
    app(DraftAssetSelections::class)->setHeroVideo($site, $draft, 'on', $user->id);

    $state = app(HeroResolution::class)->for($site->fresh(), $page, true);

    expect($state->video_version_id)->toBe($draft->id)
        ->and($state->video_url)->toBe(Storage::disk('s3')->url($draft->s3_key))
        ->and($state->video_url)->not->toBe(Storage::disk('s3')->url($live->s3_key));
});

it('keeps public rendering on published hero state when drafted rows exist', function () {
    Storage::fake('s3');
    [$site, $page] = publishedHeroPage();
    $publishedImage = HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/published-hero.jpg',
    ]);
    $sceneSlide = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/published-scene-slide.jpg',
    ]);
    $scene = [
        'kind' => 'image',
        'slides' => [[
            'asset_type' => 'hero_version',
            'asset_id' => $sceneSlide->id,
            'heading' => 'Published scene',
        ]],
        'transitions' => [],
    ];
    $site->update(['home_hero_scene' => $scene]);
    $draftImage = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/draft-hero.jpg',
    ]);
    $draftVideo = HeroVideoVersion::factory()->for($site)->create(['s3_key' => 'draft/hero.mp4']);
    Storage::disk('s3')->put($draftVideo->s3_key, 'video');
    app(DraftAssetSelections::class)->setHero($site, 'home', 'hero', $draftImage, null);
    app(DraftAssetSelections::class)->setHeroVideo($site, $draftVideo, 'on', null);

    $published = app(HeroResolution::class)->for($site, $page, false);
    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public', useDraftAssets: false);

    expect($published->mode)->toBe('scene')
        ->and($published->scene)->toBe($scene)
        ->and($published->image_version_id)->toBe($publishedImage->id)
        ->and($html)->toContain($sceneSlide->url)
        ->not->toContain($draftImage->url)
        ->not->toContain(Storage::disk('s3')->url($draftVideo->s3_key));
});

it('renders the published image on a scene-free public page when drafted rows exist', function () {
    Storage::fake('s3');
    [$site, $page] = publishedHeroPage();
    $publishedImage = HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/scene-free-published-hero.jpg',
    ]);
    $draftImage = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/scene-free-draft-hero.jpg',
    ]);
    $draftVideo = HeroVideoVersion::factory()->for($site)->create([
        's3_key' => 'draft/scene-free-hero.mp4',
    ]);
    Storage::disk('s3')->put($draftVideo->s3_key, 'video');
    app(DraftAssetSelections::class)->setHero($site, 'home', 'hero', $draftImage, null);
    app(DraftAssetSelections::class)->setHeroVideo($site, $draftVideo, 'on', null);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public', useDraftAssets: false);

    expect($html)->toContain($publishedImage->url)
        ->not->toContain($draftImage->url)
        ->not->toContain(Storage::disk('s3')->url($draftVideo->s3_key));
});
