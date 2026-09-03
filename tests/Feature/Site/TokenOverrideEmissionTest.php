<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => $this->renderer = app(PageRenderer::class));

/**
 * @return array{0: Site, 1: GeneratedPage}
 */
function setupTokenOverrideSite(array $compositionTheme = []): array
{
    $site = Site::factory()->create([
        'business_name' => 'Acme',
        'theme' => 'trades-bold',
        'design_brief' => [
            'mood' => 'warm-traditional',
            'display_font' => 'fraunces',
            'body_font' => 'source-sans-3',
            'heading_scale' => 'balanced',
            'spacing_density' => 'balanced',
            'corner_style' => 'soft',
            'palette' => [
                'primary' => '#1f3a5f',
                'accent' => '#8b6b2f',
                'tertiary' => '#f4ede0',
                'surface' => '#ffffff',
                'surface_alt' => '#f8f5ee',
                'border' => '#e4ddcf',
                'text' => '#1a1a1a',
                'text_muted' => '#6b7280',
            ],
        ],
    ]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Welcome', 'subtitle' => 'Professional services'],
            ['type' => 'cta', 'title' => 'Call to action', 'button_label' => 'Get Quote'],
        ]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => array_merge(['key' => 'trades-bold'], $compositionTheme),
            'homepage_page_id' => $page->id,
        ],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    return [$site, $page];
}

/**
 * @return array<string, string>
 */
function emittedPaintDeclarations(string $html): array
{
    preg_match_all('/--((?:color|radius)-[a-z0-9-]+):\s*([^;]+);/', $html, $matches, PREG_SET_ORDER);

    $declarations = [];
    foreach ($matches as $match) {
        $declarations[$match[1]] ??= $match[2];
    }

    return $declarations;
}

test('absent and empty token_overrides render byte-identical HTML', function () {
    [$absentSite, $absentPage] = setupTokenOverrideSite();
    $absentHtml = $this->renderer->render($absentSite, $absentPage->id, mode: 'public');

    [$emptySite, $emptyPage] = setupTokenOverrideSite(['token_overrides' => []]);
    $emptyHtml = $this->renderer->render($emptySite, $emptyPage->id, mode: 'public');

    expect($emptyHtml)->toBe($absentHtml);
});

test('a site-wide token override changes exactly the named CSS variable', function () {
    [$baselineSite, $baselinePage] = setupTokenOverrideSite();
    $baselineHtml = $this->renderer->render($baselineSite, $baselinePage->id, mode: 'public');

    [$site, $page] = setupTokenOverrideSite([
        'token_overrides' => ['color-band' => '#f7f2ea'],
    ]);
    $html = $this->renderer->render($site, $page->id, mode: 'public');

    $baseline = emittedPaintDeclarations($baselineHtml);
    $overridden = emittedPaintDeclarations($html);

    expect($overridden['color-band'])->toBe('#f7f2ea')
        ->and($baseline['color-band'])->not->toBe('#f7f2ea');

    unset($baseline['color-band'], $overridden['color-band']);

    expect($overridden)->toBe($baseline);
});

test('a token override on an inverted site renders the literal colour', function () {
    [$invertedSite, $invertedPage] = setupTokenOverrideSite([
        'invert_mode_override' => true,
    ]);
    $invertedHtml = $this->renderer->render($invertedSite, $invertedPage->id, mode: 'public');

    [$site, $page] = setupTokenOverrideSite([
        'invert_mode_override' => true,
        'token_overrides' => ['color-surface' => '#f7f2ea'],
    ]);
    $html = $this->renderer->render($site, $page->id, mode: 'public');

    $inverted = emittedPaintDeclarations($invertedHtml);
    $literal = emittedPaintDeclarations($html);

    expect($inverted['color-surface'])->not->toBe('#ffffff')
        ->and($inverted['color-surface'])->not->toBe('#f7f2ea')
        ->and($literal['color-surface'])->toBe('#f7f2ea');
});
