<?php

use App\Models\GeneratedPage;
use App\Models\LogoConcept;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use App\Services\Site\ThemeResolver;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $siteAttrs
 * @return array{0: Site, 1: GeneratedPage}
 */
function makeFooterSite(array $siteAttrs = []): array
{
    Storage::fake('s3');

    $site = Site::factory()->create([
        'business_name' => 'Acme Plumbing',
        'theme' => 'trades-bold',
    ] + $siteAttrs);

    LogoConcept::factory()->for($site)->selected()->create([
        'path' => 'logos/acme-logo.png',
    ]);

    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Welcome to Acme'],
        ]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => [['type' => 'page', 'label' => 'Home', 'page_id' => $page->id]]],
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

it('renders the motto in its own slot at full opacity when set', function () {
    [$site, $page] = makeFooterSite(['footer_motto' => 'Building beyond the brief.']);
    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');
    expect($html)->toContain('data-footer-motto')
        ->toContain('Building beyond the brief.')
        ->toMatch('/data-footer-motto[^>]*class="[^"]*italic[^"]*opacity-100/');
});

it('renders no motto slot when unset (byte-identical row)', function () {
    [$site, $page] = makeFooterSite();
    expect(app(PageRenderer::class)->render($site, $page->id, mode: 'public'))->not->toContain('data-footer-motto');
});

it('shows the logo above the business name when footer_show_logo is on', function () {
    [$site, $page] = makeFooterSite(['footer_show_logo' => true]);
    expect(app(PageRenderer::class)->render($site, $page->id, mode: 'public'))->toMatch('/<footer.*<img[^>]+class="h-12 w-auto object-contain mb-4"/s');
});

it('motto text-on-band over band meets AA for every committed theme', function (string $key) {
    [$site, $page] = makeFooterSite(['footer_motto' => 'Building beyond the brief.', 'theme' => 'trades-bold']);
    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');
    expect($html)->toMatch('/data-footer-motto[^>]*style="[^"]*color:\s*var\(--color-text-on-band\)/');

    $path = base_path('tests/fixtures/home-themes/demo-site-themes.json');
    $decoded = json_decode((string) file_get_contents($path), true);
    expect($decoded[$key] ?? null)->toBeArray();

    $resolver = app(ThemeResolver::class);
    $tokens = $resolver->renderTokens($decoded[$key]);
    expect($tokens)->toHaveKey('text_on_band')->toHaveKey('band');

    $ratio = $resolver->contrastRatio($tokens['text_on_band'], $tokens['band']);
    expect($ratio)->toBeGreaterThanOrEqual(4.5);
})->with([
    '51-eden' => ['51-eden'],
    '52-hunt' => ['52-hunt'],
    '54-nh' => ['54-nh'],
    'light-archetype' => ['light-archetype'],
]);
