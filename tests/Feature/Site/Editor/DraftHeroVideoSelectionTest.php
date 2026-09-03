<?php

use App\Models\HeroVersion;
use App\Models\HeroVideoVersion;
use App\Models\Site\SiteDraftAssetSelection;
use App\Services\Site\Editor\DraftAssetSelections;
use App\Services\Site\PageRenderer;
use App\Services\Site\SitePublishService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EditorSeeds;

it('drafts a hero video without touching any live state', function () {
    [$user, $site, $page] = EditorSeeds::homeWithHero(); // same order as the existing EditorSeeds::site()
    $video = HeroVideoVersion::factory()->for($site)->create(['is_active' => false]);

    app(DraftAssetSelections::class)->setHeroVideo($site, $video, 'on', $user->id);

    $drafted = app(DraftAssetSelections::class)->heroVideoFor($site);
    expect($drafted['mode'])->toBe('on');
    expect($drafted['version']->id)->toBe($video->id);
    expect($drafted['version'])->toBeInstanceOf(HeroVideoVersion::class);

    $site->refresh();
    expect($site->home_hero_video_enabled)->toBeFalse();
    expect(HeroVideoVersion::where('site_id', $site->id)->where('is_active', true)->count())->toBe(0);
});

it('lets a drafted image and a drafted video-off coexist', function () {
    [, $site] = EditorSeeds::homeWithHero(); // [User, Site, GeneratedPage]
    $image = HeroVersion::factory()->for($site)->create(['page_type' => 'home', 'slot' => 'hero']);

    app(DraftAssetSelections::class)->setHero($site, 'home', 'hero', $image, null);
    app(DraftAssetSelections::class)->setHeroVideo($site, null, 'off', null);

    expect(app(DraftAssetSelections::class)->heroFor($site, 'home', 'hero')->id)->toBe($image->id);
    expect(app(DraftAssetSelections::class)->heroVideoFor($site)['mode'])->toBe('off');
});

it('keeps a hero video selection out of every existing family=hero reader', function () {
    [$user, $site, $page] = EditorSeeds::homeWithHero(); // [User, Site, GeneratedPage]
    $collisionId = 900_000_000 + $site->id;
    $image = HeroVersion::factory()->for($site)->create([
        'id' => $collisionId,
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/colliding-hero.jpg',
        'is_active' => false,
    ]);
    $video = HeroVideoVersion::factory()->for($site)->create([
        'id' => $collisionId,
        'is_active' => false,
    ]);

    app(DraftAssetSelections::class)->setHeroVideo($site, $video, 'on', $user->id);

    $stored = SiteDraftAssetSelection::query()
        ->where('site_id', $site->id)
        ->where('family', 'hero_video')
        ->where('page_type', 'home')
        ->where('slot', 'hero')
        ->firstOrFail();
    expect($stored->mode)->toBe('on')
        ->and($stored->version_id)->toBe($video->id)
        ->and(app(DraftAssetSelections::class)->heroFor($site, 'home', 'hero'))->toBeNull()
        ->and(app(DraftAssetSelections::class)->all($site)->where('family', 'hero'))->toBeEmpty();

    // This render used to assert only an ABSENCE, which is not an oracle: it was green both when the
    // drafted video correctly replaced the image AND when nothing resolved at all. It went red at one
    // point purely because an unfaked S3 call threw "Missing region" - so it rewarded suppressing the
    // video and blocked the correct fix. Fake the disk, seed the object, and assert the POSITIVE: the
    // drafted video's own URL appears. The expected value is the seeded version's URL, computed
    // without reference to the resolver.
    Storage::fake('s3');
    Storage::disk('s3')->put($video->s3_key, 'fake-mp4-bytes');

    $this->withoutVite();
    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'admin-preview', useDraftAssets: true);
    expect($html)->toContain($video->url())
        ->and($html)->not->toContain($image->url);

    $versions = EditorSeeds::invokeMinimally('list_image_versions', $site, $page, $user)->data['versions'];
    expect(collect($versions)->firstWhere('id', $image->id)['drafted'])->toBeFalse();

    Queue::fake();
    app(SitePublishService::class)->publishSite($site);

    expect($image->fresh()->is_active)->toBeFalse()
        ->and(SiteDraftAssetSelection::query()->find($stored->id))->toBeNull()
        ->and($video->fresh()->is_active)->toBeTrue()
        ->and($site->fresh()->home_hero_video_enabled)->toBeTrue();
});

it('persists hero placement through mass assignment and a fresh query', function () {
    [, $site] = EditorSeeds::homeWithHero();
    $image = HeroVersion::factory()->for($site)->create(['page_type' => 'home', 'slot' => 'hero']);
    $placement = ['y' => 37];

    app(DraftAssetSelections::class)->setHero($site, 'home', 'hero', $image, null, $placement);

    $stored = SiteDraftAssetSelection::query()
        ->where('site_id', $site->id)
        ->where('family', 'hero')
        ->where('page_type', 'home')
        ->where('slot', 'hero')
        ->firstOrFail();

    expect($stored->placement)->toBe($placement);
});

it('clears only the drafted hero video selection', function () {
    [, $site] = EditorSeeds::homeWithHero();
    $image = HeroVersion::factory()->for($site)->create(['page_type' => 'home', 'slot' => 'hero']);
    $video = HeroVideoVersion::factory()->for($site)->create();
    $selections = app(DraftAssetSelections::class);
    $selections->setHero($site, 'home', 'hero', $image, null);
    $selections->setHeroVideo($site, $video, 'on', null);

    $selections->clearHeroVideo($site);

    expect(SiteDraftAssetSelection::query()
        ->where('site_id', $site->id)
        ->where('family', 'hero_video')
        ->exists())->toBeFalse()
        ->and($selections->heroFor($site, 'home', 'hero')?->is($image))->toBeTrue();
});

it('rejects video-on mode without a version', function () {
    [, $site] = EditorSeeds::homeWithHero();

    expect(fn () => app(DraftAssetSelections::class)->setHeroVideo($site, null, 'on', null))
        ->toThrow(InvalidArgumentException::class, 'A hero video version is required when mode is on.');

    expect(SiteDraftAssetSelection::query()
        ->where('site_id', $site->id)
        ->where('family', 'hero_video')
        ->exists())->toBeFalse();
});

// No "rejects a row outside home/hero" case: `setHeroVideo()`'s signature takes no page_type or slot,
// so the invalid state is unrepresentable at this layer. The check belongs to `set_hero_media` (Task 10),
// which does take them, and is tested there.

// NOT WRITTEN HERE, deliberately: the guard "home video resolved only on the home render"
// wants a public render of a NON-home page, which needs the published SiteVersion +
// SiteVersionCurrent plumbing that tests/Feature/Site/SharedServiceHeroTest.php:15-50 sets up. That
// is fixture work beyond the precision edit this commit is, and the guard is a strengthening rather
// than a fix for an open finding. Logged for the next task in this file to add, using that seed:
// render a service page and assert the video URL is ABSENT, then render home and assert it is
// PRESENT. The wrong implementation it must fail against is "resolve the home video for every page".
