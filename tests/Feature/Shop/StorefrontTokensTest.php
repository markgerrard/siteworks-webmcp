<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\BusinessProfile;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Token key → CSS custom property, matching the 30 declarations in
 * site/page.blade.php's first :root block. renderTokens() keys have no
 * `_color` suffix — `primary`, not `primary_color`.
 *
 * @return array<string, string>
 */
function storefrontTokenCssMap(): array
{
    return [
        'primary' => '--color-primary',
        'primary_text' => '--color-primary-text',
        'primary_text_on_alt' => '--color-primary-text-on-alt',
        'accent' => '--color-accent',
        'accent_text' => '--color-accent-text',
        'accent_text_on_alt' => '--color-accent-text-on-alt',
        'tertiary' => '--color-tertiary',
        'surface' => '--color-surface',
        'surface_alt' => '--color-surface-alt',
        'border' => '--color-border',
        'text' => '--color-text',
        'text_on_alt' => '--color-text-on-alt',
        'text_muted' => '--color-text-muted',
        'text_muted_on_alt' => '--color-text-muted-on-alt',
        'band' => '--color-band',
        'text_on_band' => '--color-text-on-band',
        'band_overlay' => '--color-band-overlay',
        'text_on_primary' => '--color-text-on-primary',
        'text_on_accent' => '--color-text-on-accent',
        'surface_contrast' => '--color-surface-contrast',
        'text_on_contrast' => '--color-text-on-contrast',
        'text_muted_on_contrast' => '--color-text-muted-on-contrast',
        'accent_text_on_contrast' => '--color-accent-text-on-contrast',
        'display_font_stack' => '--font-display',
        'body_font_stack' => '--font-body',
        'radius_card' => '--radius-card',
        'radius_button' => '--radius-button',
        'section_spacing' => '--section-spacing',
        'container_width' => '--container-width',
        'heading_letter_spacing' => '--heading-letter-spacing',
    ];
}

/**
 * The 7 --brand-* aliases nav/footer consume with no fallback.
 *
 * @return array<string, string>
 */
function storefrontBrandAliases(): array
{
    return [
        '--brand-primary' => 'var(--color-primary)',
        '--brand-primary-text' => 'var(--color-primary-text)',
        '--brand-primary-text-on-alt' => 'var(--color-primary-text-on-alt)',
        '--brand-accent' => 'var(--color-accent)',
        '--brand-accent-text' => 'var(--color-accent-text)',
        '--brand-accent-text-on-alt' => 'var(--color-accent-text-on-alt)',
        '--brand-accent-text-on-contrast' => 'var(--color-accent-text-on-contrast)',
    ];
}

/**
 * Hard-coded fallbacks from site/page.blade.php. A fixture where any
 * computed token equals its fallback is blind against the
 * `$tokens['primary_color'] ?? $theme['primary_color'] ?? '#1e40af'` trap.
 *
 * @return array<string, string>
 */
function storefrontHardCodedFallbacks(): array
{
    return [
        'primary' => '#1e40af',
        'accent' => '#f59e0b',
        'tertiary' => '#d2d9eb',
        'surface' => '#ffffff',
        'surface_alt' => '#f5f5f5',
        'border' => '#e5e5e5',
        'text' => '#111111',
        'text_muted' => '#6b7280',
        'band' => '#0f172a',
        'text_on_band' => '#ffffff',
        'text_on_primary' => '#ffffff',
        'text_on_accent' => '#ffffff',
        'display_font_stack' => '"Inter", system-ui, sans-serif',
        'body_font_stack' => '"Inter", system-ui, sans-serif',
        'radius_card' => '12px',
        'radius_button' => '8px',
        'section_spacing' => '6rem',
        'container_width' => '1200px',
        'heading_letter_spacing' => '-0.01em',
        'icon_stroke_width' => '2',
        'font_link_href' => '/fonts/inter+inter.css',
    ];
}

/**
 * Composition theme whose every derived token differs from the hard-coded
 * fallback AND from the `?? $previousToken` chain (raw primary, $text, etc.).
 *
 * @return array<string, mixed>
 */
function distinctiveStorefrontCompositionTheme(): array
{
    return [
        'key' => 'trades-bold',
        'primary_override' => '#6db3d0',
        'accent_override' => '#c45c26',
        'tertiary_override' => '#7b2cbf',
        'surface_override' => '#f3e6c8',
        'surface_alt_override' => '#2a3328',
        'border_override' => '#c4a882',
        'text_override' => '#2c1810',
        'text_muted_override' => '#7a5c48',
        'display_font_override' => 'bricolage-grotesque',
        'body_font_override' => 'nunito-sans',
        'heading_scale_override' => 'relaxed',
        'spacing_density_override' => 'generous',
        'corner_style_override' => 'rounded',
    ];
}

