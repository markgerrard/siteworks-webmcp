<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use App\Support\Textures\TextureLibrary;
use App\Support\Textures\TextureResolver;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => $this->renderer = app(PageRenderer::class));

/**
 * @param  list<array<string, mixed>>  $sections
 * @return array{0: Site, 1: GeneratedPage}
 */
function setupTextureSafetySite(array $siteAttrs, array $sections): array
{
    $site = Site::factory()->create(array_merge([
        'business_name' => 'Safety Co',
        'business_type' => 'Clockmaker',
        'theme' => 'trades-bold',
    ], $siteAttrs));

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

/**
 * @return list<array<string, mixed>>
 */
function safetySections(): array
{
    return [
        ['type' => 'hero', 'title' => 'Hero marker', 'subtitle' => 'Professional services'],
        ['type' => 'services', 'title' => 'Services marker', 'items' => [['title' => 'Widget care', 'body' => 'We look after widgets.']]],
        ['type' => 'cta', 'title' => 'Cta marker', 'button_label' => 'Get Quote'],
    ];
}

function extractServicesHtml(string $html): string
{
    $start = strpos($html, 'Services marker');
    $end = strpos($html, 'Cta marker');
    expect($start)->not->toBeFalse()
        ->and($end)->not->toBeFalse()
        ->and($end)->toBeGreaterThan($start);

    return substr($html, $start, $end - $start);
}

test('containment: a non-hero non-cta section is byte-identical across different site textures', function () {
    [$plusSite, $plusPage] = setupTextureSafetySite(['texture_key' => 'plus'], safetySections());
    [$dotsSite, $dotsPage] = setupTextureSafetySite(['texture_key' => 'dots'], safetySections());

    $plusHtml = $this->renderer->render($plusSite, $plusPage->id, mode: 'public');
    $dotsHtml = $this->renderer->render($dotsSite, $dotsPage->id, mode: 'public');

    $plusServices = extractServicesHtml($plusHtml);
    $itemAt = strpos($plusHtml, 'Widget care');

    expect($plusServices)->toBe(extractServicesHtml($dotsHtml))
        ->and(substr_count($plusHtml, 'class="absolute inset-0 hero-pattern"'))->toBe(2)
        ->and(substr_count($dotsHtml, 'class="absolute inset-0 hero-pattern"'))->toBe(2)
        ->and(substr($plusHtml, $itemAt - 240, 480))->not->toContain('hero-pattern')
        ->and($dotsHtml)->toContain('--site-texture-size: 24px');
});

test('determinism: the same site renders the same texture twice', function () {
    [$site, $page] = setupTextureSafetySite([], safetySections());

    $first = $this->renderer->render($site, $page->id, mode: 'public');
    $second = $this->renderer->render($site->fresh(), $page->id, mode: 'public');

    expect($second)->toBe($first)
        ->and(TextureResolver::resolve($site)->key)->toBe(TextureResolver::resolve($site->fresh())->key);
});

test('unknown texture keys fall back to plus and still render the page', function () {
    [$site, $page] = setupTextureSafetySite(['texture_key' => 'swirl'], safetySections());

    $html = $this->renderer->render($site, $page->id, mode: 'public');

    expect($html)->toContain('Hero marker')
        ->toContain(TextureLibrary::PLUS_PATH)
        ->toContain('--site-texture-opacity: 0.05');
});

test('malformed site opacity uses the library default and still renders', function () {
    [$site, $page] = setupTextureSafetySite([
        'texture_key' => 'dots',
        'texture_opacity' => 9,
    ], safetySections());

    $html = $this->renderer->render($site, $page->id, mode: 'public');

    expect($html)->toContain('--site-texture-opacity: 0.06')
        ->toContain('Hero marker');
});

test('kill-switch off resolves null keys to plus while explicit keys still work', function () {
    config()->set('site-textures.auto', false);

    [$autoSite, $autoPage] = setupTextureSafetySite([
        'business_type' => 'Landscaping',
    ], safetySections());
    [$explicitSite, $explicitPage] = setupTextureSafetySite([
        'texture_key' => 'waves',
        'business_type' => 'Landscaping',
    ], safetySections());

    $autoHtml = $this->renderer->render($autoSite, $autoPage->id, mode: 'public');
    $explicitHtml = $this->renderer->render($explicitSite, $explicitPage->id, mode: 'public');

    expect($autoHtml)->toContain(TextureLibrary::PLUS_PATH)
        ->and($autoHtml)->toContain('--site-texture-size: 60px')
        ->and($explicitHtml)->toContain('--site-texture-size: 80px 20px')
        ->and($explicitHtml)->not->toContain(TextureLibrary::PLUS_PATH);
});

test('kill-switch on is the default so unmatched neighbours can differ', function () {
    expect(config('site-textures.auto'))->toBeTrue();

    [$a, $aPage] = setupTextureSafetySite([], safetySections());
    [$b, $bPage] = setupTextureSafetySite([], safetySections());

    $keyA = TextureResolver::resolve($a->fresh())->key;
    $keyB = TextureResolver::resolve($b->fresh())->key;
    $htmlA = $this->renderer->render($a->fresh(), $aPage->id, mode: 'public');
    $htmlB = $this->renderer->render($b->fresh(), $bPage->id, mode: 'public');

    expect($htmlA)->toContain('Hero marker')
        ->and($htmlB)->toContain('Hero marker')
        ->and($keyA)->toBeIn(TextureLibrary::SEEDED_KEYS)
        ->and($keyB)->toBeIn(TextureLibrary::SEEDED_KEYS);
});
