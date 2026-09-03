<?php

use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * A configured hero scene and an enabled background video coexist on the
 * same site: HeroSceneService::resolve() routes to a legacy 1-slide video
 * scene when home_hero_video_enabled + home_hero_video_path are both set,
 * leaving the stored image scene untouched in the column.
 *
 * Untested before, which is how an operator (me) came to believe enabling
 * video required nulling home_hero_scene — destroying studio-editable state
 * that cannot be rebuilt from the UI. These pin the toggle as non-destructive
 * in both directions.
 */
function makeSiteWithSceneAndVideo(): array
{
    $site = Site::factory()->create(['business_name' => 'Acme Plumbing', 'theme' => 'trades-bold']);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Welcome to Acme', 'subtitle' => 'Plumbing in Wigan', 'cta_label' => 'Get a quote'],
        ]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'homepage_page_id' => $page->id,
        ],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    $slides = [];
    foreach ([1, 2] as $n) {
        $hv = HeroVersion::create([
            'site_id' => $site->id,
            'page_type' => 'home',
            'slot' => 'hero',
            'url' => "https://cdn.example/scene-slide-{$n}.webp",
            'source' => 'user_upload',
            'is_active' => false,
        ]);
        $slides[] = [
            'asset_type' => 'hero_version',
            'asset_id' => $hv->id,
            'heading' => "Slide {$n}",
            'subheading' => null,
            'cta_label' => 'Get a quote',
            'text_zone' => 'middle-left',
            'text_color' => 'white',
            'overlay_strength' => 'light',
            'dwell_secs' => 7,
        ];
    }

    $site->update([
        'home_hero_scene' => [
            'kind' => 'image',
            'slides' => $slides,
            'transitions' => [['type' => 'fade', 'duration_secs' => 1.0]],
            'motion' => 'ken_burns',
        ],
        'home_hero_video_path' => 'previews/hero.mp4',
    ]);

    return [$site, $page];
}

it('renders the video and not the scene when the video is enabled', function () {
    [$site, $page] = makeSiteWithSceneAndVideo();
    Storage::fake('s3');
    Storage::disk('s3')->put('previews/hero.mp4', 'fake-bytes');
    $site->update(['home_hero_video_enabled' => true]);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($html)->toContain('<video')
        ->and($html)->not->toContain('scene-kb');
});

it('falls back to the stored scene when the video is disabled', function () {
    [$site, $page] = makeSiteWithSceneAndVideo();
    Storage::fake('s3');
    Storage::disk('s3')->put('previews/hero.mp4', 'fake-bytes');
    $site->update(['home_hero_video_enabled' => false]);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($html)->not->toContain('<video')
        ->and($html)->toContain('scene-kb');
});

it('keeps the scene stored while the video is enabled', function () {
    [$site] = makeSiteWithSceneAndVideo();
    Storage::fake('s3');
    Storage::disk('s3')->put('previews/hero.mp4', 'fake-bytes');
    $site->update(['home_hero_video_enabled' => true]);

    // Toggling the video must be non-destructive in both directions.
    expect($site->fresh()->home_hero_scene['slides'])->toHaveCount(2);

    $site->update(['home_hero_video_enabled' => false]);
    expect($site->fresh()->home_hero_scene['slides'])->toHaveCount(2);
});
