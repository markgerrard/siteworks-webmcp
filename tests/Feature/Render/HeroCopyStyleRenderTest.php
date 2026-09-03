<?php

use App\Enums\PageKind;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use Illuminate\Support\Facades\View;

/**
 * @return array<string, mixed>
 */
function heroCopyStyleVars(string $pageType, ?string $variant, ?string $knob, array $sectionOverrides = []): array
{
    $section = array_merge([
        'type' => 'hero',
        'title' => 'Welcome to Acme',
        'subtitle' => 'Plumbing in Wigan',
        'cta_label' => 'Get a quote',
        'eyebrow' => 'Local experts',
    ], $sectionOverrides);
    if ($variant !== null) {
        $section['variant'] = $variant;
    }

    return [
        'section' => $section,
        'sectionIndex' => 0,
        'pageId' => 1,
        'emitMarkers' => false,
        'emitFormMarkers' => false,
        'profile' => ['watermark_enabled' => false],
        'heroImageUrl' => 'https://example.test/hero.jpg',
        'pageType' => $pageType,
        'pagesBySlug' => ['contact' => '/contact'],
        'schema' => [],
        'theme' => [],
        'site' => new Site(['hero_copy_style' => $knob]),
    ];
}

function renderHeroCopyStyle(string $pageType, ?string $variant, ?string $knob, array $sectionOverrides = []): string
{
    return View::make('site.sections.hero', heroCopyStyleVars($pageType, $variant, $knob, $sectionOverrides))->render();
}

/**
 * @param  list<array<string, mixed>>  $sections
 * @return array{0: Site, 1: GeneratedPage}
 */
function makeHeroCopyStylePublishedPage(string $pageType, array $sections, ?string $knob = null, ?PageKind $kind = null): array
{
    $site = Site::factory()->create([
        'business_name' => 'Acme Plumbing',
        'theme' => 'trades-bold',
        'hero_copy_style' => $knob,
    ]);
    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => $pageType,
        'kind' => $kind ?? (in_array($pageType, PageKind::CORE_PAGE_TYPES, true) ? PageKind::Core : PageKind::Service),
        'nav_label' => ucfirst(str_replace('-', ' ', $pageType)),
    ]);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => $sections],
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

it('preset keeps the layout-stamped panel and boxed wrappers', function (string $variant) {
    $html = renderHeroCopyStyle('home', $variant, null);

    expect($html)->toContain('data-hero-variant="'.$variant.'"')
        ->and($html)->toContain('color-mix(in srgb, var(--brand-primary)');
})->with(['panel-left', 'boxed-left']);

it('preset on a plain hero stays without a copy wrapper', function () {
    $html = renderHeroCopyStyle('home', null, null);

    expect($html)->not->toContain('data-hero-variant=');
});

it('plain strips a stamped panel-left wrapper', function () {
    $html = renderHeroCopyStyle('home', 'panel-left', 'plain');

    expect($html)->not->toContain('data-hero-variant=')
        ->and($html)->not->toContain('color-mix(in srgb, var(--brand-primary)');
});

it('panel and boxed wrap home and inner intro heroes', function (string $pageType, string $knob, string $expected) {
    $html = renderHeroCopyStyle($pageType, null, $knob);

    expect($html)->toContain('data-hero-variant="'.$expected.'"')
        ->and($html)->toContain('color-mix(in srgb, var(--brand-primary)');
})->with([
    'home panel' => ['home', 'panel', 'panel-left'],
    'home boxed' => ['home', 'boxed', 'boxed-left'],
    'about panel' => ['about', 'panel', 'panel-left'],
    'about boxed' => ['about', 'boxed', 'boxed-left'],
]);

it('boxed is a compact painted box and panel is a full-height band on non-scene heroes', function (string $pageType) {
    $panel = renderHeroCopyStyle($pageType, null, 'panel');
    $boxed = renderHeroCopyStyle($pageType, null, 'boxed');

    expect($boxed)->not->toBe($panel)
        ->and($boxed)->toContain('hero-copy-box')
        ->and($boxed)->toContain('max-width: 36rem')
        ->and($panel)->not->toContain('hero-copy-box')
        ->and($panel)->not->toContain('max-width: 36rem')
        ->and($panel)->toContain('min-height: 100%')
        ->and($boxed)->not->toContain('min-height: 100%');
})->with(['home', 'about']);

