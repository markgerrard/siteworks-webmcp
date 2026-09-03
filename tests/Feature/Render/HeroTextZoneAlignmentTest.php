<?php

use App\Models\Site;
use Illuminate\Support\Facades\View;

/**
 * @return array<string, mixed>
 */
function heroTextZoneVars(string $variant, ?string $textZone = null): array
{
    $section = [
        'type' => 'hero',
        'title' => 'Welcome to Acme',
        'subtitle' => 'Plumbing in Wigan',
        'cta_label' => 'Get a quote',
        'eyebrow' => 'Local experts',
        'variant' => $variant,
    ];

    $heroImageUrl = 'https://example.test/hero.jpg';
    if ($textZone !== null) {
        $heroImageUrl = [
            'url' => 'https://example.test/hero.jpg',
            'watermark_url' => null,
            'placement' => ['text_zone' => $textZone],
        ];
    }

    return [
        'section' => $section,
        'sectionIndex' => 0,
        'pageId' => 1,
        'emitMarkers' => false,
        'emitFormMarkers' => false,
        'profile' => ['watermark_enabled' => false],
        'heroImageUrl' => $heroImageUrl,
        'pageType' => 'home',
        'pagesBySlug' => ['contact' => '/contact'],
        'schema' => [],
        'theme' => [],
        'site' => new Site,
    ];
}

function normalizeHeroHtml(string $html): string
{
    return trim(preg_replace(['/>\s+</', '/\s+/'], ['><', ' '], $html));
}

function renderHeroTextZone(string $variant, ?string $textZone = null): string
{
    return normalizeHeroHtml(View::make('site.sections.hero', heroTextZoneVars($variant, $textZone))->render());
}

function heroPanelFixturePath(string $variant): string
{
    return base_path("tests/fixtures/home-sections/hero-non-scene-{$variant}-pre-align.html");
}

it('keeps panel-left and boxed-left byte-identical for middle-left and absent placement', function (string $variant) {
    $path = heroPanelFixturePath($variant);
    $absent = renderHeroTextZone($variant, null);
    $middleLeft = renderHeroTextZone($variant, 'middle-left');

    if (! file_exists($path)) {
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, $absent);
        $this->markTestIncomplete("Snapshot created at {$path} — re-run to assert.");
    }

    $expected = file_get_contents($path);
    expect($absent)->toBe($expected)
        ->and($middleLeft)->toBe($expected)
        ->and($absent)->not->toContain('data-hero-align=');
})->with(['panel-left', 'boxed-left']);

it('places the review on the centre axis when text_zone is middle-center', function (string $variant) {
    $html = renderHeroTextZone($variant, 'middle-center');

    expect($html)->toContain("data-hero-variant=\"{$variant}\"")
        ->and($html)->toContain('data-hero-align="center"')
        ->and($html)->toContain('text-center mx-auto items-center')
        ->and($html)->toContain('sm:justify-center')
        ->and($html)->not->toContain('sm:justify-start')
        ->and($html)->not->toContain('text-left mr-auto items-start');
})->with(['panel-left', 'boxed-left']);

it('places the review on the right when text_zone is middle-right', function (string $variant) {
    $html = renderHeroTextZone($variant, 'middle-right');

    expect($html)->toContain("data-hero-variant=\"{$variant}\"")
        ->and($html)->toContain('data-hero-align="right"')
        ->and($html)->toContain('text-left ml-auto items-start')
        ->and($html)->toContain('sm:justify-end')
        ->and($html)->not->toContain('sm:justify-start')
        ->and($html)->not->toContain('text-left mr-auto items-start');
})->with(['panel-left', 'boxed-left']);

/**
 * @return array<string, mixed>
 */
function heroSceneHeightVars(string $heroH, ?string $sceneHeight, string $textZone = 'top-left'): array
{
    return [
        'section' => ['type' => 'hero'],
        'scene' => [
            'kind' => 'image',
            'slides' => [[
                'heading' => 'Slide 1',
                'subheading' => 'First view',
                'cta_label' => 'Get a quote',
                'asset_url' => 'https://example.test/slide-1.webp',
                'text_zone' => $textZone,
                'dwell_secs' => 6,
            ]],
            'transitions' => [],
            'height' => $sceneHeight,
        ],
        'heroH' => $heroH,
        'heroMinH' => '280px',
        'profile' => [],
        'pagesBySlug' => ['contact' => '/contact'],
        'site' => new Site,
        'pageId' => 1,
        'sectionIndex' => 0,
        'emitMarkers' => false,
        'sceneEyebrowOverride' => 'Local experts',
        'sceneAccentWord' => null,
    ];
}

function renderHeroSceneHeight(string $heroH, ?string $sceneHeight, string $textZone = 'top-left'): string
{
    return normalizeHeroHtml(View::make('site.sections._hero_scene', heroSceneHeightVars($heroH, $sceneHeight, $textZone))->render());
}

it('compacts padding and uses min-height when scene height is 30vh with top-left copy', function () {
    $html = renderHeroSceneHeight('30vh', '30vh', 'top-left');

    expect($html)->toContain('min-height: 30vh')
        ->and($html)->toContain('py-8')
        ->and($html)->not->toMatch('/(?<!min-)height:\s*30vh/')
        ->and($html)->not->toContain('py-28');
});

it('keeps today’s full-bleed markup when scene height is 60vh', function () {
    $path = base_path('tests/fixtures/home-sections/hero-scene-height-60vh.html');
    $html = renderHeroSceneHeight('60vh', '60vh', 'top-left');

    if (! file_exists($path)) {
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, $html);
        $this->markTestIncomplete("Snapshot created at {$path} — re-run to assert.");
    }

    expect($html)->toBe(file_get_contents($path))
        ->and($html)->toContain('height: 60vh')
        ->and($html)->toContain('py-28')
        ->and($html)->not->toContain('py-8');
});