/**
 * @return array{site: Site, tokens: array<string, string>, html: string}
 */
function distinctiveStorefrontPage(): array
{
    $compositionTheme = distinctiveStorefrontCompositionTheme();

    $site = Site::factory()->create([
        'theme' => 'trades-bold',
        'custom_domain' => 'atelier.example',
        'custom_domain_status' => 'active',
        'business_name' => 'Atelier Bloom',
    ]);

    BusinessProfile::factory()->for($site)->create([
        'profile_data' => ['archetype' => 'retail_venue', 'name' => 'Atelier Bloom'],
    ]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'theme' => $compositionTheme,
        ],
        'page_revisions' => [],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);

    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => [
            'meta' => ['site_id' => $site->id, 'product_count' => 1],
            'categories' => [],
            'products' => [
                'stem' => [
                    'id' => 1, 'slug' => 'stem', 'status' => 'published',
                    'primary_category_slug' => null,
                    'price_cents' => 2500, 'price_display' => '£25.00',
                    'in_stock_any' => true, 'variant_in_stock' => [],
                    'image_urls' => ['thumb' => '/a.jpg', 'card' => '/a.jpg', 'full' => '/a.jpg'],
                    'product_card' => ['slug' => 'stem', 'name' => 'Single Stem', 'price_display' => '£25.00'],
                    'product_detail' => ['slug' => 'stem', 'name' => 'Single Stem'],
                    'variants' => [],
                    'is_ai_seeded' => false, 'is_ai_reviewed' => false,
                ],
            ],
            'featured_slugs' => [],
        ],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create([
        'site_id' => $site->id,
        'snapshot_id' => $snap->id,
        'updated_at' => now(),
    ]);

    $site->refresh();
    $profile = $site->businessProfile?->profile_data ?? [];
    $resolver = app(ThemeResolver::class);
    $theme = $resolver->resolve($site, $profile, $compositionTheme);
    $tokens = $resolver->renderTokens($theme);

    $blind = [];
    foreach (storefrontHardCodedFallbacks() as $key => $fallback) {
        if (($tokens[$key] ?? null) === $fallback) {
            $blind[$key] = $fallback;
        }
    }
    expect($blind)->toBe([]);

    // The page.blade.php `?? $previousToken` chain. If these pairs are
    // equal, a wrong-key read that falls through to the previous token
    // still matches the oracle.
    expect($tokens['primary_text'])->not->toBe($tokens['primary'])
        ->and($tokens['primary_text_on_alt'])->not->toBe($tokens['primary_text'])
        ->and($tokens['accent_text'])->not->toBe($tokens['accent'])
        ->and($tokens['accent_text_on_alt'])->not->toBe($tokens['accent_text'])
        ->and($tokens['text_on_alt'])->not->toBe($tokens['text'])
        ->and($tokens['text_muted_on_alt'])->not->toBe($tokens['text_muted'])
        ->and($tokens['surface_contrast'])->not->toBe($tokens['surface_alt'])
        ->and($tokens['text_on_contrast'])->not->toBe($tokens['text_on_alt'])
        ->and($tokens['text_muted_on_contrast'])->not->toBe($tokens['text_muted_on_alt'])
        ->and($tokens['accent_text_on_contrast'])->not->toBe($tokens['accent_text_on_alt']);

    $html = test()->get('http://atelier.example/shop')->assertOk()->getContent();

    return ['site' => $site, 'tokens' => $tokens, 'html' => $html];
}

/**
 * Parse `--name: value;` declarations from the shop page's head <style>.
 *
 * @return array<string, string>
 */
function storefrontCssCustomProperties(string $html): array
{
    preg_match('/<head\b[^>]*>.*?<\/head>/is', $html, $head);
    expect($head)->not->toBeEmpty();

    preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $head[0], $blocks);
    $css = implode("\n", $blocks[1] ?? []);

    preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+);/i', $css, $matches, PREG_SET_ORDER);

    $properties = [];
    foreach ($matches as $match) {
        $properties[$match[1]] = trim($match[2]);
    }

    return $properties;
}

