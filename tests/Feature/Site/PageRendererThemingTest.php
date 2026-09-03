<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => $this->renderer = app(PageRenderer::class));

function themedDesignBriefFixture(array $overrides = []): array
{
    return array_replace_recursive([
        'mood' => 'warm-traditional',
        'display_font' => 'fraunces',
        'body_font' => 'source-sans-3',
        'heading_scale' => 'relaxed',
        'spacing_density' => 'generous',
        'corner_style' => 'rounded',
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
        'rationale' => 'Heritage-led palette and serif display fit the business tone.',
    ], $overrides);
}

function setupThemedSite(string $theme = 'trades-bold', array $extraSections = [], ?array $designBrief = null, array $compositionTheme = []): array
{
    $site = Site::factory()->create([
        'business_name' => 'Acme',
        'theme' => $theme,
        'design_brief' => $designBrief,
    ]);
    $sections = array_merge([
        ['type' => 'hero', 'title' => 'Welcome', 'subtitle' => 'Professional services'],
    ], $extraSections);

    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => $sections],
    ]);
    $page->update(['published_revision_id' => $rev->id]);

    $version = SiteVersion::create([
        'site_id'        => $site->id,
        'version'        => 1,
        'composition'    => [
            'nav'              => ['items' => []],
            'footer'           => ['columns' => [], 'show_credit' => true],
            'theme'            => array_merge(['key' => $theme, 'primary_override' => null, 'accent_override' => null], $compositionTheme),
            'homepage_page_id' => $page->id,
        ],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev->id]],
        'published_at'   => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    return [$site, $page];
}

test('rendered page contains the default token block for trades-bold', function () {
    [$site, $page] = setupThemedSite('trades-bold');

    $html = $this->renderer->render($site, $page->id, mode: 'public');

    expect($html)->toContain('--color-primary: #1e40af');
    expect($html)->toContain('--color-accent: #f59e0b');
    expect($html)->toContain('--brand-primary: var(--color-primary);');
    expect($html)->toContain('--brand-accent: var(--color-accent);');
});

test('rendered page contains CSS variable declaration for professional-clean', function () {
    [$site, $page] = setupThemedSite('professional-clean');

    $html = $this->renderer->render($site, $page->id, mode: 'public');

    expect($html)->toContain('--color-primary: #1f2937');
    expect($html)->toContain('--color-accent: #6366f1');
});

test('rendered page contains CSS variable declaration for local-friendly', function () {
    [$site, $page] = setupThemedSite('local-friendly');

    $html = $this->renderer->render($site, $page->id, mode: 'public');

    expect($html)->toContain('--color-primary: #15803d');
    expect($html)->toContain('--color-accent: #ea580c');
});

test('rendered page loads only the design brief font families and spacing tokens', function () {
    [$site, $page] = setupThemedSite('trades-bold', [], themedDesignBriefFixture());

    $html = $this->renderer->render($site, $page->id, mode: 'public');

    expect($html)->toContain('--color-primary: #1f3a5f')
        ->toContain('--color-surface-alt: #f8f5ee')
        ->toContain('--container-width: 1360px')
        ->toContain('--section-spacing: 8rem')
        ->toContain('/fonts/fraunces+source-sans-3.css')
        ->not->toContain('fonts.bunny.net')
        ->not->toContain('family=inter:400,500,600,700,800');
});

test('hero section renders with brand-accent style on CTA button', function () {
    [$site, $page] = setupThemedSite('trades-bold', [
        ['type' => 'cta', 'title' => 'Call to action', 'button_label' => 'Get Quote'],
    ]);

    $html = $this->renderer->render($site, $page->id, mode: 'public');

    // CTA section uses var(--brand-primary) background
    expect($html)->toContain('var(--brand-primary)');
    expect($html)->toContain('var(--brand-accent)');
});

test('brand section scheme defaults to byte-identical bold rendering', function () {
    [$site, $page] = setupThemedSite('trades-bold', [
        ['type' => 'cta', 'title' => 'Call to action', 'button_label' => 'Get Quote'],
    ]);

    $defaultHtml = $this->renderer->render($site, $page->id, mode: 'public');
    $version = SiteVersion::where('site_id', $site->id)->firstOrFail();
    $composition = $version->composition;
    $composition['theme']['brand_section_scheme_override'] = 'bold';
    $version->update(['composition' => $composition]);
    $explicitBoldHtml = $this->renderer->render($site, $page->id, mode: 'public');

    expect($explicitBoldHtml)->toBe($defaultHtml)
        ->and($defaultHtml)->not->toContain('--color-brand-section-surface:');
});

test('soft brand sections tint brand contrast pattern and footer surfaces with AA ink', function () {
    $brief = themedDesignBriefFixture([
        'palette' => [
            'primary' => '#111111',
            'accent' => '#f5b800',
            'surface' => '#ffffff',
            'surface_alt' => '#f5f4f0',
            'text' => '#111111',
            'text_muted' => '#5f6368',
        ],
    ]);
    [$site, $page] = setupThemedSite('trades-bold', [
        ['type' => 'services', 'title' => 'Seasonal flowers', 'items' => [['title' => 'Bouquets', 'body' => 'Made nearby.']]],
        ['type' => 'trust', 'title' => 'The Verdant way', 'items' => [['title' => 'Local', 'body' => 'Flowers with a sense of place.']]],
        ['type' => 'cta', 'title' => 'Send a little more colour', 'body' => 'Flowers with a sense of place.', 'button_label' => 'Shop flowers'],
    ], $brief, ['brand_section_scheme_override' => 'soft']);
    $site->update(['home_layout' => 'editorial']);

    $html = $this->renderer->render($site->fresh(), $page->id, mode: 'public');
    preg_match('/--color-brand-section-surface: (#[0-9a-f]{6});/', $html, $surfaceMatch);
    preg_match('/--color-brand-section-ink: (#[0-9a-f]{6});/', $html, $inkMatch);
    preg_match('/--color-surface-contrast: (#[0-9a-f]{6});/', $html, $contrastSurfaceMatch);

    expect($html)->toContain('data-brand-section-scheme="soft"')
        ->toContain('data-svc-variant="brand-manifesto"')
        ->toContain('background-color: var(--color-brand-section-surface);')
        ->toContain('<div class="absolute inset-0 hero-pattern"')
        ->toContain('style="filter: invert(1);"')
        ->toContain('<footer data-brand-section-scheme="soft"')
        ->and($surfaceMatch)->toHaveCount(2)
        ->and($inkMatch)->toHaveCount(2)
        ->and($contrastSurfaceMatch[1] ?? null)->toBe($surfaceMatch[1] ?? null)
        ->and(app(\App\Services\Site\ThemeResolver::class)->contrastRatio($inkMatch[1], $surfaceMatch[1]))->toBeGreaterThanOrEqual(4.5);
});

test('nav includes brand-primary top bar style', function () {
    [$site, $page] = setupThemedSite('trades-bold');

    $html = $this->renderer->render($site, $page->id, mode: 'public');

    // Top bar and nav use brand colours via inline style
    expect($html)->toContain('background-color: var(--brand-primary)');
    expect($html)->toContain('background-color: var(--brand-accent)');
});

test('render byte count is substantial (full themed page over 5KB)', function () {
    [$site, $page] = setupThemedSite('trades-bold');

    $html = $this->renderer->render($site, $page->id, mode: 'public');

    expect(strlen($html))->toBeGreaterThan(5000);
});