it('inner intro heroes keep the inner band height when the knob paints a panel', function () {
    $html = renderHeroCopyStyle('about', null, 'panel');

    expect($html)->toContain('min-height: 35vh')
        ->and($html)->toContain('py-8 md:py-12');
});

/**
 * @return array<string, mixed>
 */
function heroCopyStyleSceneVars(?string $knob, string $overlayStyle, ?string $stampedVariant = null): array
{
    $vars = heroCopyStyleVars('home', $stampedVariant, $knob);
    $vars['scene'] = [
        'kind' => 'image',
        'slides' => [
            [
                'heading' => 'Slide 1',
                'subheading' => 'First view',
                'cta_label' => 'Get a quote',
                'asset_url' => 'https://example.test/slide-1.webp',
                'text_zone' => 'middle-right',
                'dwell_secs' => 6,
            ],
        ],
        'transitions' => [['type' => 'fade', 'duration_secs' => 1.0]],
        'overlay_style' => $overlayStyle,
    ];
    $vars['heroH'] = '55vh';
    $vars['heroMinH'] = '280px';

    return $vars;
}

function renderHeroCopyStyleScene(?string $knob, string $overlayStyle, ?string $stampedVariant = null): string
{
    return View::make('site.sections._hero_scene', heroCopyStyleSceneVars($knob, $overlayStyle, $stampedVariant))->render();
}

it('scene heroes honour the knob independently of the stamped variant', function () {
    $html = renderHeroCopyStyleScene('panel', 'gradient');

    expect($html)->toContain('data-hero-variant="panel-left"')
        ->and($html)->toContain('color-mix(in srgb, var(--brand-primary)');
});

function stripHeroCopyVariantToken(string $html): string
{
    return (string) preg_replace('/data-hero-variant="(?:boxed|panel)-left"/', 'data-hero-variant="TOKEN"', $html);
}

it('boxed and panel scene heroes stay unequal after stripping the variant token', function (string $overlay) {
    $panel = renderHeroCopyStyleScene('panel', $overlay);
    $boxed = renderHeroCopyStyleScene('boxed', $overlay);

    expect(stripHeroCopyVariantToken($boxed))->not->toBe(stripHeroCopyVariantToken($panel))
        ->and($boxed)->toContain('hero-copy-box')
        ->and($boxed)->toContain('max-width: 36rem')
        ->and($panel)->not->toContain('hero-copy-box')
        ->and($panel)->not->toContain('max-width: 36rem');
})->with(['panel', 'gradient', 'none']);

it('boxed and panel knobs force the scene copy surface over gradient and none overlays', function (string $overlay, string $knob, string $expectedVariant) {
    $html = renderHeroCopyStyleScene($knob, $overlay);

    expect($html)->toContain('data-hero-variant="'.$expectedVariant.'"')
        ->and($html)->toContain('background-color: color-mix(in srgb, var(--brand-primary)')
        ->and($html)->toContain('border-radius: var(--radius-card); padding: 1.5rem 2rem')
        ->and($html)->not->toContain('data-hero-overlay-style="gradient"');
})->with([
    'boxed × panel' => ['panel', 'boxed', 'boxed-left'],
    'boxed × gradient' => ['gradient', 'boxed', 'boxed-left'],
    'boxed × none' => ['none', 'boxed', 'boxed-left'],
    'panel × panel' => ['panel', 'panel', 'panel-left'],
    'panel × gradient' => ['gradient', 'panel', 'panel-left'],
    'panel × none' => ['none', 'panel', 'panel-left'],
]);

it('plain and preset leave a scene overlay unboxed', function (string $overlay, ?string $knob) {
    $html = renderHeroCopyStyleScene($knob, $overlay);

    expect($html)->not->toContain('data-hero-variant=')
        ->and($html)->not->toContain('background-color: color-mix(in srgb, var(--brand-primary)')
        ->and($html)->not->toContain('data-hero-overlay-style="gradient"');
})->with([
    'plain × panel' => ['panel', 'plain'],
    'plain × gradient' => ['gradient', 'plain'],
    'plain × none' => ['none', 'plain'],
    'preset × panel' => ['panel', null],
    'preset × gradient' => ['gradient', null],
    'preset × none' => ['none', null],
]);

