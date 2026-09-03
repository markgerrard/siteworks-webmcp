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
 * @param  list<array<string, mixed>>  $sections
 * @return array{0: Site, 1: GeneratedPage}
 */
function setupSectionStyleSite(array $sections): array
{
    $site = Site::factory()->create([
        'business_name' => 'Acme',
        'theme' => 'trades-bold',
        'texture_key' => 'plus',
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
        'content_data' => ['sections' => $sections],
    ]);
    $page->update(['published_revision_id' => $rev->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'],
            'homepage_page_id' => $page->id,
        ],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    return [$site, $page];
}

function defaultStyleSections(?array $ctaExtra = null): array
{
    $cta = array_merge([
        'id' => '01JHG7KX3MNQRSTVWXYZCTA001',
        'type' => 'cta',
        'title' => 'Targeted call to action',
        'button_label' => 'Get Quote',
    ], $ctaExtra ?? []);

    return [
        [
            'id' => '01JHG7KX3MNQRSTVWXYZHERO01',
            'type' => 'hero',
            'title' => 'Untargeted welcome',
            'subtitle' => 'Professional services',
        ],
        $cta,
    ];
}

test('absent and empty section style_overrides render byte-identical HTML', function () {
    [$absentSite, $absentPage] = setupSectionStyleSite(defaultStyleSections());
    $absentHtml = $this->renderer->render($absentSite, $absentPage->id, mode: 'public');

    [$emptySite, $emptyPage] = setupSectionStyleSite(defaultStyleSections([
        'style_overrides' => [],
    ]));
    $emptyHtml = $this->renderer->render($emptySite, $emptyPage->id, mode: 'public');

    expect($emptyHtml)->toBe($absentHtml)
        ->and($absentHtml)->not->toContain('style="--color-band:');
});

test('only the target section wrapper redeclares the overridden custom property', function () {
    [$site, $page] = setupSectionStyleSite(defaultStyleSections([
        'style_overrides' => ['color-band' => '#f7f2ea'],
    ]));
    $html = $this->renderer->render($site, $page->id, mode: 'public');

    expect(substr_count($html, 'style="--color-band: #f7f2ea;"'))->toBe(1);

    $main = (string) preg_replace('/^.*<main\b[^>]*>/s', '', $html);
    $main = (string) preg_replace('/<\/main>.*$/s', '', $main);

    expect($main)->toMatch('/<div style="--color-band: #f7f2ea;">[\s\S]*Targeted call to action[\s\S]*<\/div>/')
        ->and($main)->toMatch('/Untargeted welcome[\s\S]*<div style="--color-band: #f7f2ea;">/')
        ->and($main)->not->toMatch('/<div style="--color-band: #f7f2ea;">[\s\S]*Untargeted welcome/');
});