/**
 * Wrong implementations this must reject:
 *
 * 1. `$tokens['primary_color'] ?? $theme['primary_color'] ?? '#1e40af'`
 *    renderTokens() keys are `primary`, not `primary_color`. Every `??`
 *    falls through. Equality against this independently computed oracle
 *    fails; a "non-empty" assertion would pass.
 * 2. `--color-primary: ;` empty interpolation.
 * 3. `--brand-primary: <hex>` (today's layout) instead of
 *    `--brand-primary: var(--color-primary)`. Presence of the name is
 *    not enough — today's layout already emits --brand-primary as a hex.
 * 4. Hard-coded Inter font link. This tenant is not Inter, so equality
 *    on font_link_href fails. We do not assert "href must not contain
 *    family=inter" — that would fail a correct Inter tenant.
 */
test('shop layout emits every derived token equal to an independently computed renderTokens oracle', function () {
    ['tokens' => $tokens, 'html' => $html] = distinctiveStorefrontPage();

    $emitted = storefrontCssCustomProperties($html);

    $mismatches = [];
    foreach (storefrontTokenCssMap() as $tokenKey => $cssVar) {
        $actual = $emitted[$cssVar] ?? null;
        $expected = $tokens[$tokenKey];
        if ($actual === null) {
            $mismatches[$cssVar] = 'missing';

            continue;
        }
        if ($actual === '') {
            $mismatches[$cssVar] = 'empty (--x: ; form)';

            continue;
        }
        if ($actual !== $expected) {
            $mismatches[$cssVar] = ['expected' => $expected, 'actual' => $actual];
        }
    }

    expect($mismatches)->toBe([]);

    $aliasMismatches = [];
    foreach (storefrontBrandAliases() as $alias => $expected) {
        $actual = $emitted[$alias] ?? null;
        if ($actual !== $expected) {
            $aliasMismatches[$alias] = ['expected' => $expected, 'actual' => $actual];
        }
    }

    expect($aliasMismatches)->toBe([]);
});

test('shop layout consumes the derived tokens for fonts body headings and the site shell', function () {
    ['tokens' => $tokens, 'html' => $html] = distinctiveStorefrontPage();

    preg_match('/<head\b[^>]*>.*?<\/head>/is', $html, $head);
    expect($head)->not->toBeEmpty();
    $headHtml = $head[0];

    preg_match('/<link href="([^"]+)" rel="stylesheet"/i', $headHtml, $fontLink);
    expect($fontLink)->not->toBeEmpty();
    expect(html_entity_decode($fontLink[1], ENT_QUOTES | ENT_HTML5))->toBe($tokens['font_link_href']);

    preg_match('/<body\b([^>]*)>/i', $html, $bodyTag);
    expect($bodyTag)->not->toBeEmpty();
    preg_match('/\bclass="([^"]*)"/i', $bodyTag[1], $classAttr);
    $bodyClasses = preg_split('/\s+/', trim($classAttr[1] ?? '')) ?: [];
    expect($bodyClasses)->not->toContain('bg-white')
        ->and($bodyClasses)->not->toContain('text-gray-900');

    preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $headHtml, $blocks);
    $css = implode("\n", $blocks[1] ?? []);

    expect($css)->toContain('.site-shell-container')
        ->and($css)->toContain('max-width: var(--container-width)')
        ->and($css)->toContain('font-family: var(--font-body)')
        ->and($css)->toContain('background-color: var(--color-surface)')
        ->and($css)->toContain('color: var(--color-text)')
        ->and($css)->toContain('font-family: var(--font-display)')
        ->and($css)->toContain('letter-spacing: var(--heading-letter-spacing)');

    preg_match('/<main\b([^>]*)>/i', $html, $mainTag);
    expect($mainTag)->not->toBeEmpty();
    expect($mainTag[1])->toContain('site-shell-container');

    expect($html)->toContain("lucide.createIcons({ attrs: { 'stroke-width': '{$tokens['icon_stroke_width']}' } })");
});

test('shop layout ships compiled tailwind, pinned alpine, and vendored lucide', function () {
    ['html' => $html] = distinctiveStorefrontPage();

    expect($html)->not->toContain('cdn.tailwindcss.com')
        ->and($html)->not->toContain('fonts.bunny.net')
        ->and($html)->not->toContain('unpkg.com')
        ->and($html)->not->toContain('alpinejs@3.x.x')
        ->and($html)->not->toContain('cdn.jsdelivr.net/npm/alpinejs')
        ->and($html)->not->toContain('cdn.jsdelivr.net')
        ->and($html)->toMatch('/<script(?=[^>]*\bdefer\b)(?=[^>]*src="\/vendor\/alpine\.min\.js")[^>]*>/')
        ->and($html)->toMatch('/<script(?=[^>]*\bdefer\b)(?=[^>]*src="\/vendor\/lucide\.min\.js")[^>]*>/')
        ->and($html)->toMatch('/<link rel="stylesheet"[^>]*href="[^"]*\/site-[^"]*\.css"/');
});
