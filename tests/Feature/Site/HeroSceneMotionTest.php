<?php

use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\HeroSceneService;
use App\Services\Site\PageRenderer;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * @return array{0: Site, 1: GeneratedPage}
 */
function makeHomePageForMotionTest(): array
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

    return [$site, $page];
}

/**
 * Attach a 2-slide kind=image home_hero_scene backed by inactive
 * user_upload HeroVersion rows (the customer-photo slide convention).
 *
 * @param  array<string, mixed>  $sceneMeta  Top-level scene keys (motion, …)
 */
function attachImageHeroScene(Site $site, array $sceneMeta = []): void
{
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
        'home_hero_video_enabled' => false,
        'home_hero_scene' => array_merge([
            'kind' => 'image',
            'slides' => $slides,
            'transitions' => [['type' => 'fade', 'duration_secs' => 1.0]],
        ], $sceneMeta),
    ]);
}

it('resolves ken_burns motion for image scenes', function () {
    [$site] = makeHomePageForMotionTest();
    attachImageHeroScene($site, ['motion' => 'ken_burns']);

    $scene = app(HeroSceneService::class)->resolve($site->fresh());

    expect($scene['motion'])->toBe('ken_burns');
});

it('nulls unknown motion values at the service boundary', function () {
    [$site] = makeHomePageForMotionTest();
    attachImageHeroScene($site, ['motion' => 'spin;background:url(x)']);

    $scene = app(HeroSceneService::class)->resolve($site->fresh());

    expect($scene['motion'])->toBeNull();
});

it('resolves motion null for legacy single-asset derivation', function () {
    [$site] = makeHomePageForMotionTest();
    HeroVersion::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/legacy-hero.png',
        'is_active' => true,
    ]);

    $scene = app(HeroSceneService::class)->resolve($site->fresh());

    expect($scene['is_legacy'])->toBeTrue()
        ->and($scene['motion'])->toBeNull();
});

it('renders ken burns slide classes and keyframes when motion is enabled', function () {
    [$site, $page] = makeHomePageForMotionTest();
    attachImageHeroScene($site, ['motion' => 'ken_burns']);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($html)->toContain('scene-kb-0')
        ->toContain('scene-kb-1')
        ->toContain('@keyframes scene-kb-push-right')
        ->toContain('prefers-reduced-motion');
});

it('renders the plain cross-fade when motion is absent', function () {
    [$site, $page] = makeHomePageForMotionTest();
    attachImageHeroScene($site);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($html)->toContain('https://cdn.example/scene-slide-1.webp')
        ->not->toContain('scene-kb')
        ->not->toContain('@keyframes');
});

it('constant overlay locks slide-0 copy and drops the pager dots', function () {
    [$site, $page] = makeHomePageForMotionTest();
    attachImageHeroScene($site, ['motion' => 'ken_burns', 'overlay_mode' => 'constant']);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    // Slide 0 copy present and always visible; slide 1 copy absent from the DOM.
    expect($html)->toContain('Slide 1')
        ->not->toContain('Slide 2')
        ->not->toContain('Go to slide');
    // Background images still cycle — both assets in the stack.
    expect($html)->toContain('https://cdn.example/scene-slide-1.webp')
        ->toContain('https://cdn.example/scene-slide-2.webp');
});

it('rejects unknown overlay_mode values at the service boundary', function () {
    [$site] = makeHomePageForMotionTest();
    attachImageHeroScene($site, ['overlay_mode' => 'sticky']);

    $scene = app(HeroSceneService::class)->resolve($site->fresh());

    expect($scene['overlay_mode'])->toBeNull();
});

it('per-slide overlays keep cycling when overlay_mode is absent', function () {
    [$site, $page] = makeHomePageForMotionTest();
    attachImageHeroScene($site);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($html)->toContain('Slide 1')
        ->toContain('Slide 2')
        ->toContain('Go to slide');
});

it('showcase boxed-left scene renders without the legibility gradient', function () {
    [$site, $page] = makeHomePageForMotionTest();
    attachImageHeroScene($site, ['overlay_mode' => 'constant']);
    $site->update(['home_layout' => 'showcase']);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($html)->toContain('data-hero-variant="boxed-left"')
        ->not->toContain('from-black/70');
});

it('non-boxed scene keeps the legibility gradient', function () {
    [$site, $page] = makeHomePageForMotionTest();
    attachImageHeroScene($site);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($html)->toContain('from-black/70');
});

