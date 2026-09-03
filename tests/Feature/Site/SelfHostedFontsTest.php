<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use App\Services\Site\ThemeResolver;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * The catalogue ThemeResolver::FONTS must vendor. A newly added family
 * without a committed stylesheet is a blank-page regression.
 *
 * @return list<string>
 */
function selfHostedFontSlugs(): array
{
    return [
        'inter',
        'manrope',
        'figtree',
        'source-sans-3',
        'nunito-sans',
        'fraunces',
        'dm-serif-display',
        'playfair-display',
        'space-grotesk',
        'bricolage-grotesque',
        'archivo-black',
    ];
}

function selfHostedFontSite(): array
{
    $site = Site::factory()->create([
        'business_name' => 'Acme',
        'theme' => 'trades-bold',
        'design_brief' => [
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
        ],
    ]);

    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Welcome']]],
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

test('theme resolver font catalogue matches the vendored family list', function () {
    expect(array_keys(ThemeResolver::FONTS))->toBe(selfHostedFontSlugs());
});

test('renderTokens emits a same-origin pair stylesheet rather than bunny', function () {
    $tokens = app(ThemeResolver::class)->renderTokens([
        'display_font' => 'fraunces',
        'body_font' => 'source-sans-3',
        'primary_color' => '#1f3a5f',
        'accent_color' => '#8b6b2f',
    ]);

    expect($tokens['font_link_href'])->toBe('/fonts/fraunces+source-sans-3.css')
        ->and($tokens['font_link_href'])->not->toContain('fonts.bunny.net');
});

test('renderTokens keeps both slugs when display and body are the same family', function () {
    $tokens = app(ThemeResolver::class)->renderTokens([
        'display_font' => 'inter',
        'body_font' => 'inter',
    ]);

    expect($tokens['font_link_href'])->toBe('/fonts/inter+inter.css');
});

test('every catalogue family has a committed latin stylesheet with font-display swap', function () {
    foreach (selfHostedFontSlugs() as $slug) {
        $path = public_path("fonts/{$slug}.css");
        expect($path)->toBeFile();

        $css = file_get_contents($path);
        expect($css)->toContain('@font-face')
            ->and($css)->toContain('font-display: swap')
            ->and($css)->toContain("url(/fonts/{$slug}/")
            ->and($css)->toContain('.woff2')
            ->and($css)->not->toContain('fonts.bunny.net')
            ->and($css)->not->toContain('latin-ext');
    }
});

test('rendered public page loads same-origin fonts and never mentions bunny', function () {
    [$site, $page] = selfHostedFontSite();

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->not->toContain('fonts.bunny.net')
        ->and($html)->not->toContain('rel="preconnect"')
        ->and($html)->toContain('href="/fonts/fraunces+source-sans-3.css"');
});

test('the font pair stylesheet returns concatenated at-font-face rules with long cache headers', function () {
    $response = $this->withServerVariables(['HTTP_HOST' => 'public-site.example'])
        ->get('http://public-site.example/fonts/fraunces+source-sans-3.css');

    $response->assertSuccessful();
    expect($response->headers->get('Content-Type'))->toStartWith('text/css');

    $cacheControl = strtolower((string) $response->headers->get('Cache-Control'));
    expect($cacheControl)->toContain('max-age=31536000')
        ->and($cacheControl)->toContain('immutable');

    $css = $response->getContent();
    expect($css)->toContain('@font-face')
        ->and($css)->toContain('font-family: \'Fraunces\'')
        ->and($css)->toContain('font-family: \'Source Sans 3\'')
        ->and($css)->toContain('font-display: swap')
        ->and($css)->toContain('url(/fonts/fraunces/')
        ->and($css)->toContain('url(/fonts/source-sans-3/')
        ->and($css)->not->toContain('fonts.bunny.net');
});

test('a repeated family pair is served once without duplicating at-font-face blocks', function () {
    $response = $this->withServerVariables(['HTTP_HOST' => 'public-site.example'])
        ->get('http://public-site.example/fonts/inter+inter.css');

    $response->assertSuccessful();
    expect(substr_count($response->getContent(), "font-family: 'Inter'"))
        ->toBe(substr_count(file_get_contents(public_path('fonts/inter.css')), "font-family: 'Inter'"));
});

test('unknown font pair slugs 404', function () {
    $this->withServerVariables(['HTTP_HOST' => 'public-site.example'])
        ->get('http://public-site.example/fonts/not-a-font+inter.css')
        ->assertNotFound();
    $this->withServerVariables(['HTTP_HOST' => 'public-site.example'])
        ->get('http://public-site.example/fonts/inter+also-fake.css')
        ->assertNotFound();
});

test('the font pair stylesheet is reachable on an active custom-domain host (ResolvePreviewHost passthrough)', function () {
    // ResolvePreviewHost only passes listed prefixes to PHP on site hosts; without
    // 'fonts/' in that list every rendered site 404s its own font stylesheet.
    \App\Models\Site::factory()->create(['custom_domain' => 'fontpass.example', 'custom_domain_status' => 'active']);

    $css = $this->get('http://fontpass.example/fonts/inter+inter.css');
    $css->assertOk();
    expect((string) $css->headers->get('Content-Type'))->toStartWith('text/css');
});