it('preset boxed-left scenes keep a gradient overlay so recipe output stays byte-identical', function () {
    $html = renderHeroCopyStyleScene(null, 'gradient', 'boxed-left');

    expect($html)->toContain('data-hero-variant="boxed-left"')
        ->and($html)->toContain('data-hero-overlay-style="gradient"')
        ->and($html)->not->toContain('background-color: color-mix(in srgb, var(--brand-primary)')
        ->and($html)->not->toContain('padding: 1.5rem 2rem');
});

it('contact and service intro heroes honour boxed through the public renderer', function (string $pageType, PageKind $kind) {
    [$site, $page] = makeHeroCopyStylePublishedPage($pageType, [
        ['type' => 'hero', 'title' => 'Get in touch', 'subtitle' => 'Call us in Wigan'],
    ], 'boxed', $kind);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('data-hero-variant="boxed-left"')
        ->and($html)->toContain('background-color: color-mix(in srgb, var(--brand-primary)')
        ->and($html)->toContain('min-height: 35vh')
        ->and($html)->toContain('py-8 md:py-12');
})->with([
    'contact' => ['contact', PageKind::Core],
    'service' => ['boiler-installation', PageKind::Service],
]);

it('plain on an inner intro hero strips a stamped panel without painting a box', function () {
    [$site, $page] = makeHeroCopyStylePublishedPage('about', [
        ['type' => 'hero', 'title' => 'About Acme', 'subtitle' => 'Family firm', 'variant' => 'panel-left'],
    ], 'plain');

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('About Acme')
        ->and($html)->toContain('py-8 md:py-12')
        ->and($html)->toContain('35vh')
        ->and($html)->not->toContain('data-hero-variant=')
        ->and($html)->not->toContain('background-color: color-mix(in srgb, var(--brand-primary)');
});

it('a boxed scene through the public renderer paints the box over a gradient overlay', function () {
    [$site, $page] = makeHeroCopyStylePublishedPage('home', [
        ['type' => 'hero', 'title' => 'Welcome to Acme', 'subtitle' => 'Plumbing in Wigan'],
    ], 'boxed');
    $heroVersion = HeroVersion::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://example.test/slide-1.webp',
        'source' => 'user_upload',
        'is_active' => false,
    ]);
    $site->update([
        'home_hero_video_enabled' => false,
        'home_hero_scene' => [
            'kind' => 'image',
            'slides' => [[
                'asset_type' => 'hero_version',
                'asset_id' => $heroVersion->id,
                'heading' => 'Slide 1',
                'subheading' => 'First view',
                'cta_label' => 'Get a quote',
                'text_zone' => 'middle-right',
                'dwell_secs' => 6,
            ]],
            'transitions' => [['type' => 'fade', 'duration_secs' => 1.0]],
            'overlay_style' => 'gradient',
        ],
    ]);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($html)->toContain('data-hero-variant="boxed-left"')
        ->and($html)->toContain('background-color: color-mix(in srgb, var(--brand-primary)')
        ->and($html)->not->toContain('data-hero-overlay-style="gradient"');
});

it('an empty-copy boxed hero does not paint an empty box', function () {
    $html = renderHeroCopyStyle('about', null, 'boxed', [
        'title' => '',
        'subtitle' => '',
        'cta_label' => '',
        'eyebrow' => '',
    ]);

    expect($html)->not->toContain('data-hero-variant=')
        ->and($html)->not->toContain('background-color: color-mix(in srgb, var(--brand-primary)')
        ->and($html)->toContain('py-8 md:py-12')
        ->and($html)->toContain('35vh');
});

it('preset empty-copy stamped heroes keep the historical painted wrapper', function (string $variant) {
    $html = renderHeroCopyStyle('home', $variant, null, [
        'title' => '',
        'subtitle' => '',
        'cta_label' => '',
        'eyebrow' => '',
    ]);

    expect($html)->toContain('data-hero-variant="'.$variant.'"')
        ->and($html)->toContain('background-color: color-mix(in srgb, var(--brand-primary)');
})->with(['boxed-left', 'panel-left']);
