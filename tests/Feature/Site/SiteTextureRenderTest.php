<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use App\Support\MediaStorage;
use App\Support\Textures\TextureLibrary;
use App\Support\Textures\TextureResolver;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => $this->renderer = app(PageRenderer::class));

/**
 * @param  list<array<string, mixed>>  $sections
 * @return array{0: Site, 1: GeneratedPage}
 */
function setupTextureRenderSite(array $siteAttrs = [], array $sections = []): array
{
    $site = Site::factory()->create(array_merge([
        'business_name' => 'Acme',
        'theme' => 'trades-bold',
    ], $siteAttrs));

    if ($sections === []) {
        $sections = [
            ['type' => 'hero', 'title' => 'Welcome', 'subtitle' => 'Professional services'],
            ['type' => 'cta', 'title' => 'Call to action', 'button_label' => 'Get Quote'],
        ];
    }

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

test('rendered page emits CSS vars for the resolved site texture', function () {
    [$site, $page] = setupTextureRenderSite(['texture_key' => 'dots']);

    $html = $this->renderer->render($site, $page->id, mode: 'public');
    $resolved = TextureResolver::resolve($site);

    expect($html)->toContain('--site-texture-image: '.$resolved->cssImage())
        ->toContain('--site-texture-opacity: 0.06')
        ->toContain('--site-texture-size: 24px')
        ->toContain('mask-image: var(--site-texture-image)')
        ->toContain('.site-texture')
        ->toContain('.hero-pattern');
});

test('explicit none emits no texture layer and no texture CSS vars', function () {
    [$site, $page] = setupTextureRenderSite(['texture_key' => 'none']);

    $html = $this->renderer->render($site, $page->id, mode: 'public');

    expect($html)->not->toContain('--site-texture-image')
        ->not->toContain('--site-texture-opacity')
        ->not->toContain('--site-texture-size')
        ->not->toContain('site-texture')
        ->not->toContain('hero-pattern');
});

test('a per-section texture override beats the site-level motif on that section only', function () {
    [$site, $page] = setupTextureRenderSite(['texture_key' => 'plus'], [
        ['type' => 'hero', 'title' => 'Welcome', 'subtitle' => 'Professional services'],
        [
            'type' => 'cta',
            'title' => 'Call to action',
            'button_label' => 'Get Quote',
            'style_overrides' => ['texture' => 'grid'],
        ],
    ]);

    $html = $this->renderer->render($site, $page->id, mode: 'public');

    expect($html)->toContain('--site-texture-size: 60px')
        ->toContain('--site-texture-size: 32px')
        ->toContain('style="--site-texture-image:');
});

test('cta_band stays untextured until a per-section texture knob is set', function () {
    $band = ['type' => 'cta_band', 'title' => 'Get in touch', 'cta_label' => 'Contact'];
    [$offSite, $offPage] = setupTextureRenderSite(['texture_key' => 'dots'], [
        ['type' => 'hero', 'title' => 'Welcome', 'subtitle' => 'Professional services'],
        $band,
    ]);
    $offHtml = $this->renderer->render($offSite, $offPage->id, mode: 'public');

    [$onSite, $onPage] = setupTextureRenderSite(['texture_key' => 'dots'], [
        ['type' => 'hero', 'title' => 'Welcome', 'subtitle' => 'Professional services'],
        array_merge($band, ['style_overrides' => ['texture' => 'sprig']]),
    ]);
    $onHtml = $this->renderer->render($onSite, $onPage->id, mode: 'public');

    expect(substr_count($offHtml, 'class="absolute inset-0 hero-pattern"'))->toBe(1)
        ->and(substr_count($onHtml, 'class="absolute inset-0 hero-pattern"'))->toBe(2)
        ->and($onHtml)->toContain('--site-texture-size: 90px');
});

test('image texture mode emits a media-disk URL and not the default filesystem disk', function () {
    Storage::fake('media-disk');
    config()->set('filesystems.media', 'media-disk');
    config()->set('filesystems.default', 'local');
    Storage::fake('local');

    [$site, $page] = setupTextureRenderSite();
    $path = 'sites/'.$site->id.'/library/bg.webp';
    MediaStorage::disk()->put($path, 'texture-bytes');
    $site->update([
        'texture_key' => 'image',
        'texture_image_path' => $path,
    ]);

    $html = $this->renderer->render($site->fresh(), $page->id, mode: 'public');
    $mediaUrl = MediaStorage::disk()->url($path);

    expect($html)->toContain($mediaUrl)
        ->toContain('site-texture--image')
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

test('home hero and primary CTA still carry the texture layer when a motif is resolved', function () {
    [$site, $page] = setupTextureRenderSite(['texture_key' => 'plus']);

    $html = $this->renderer->render($site, $page->id, mode: 'public');

    expect(substr_count($html, 'hero-pattern'))->toBeGreaterThanOrEqual(2)
        ->and($html)->toContain('--site-texture-opacity: 0.05')
        ->and($html)->toContain('--site-texture-size: 60px')
        ->and($html)->toContain(TextureLibrary::PLUS_PATH);
});