it('renders a custom boxed-panel opacity from the scene', function () {
    [$site, $page] = makeHomePageForMotionTest();
    attachImageHeroScene($site, ['overlay_mode' => 'constant', 'panel_opacity' => 55]);
    $site->update(['home_layout' => 'showcase']);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($html)->toContain('var(--brand-primary) 55%')
        ->not->toContain('var(--brand-primary) 78%');
});

it('rejects invalid panel_opacity and keeps the 78% default', function (mixed $bad) {
    [$site, $page] = makeHomePageForMotionTest();
    attachImageHeroScene($site, ['overlay_mode' => 'constant', 'panel_opacity' => $bad]);
    $site->update(['home_layout' => 'showcase']);

    $scene = app(HeroSceneService::class)->resolve($site->fresh());
    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($scene['panel_opacity'])->toBeNull();
    expect($html)->toContain('var(--brand-primary) 78%');
})->with([
    'over range' => 140,
    'negative' => -5,
    'css injection' => '55;background:url(x)',
    'float string' => '55.5',
]);

it('gradient overlay style drops the review box and paints the scrim', function () {
    [$site, $page] = makeHomePageForMotionTest();
    attachImageHeroScene($site, ['overlay_mode' => 'constant', 'overlay_style' => 'gradient', 'panel_opacity' => 80]);
    $site->update(['home_layout' => 'showcase']);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($html)->toContain('data-hero-overlay-style="gradient"')
        ->toContain('var(--brand-primary) 80%')
        ->toContain('var(--brand-primary) 36%')
        ->not->toContain('background-color: color-mix');
});

it('overlay style none renders neither panel box nor scrim', function () {
    [$site, $page] = makeHomePageForMotionTest();
    attachImageHeroScene($site, ['overlay_style' => 'none']);
    $site->update(['home_layout' => 'showcase']);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($html)->not->toContain('data-hero-overlay-style')
        ->not->toContain('background-color: color-mix');
});

it('rejects unknown overlay_style and keeps the review default', function () {
    [$site, $page] = makeHomePageForMotionTest();
    attachImageHeroScene($site, ['overlay_style' => 'sparkles']);
    $site->update(['home_layout' => 'showcase']);

    $scene = app(HeroSceneService::class)->resolve($site->fresh());
    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($scene['overlay_style'])->toBeNull();
    expect($html)->toContain('background-color: color-mix')
        ->not->toContain('data-hero-overlay-style');
});

it('scene subtitles carry the self-legibility text shadow', function () {
    [$site, $page] = makeHomePageForMotionTest();
    attachImageHeroScene($site, ['overlay_mode' => 'constant', 'overlay_style' => 'gradient']);
    $site->update(['home_layout' => 'showcase']);

    $scene = $site->fresh()->home_hero_scene;
    $scene['slides'][0]['subheading'] = 'Body copy that must survive pale imagery.';
    $site->update(['home_hero_scene' => $scene]);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($html)->toContain('Body copy that must survive pale imagery.')
        ->toContain('color: rgba(255,255,255,0.95); text-shadow: 0 1px 3px rgba(0,0,0,0.65), 0 2px 12px rgba(0,0,0,0.5);');
});

it('renders the Checkatrade wordmark instead of the word in the scene trust pill', function () {
    [$site, $page] = makeHomePageForMotionTest();
    attachImageHeroScene($site, ['overlay_mode' => 'constant']);
    $site->update([
        'reviews_cache' => [
            'provider' => 'checkatrade',
            'rating' => 9.9,
            'rating_scale' => 10,
            'user_ratings_total' => 6,
            'reviews' => [],
        ],
    ]);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($html)->toContain('aria-label="Checkatrade"')
        ->not->toContain('Checkatrade reviews');
});

it('never applies motion markup to video scenes', function () {
    [$site, $page] = makeHomePageForMotionTest();
    attachImageHeroScene($site, ['motion' => 'ken_burns']);

    // Flip the stored scene to kind=video with no resolvable composite —
    // hydrate() falls back to legacy derivation, which carries motion null.
    $scene = $site->fresh()->home_hero_scene;
    $scene['kind'] = 'video';
    $site->update(['home_hero_scene' => $scene]);
    HeroVersion::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/legacy-hero.png',
        'is_active' => true,
    ]);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($html)->not->toContain('scene-kb');
});
