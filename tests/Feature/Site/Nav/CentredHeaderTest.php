<?php

use App\Enums\PageKind;
use App\Enums\PreviewLayout;
use App\Enums\Shop\ProductStatus;
use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\LayoutPreset;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use App\Support\ChromeKnobs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $siteAttrs
 * @return array{site: Site, home: GeneratedPage, about: GeneratedPage}
 */
function centredHeaderSite(
    string $host,
    string $chrome = 'classic',
    ?string $shopMode = null,
    array $siteAttrs = [],
    array $recipeOverrides = [],
): array {
    config(['site.use_versioned_renderer' => true]);

    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'business_name' => 'Camino Bakery',
        'preview_layout' => PreviewLayout::MultiPage,
        'chrome_layout' => $chrome,
        'right_action' => 'cta',
        'nav_cta_label' => 'Enquire',
        'nav_cta_url' => '/contact',
        ...$siteAttrs,
    ]);

    BusinessProfile::factory()->for($site)->create([
        'profile_data' => [
            'top_bar_enabled' => true,
            'geo' => ['service_area' => 'Brooklyn'],
            'contact' => ['phones' => ['020 7946 0000']],
        ],
    ]);

    if ($chrome !== 'classic') {
        LayoutPreset::factory()->for($site)->active()->create([
            'page_kind' => 'chrome',
            'key' => $chrome,
            'label' => 'Centred badge',
            'recipe' => [
                'schema_version' => 1,
                'layout' => 'centred',
                'top_bar' => 'off',
                'nav_row' => 'beneath',
                'nav_case' => 'caps',
                'logo_height' => 'md',
                'store_controls' => 'icons+labels',
                'sticky_shrink' => 'on',
                ...$recipeOverrides,
            ],
        ]);
    }

    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
        'nav_label' => 'Home',
    ]);
    $about = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'about',
        'kind' => PageKind::Core,
        'nav_label' => 'About',
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Welcome']]],
    ]);
    $aboutRev = PageRevision::factory()->for($about, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'About us']]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);
    $about->update(['published_revision_id' => $aboutRev->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => [
                ['type' => 'page', 'page_id' => $home->id, 'label' => 'Home'],
                ['type' => 'page', 'page_id' => $about->id, 'label' => 'About'],
            ]],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'],
            'homepage_page_id' => $home->id,
        ],
        'page_revisions' => [
            ['page_id' => $home->id, 'revision_id' => $homeRev->id],
            ['page_id' => $about->id, 'revision_id' => $aboutRev->id],
        ],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);

    if ($shopMode !== null) {
        $site->update(['shop_mode' => $shopMode]);
        $product = Product::factory()->for($site)->create([
            'slug' => 'sourdough',
            'name' => 'Sourdough',
            'status' => ProductStatus::Published,
        ]);
        $variant = ProductVariant::factory()->for($product)->create([
            'sku' => 'SD-1',
            'label' => 'Loaf',
            'price_cents' => 450,
        ]);
        VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 6]);
        $snap = ShopSnapshot::create([
            'site_id' => $site->id,
            'version' => 1,
            'status' => ShopSnapshotStatus::Success,
            'product_count' => 1,
            'json' => [
                'meta' => ['site_id' => $site->id, 'product_count' => 1],
                'categories' => [],
                'products' => [
                    'sourdough' => [
                        'id' => $product->id,
                        'slug' => 'sourdough',
                        'status' => 'published',
                        'primary_category_slug' => null,
                        'price_cents' => 450,
                        'price_display' => '£4.50',
                        'in_stock_any' => true,
                        'variant_in_stock' => [$variant->id => true],
                        'image_urls' => [],
                        'product_card' => ['slug' => 'sourdough', 'name' => 'Sourdough', 'price_display' => '£4.50'],
                        'product_detail' => ['slug' => 'sourdough', 'name' => 'Sourdough', 'description' => 'Crust'],
                        'variants' => [['id' => $variant->id, 'sku' => 'SD-1', 'label' => 'Loaf', 'price_cents' => 450, 'image_urls' => null]],
                        'is_ai_seeded' => false,
                        'is_ai_reviewed' => false,
                    ],
                ],
                'featured_slugs' => ['sourdough'],
            ],
            'built_at' => now(),
        ]);
        ShopSnapshotCurrent::create([
            'site_id' => $site->id,
            'snapshot_id' => $snap->id,
            'updated_at' => now(),
        ]);
    }

    return ['site' => $site->fresh(), 'home' => $home->fresh(), 'about' => $about->fresh()];
}

function centredHeaderHtml(Site $site, GeneratedPage $page): string
{
    return app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');
}

function centredHeaderTag(string $html): string
{
    expect(preg_match('/<header\b[^>]*>.*<\/header>/s', $html, $match))->toBe(1);

    return $match[0];
}

it('a standard site renders unchanged without data-header-layout', function () {
    ['site' => $site, 'home' => $home] = centredHeaderSite('standard-chrome.example');
    $html = centredHeaderHtml($site, $home);
    $header = centredHeaderTag($html);

    expect($html)->toContain('title="Serving Brooklyn"')
        ->and($header)->not->toContain('data-header-layout')
        ->not->toContain('data-chrome-preset');
});

it('a centred site has no top bar even when the profile has a service area', function () {
    ['site' => $site, 'home' => $home] = centredHeaderSite('centred-topbar.example', chrome: 'centred-badge');
    $html = centredHeaderHtml($site, $home);
    $header = centredHeaderTag($html);

    expect($html)->not->toContain('title="Serving Brooklyn"')
        ->and($header)->toContain('data-header-layout="centred"')
        ->toContain('data-chrome-preset="centred-badge"')
        ->not->toContain('Serving Brooklyn');
});

it('a centred site places the logo in the centred cell', function () {
    ['site' => $site, 'home' => $home] = centredHeaderSite('centred-logo.example', chrome: 'centred-badge');
    $header = centredHeaderTag(centredHeaderHtml($site, $home));

    expect($header)->toContain('justify-self-center')
        ->toContain('data-chrome-brand-row');
});

it('a centred cart-mode store shows search left and account plus bag right', function () {
    ['site' => $site, 'home' => $home] = centredHeaderSite(
        'centred-cart.example',
        chrome: 'centred-badge',
        shopMode: 'cart',
    );
    $html = centredHeaderHtml($site, $home);
    $header = centredHeaderTag($html);

    expect($header)->toContain('data-shop-search-toggle')
        ->toContain('>Search<')
        ->toContain('href="/shop/account/login"')
        ->toContain('>Account<')
        ->toContain('data-shop-cart-control')
        ->toContain('>Bag<')
        ->toContain('data-shop-cart-count')
        ->and(app(PageRenderer::class)->layoutContext($site)['shopAccountEnabled'])->toBeTrue()
        ->and(app(PageRenderer::class)->layoutContext($site)['shopCartEnabled'])->toBeTrue();
});

it('a centred enquire-mode store shows search left and no bag', function () {
    ['site' => $site, 'home' => $home] = centredHeaderSite(
        'centred-enquire.example',
        chrome: 'centred-badge',
        shopMode: 'enquire',
    );
    $header = centredHeaderTag(centredHeaderHtml($site, $home));

    expect($header)->toContain('data-shop-search-toggle')
        ->not->toContain('data-shop-cart-control')
        ->not->toContain('href="/shop/cart"')
        ->toContain('href="/shop/account/login"')
        ->and(app(PageRenderer::class)->layoutContext($site)['shopAccountEnabled'])->toBeTrue()
        ->and(app(PageRenderer::class)->layoutContext($site)['shopCartEnabled'])->toBeFalse();
});

it('a centred non-store site has no search and reuses the right action', function () {
    ['site' => $site, 'home' => $home] = centredHeaderSite('centred-plain.example', chrome: 'centred-badge');
    $header = centredHeaderTag(centredHeaderHtml($site, $home));

    expect($header)->not->toContain('data-shop-search-toggle')
        ->not->toContain('data-shop-cart-control')
        ->not->toContain('/shop/account/login')
        ->toContain('href="/contact"')
        ->toContain('>Enquire<');
});

it('a centred header includes the mobile block', function () {
    ['site' => $site, 'home' => $home] = centredHeaderSite('centred-mobile.example', chrome: 'centred-badge');
    $header = centredHeaderTag(centredHeaderHtml($site, $home));

    expect($header)->toContain('data-chrome-mobile-row')
        ->toContain('id="mobile-nav-panel"')
        ->toContain('aria-controls="mobile-nav-panel"');
});

it('marks the current nav item with aria-current', function () {
    ['site' => $site, 'about' => $about] = centredHeaderSite('centred-current.example', chrome: 'centred-badge');
    $header = centredHeaderTag(centredHeaderHtml($site, $about));

    expect($header)->toMatch('/href="[^"]*\/about"[^>]*aria-current="page"/')
        ->not->toMatch('/href="\/"[^>]*aria-current="page"/');
});

it('a centred header exposes sticky attributes', function () {
    ['site' => $site, 'home' => $home] = centredHeaderSite('centred-sticky.example', chrome: 'centred-badge');
    $header = centredHeaderTag(centredHeaderHtml($site, $home));

    expect($header)->toContain('data-chrome-nav-row')
        ->toContain('data-chrome-sticky="nav"')
        ->toContain('data-chrome-sticky-sentinel')
        ->toContain('sticky top-0');
});

it('a centred site with a brand image renders the data-brand-image layer at default opacity', function () {
    Storage::fake('s3');
    $path = 'sites/61/brand/bg.webp';
    ['site' => $site, 'home' => $home] = centredHeaderSite(
        'centred-brand-image.example',
        chrome: 'centred-badge',
        siteAttrs: ['brand_image_path' => $path],
        recipeOverrides: ['brand_pattern' => 'image'],
    );
    $header = centredHeaderTag(centredHeaderHtml($site, $home));
    $url = ChromeKnobs::brandImageUrl($site);

    expect($url)->not->toBeNull()
        ->and($header)->toContain('data-chrome-brand-band')
        ->toContain('data-brand-pattern="image"')
        ->toContain('data-brand-image')
        ->toContain("background-image: url('".e($url)."')")
        ->toContain('background-size: cover')
        ->toContain('background-position: center')
        ->toContain('opacity: 0.12')
        ->not->toContain('id="brand-pattern"');
});

it('public header HTML is byte-identical when brand_image_path is set and brand_image_media_id is null', function () {
    Storage::fake('s3');
    $path = 'sites/61/brand/bg.webp';
    ['site' => $site, 'home' => $home] = centredHeaderSite(
        'centred-brand-identity.example',
        chrome: 'centred-badge',
        siteAttrs: ['brand_image_path' => $path],
        recipeOverrides: ['brand_pattern' => 'image'],
    );

    expect($site->brand_image_media_id)->toBeNull();

    $withoutId = centredHeaderTag(centredHeaderHtml($site, $home));
    $media = \App\Models\SiteMedia::factory()->for($site)->create(['s3_key' => $path]);
    $site->update(['brand_image_media_id' => $media->id]);
    $withId = centredHeaderTag(centredHeaderHtml($site->fresh(), $home));

    expect($withId)->toBe($withoutId);
});

it('a tiled brand image uses background-repeat and auto size', function () {
    Storage::fake('s3');
    $path = 'sites/61/brand/tile.webp';
    ['site' => $site, 'home' => $home] = centredHeaderSite(
        'centred-brand-tile.example',
        chrome: 'centred-badge',
        siteAttrs: [
            'brand_image_path' => $path,
            'brand_image_fit' => 'tile',
        ],
        recipeOverrides: ['brand_pattern' => 'image'],
    );
    $header = centredHeaderTag(centredHeaderHtml($site, $home));

    expect($header)->toContain('data-brand-image')
        ->toContain('background-repeat: repeat')
        ->toContain('background-size: auto')
        ->not->toContain('background-size: cover');
});

it('brand image honours the vertical focal knob and defaults to centre', function () {
    Storage::fake('s3');
    $path = 'sites/61/brand/pins.webp';
    ['site' => $site, 'home' => $home] = centredHeaderSite(
        'centred-brand-posy.example',
        chrome: 'centred-badge',
        siteAttrs: [
            'brand_image_path' => $path,
            'brand_image_fit' => 'cover',
        ],
        recipeOverrides: ['brand_pattern' => 'image'],
    );

    $default = centredHeaderTag(centredHeaderHtml($site, $home));
    expect($default)->toContain('background-position: center 50%');

    $site->update(['brand_image_position_y' => 15]);
    $positioned = centredHeaderTag(centredHeaderHtml($site->fresh(), $home));
    expect($positioned)->toContain('background-position: center 15%');

    $site->update(['brand_image_position_y' => 400]);
    $clamped = centredHeaderTag(centredHeaderHtml($site->fresh(), $home));
    expect($clamped)->toContain('background-position: center 100%');
});

it('an image pattern with no file renders as none — no layer and no SVG', function () {
    ['site' => $site, 'home' => $home] = centredHeaderSite(
        'centred-brand-missing.example',
        chrome: 'centred-badge',
        recipeOverrides: ['brand_pattern' => 'image'],
    );
    $header = centredHeaderTag(centredHeaderHtml($site, $home));

    expect($header)->toContain('data-brand-pattern="none"')
        ->not->toContain('data-brand-image')
        ->not->toContain('id="brand-pattern"')
        ->not->toContain('background-image:');
});

it('store_control_style=pill wraps the brand-row controls in a solid chip; plain leaves them bare', function () {
    ['site' => $site, 'home' => $home] = centredHeaderSite(
        'centred-pill.example',
        chrome: 'centred-badge',
        shopMode: 'enquire',
        recipeOverrides: ['store_control_style' => 'pill'],
    );
    $header = centredHeaderTag(centredHeaderHtml($site, $home));
    expect($header)->toContain('data-shop-search-toggle')
        ->toContain('rounded-full px-3 py-1.5 shadow-sm ring-1 ring-black/10')
        ->toContain('background: rgba(255,255,255,0.9)');

    ['site' => $site, 'home' => $home] = centredHeaderSite('centred-plain.example', chrome: 'centred-badge', shopMode: 'enquire');
    $header = centredHeaderTag(centredHeaderHtml($site, $home));
    expect($header)->toContain('data-shop-search-toggle')
        ->not->toContain('ring-black/10');
});

it('renders every nav container style and fill on centred headers', function (string $style, string $fill) {
    ['site' => $site, 'home' => $home] = centredHeaderSite(
        "centred-nav-{$style}-{$fill}.example",
        chrome: 'centred-badge',
        siteAttrs: [
            'nav_container_style' => $style,
            'nav_container_fill' => $fill,
        ],
    );

    $header = centredHeaderTag(centredHeaderHtml($site, $home));
    $linkToken = $fill === 'brand' ? '--color-text-on-primary' : '--color-text';

    expect($header)->toContain('data-nav-container-style="'.$style.'"')
        ->toContain('data-nav-container-fill="'.$fill.'"')
        ->toContain('--nav-container-bg:')
        ->toContain('--nav-container-ink: var('.$linkToken.')')
        ->toContain('[data-nav-container][data-nav-container-style]>a:not(:hover)')
        ->toContain("stuck ? 'px-3 py-1.5' : 'px-5 py-2'");

    if ($style === 'band') {
        expect($header)->toContain('data-nav-container-band')
            ->toContain('col-start-1 col-end-4');
    }
})->with(['pill', 'plate', 'band'])
    ->with(['surface', 'glass', 'brand', 'pattern']);

it('keeps centred headers byte-identical when the nav container style is none', function () {
    ['site' => $defaultSite, 'home' => $defaultHome] = centredHeaderSite('centred-nav-default.example', chrome: 'centred-badge');
    ['site' => $noneSite, 'home' => $noneHome] = centredHeaderSite(
        'centred-nav-none.example',
        chrome: 'centred-badge',
        siteAttrs: [
            'nav_container_style' => 'none',
            'nav_container_fill' => 'pattern',
        ],
    );

    expect(centredHeaderTag(centredHeaderHtml($noneSite, $noneHome)))
        ->toBe(centredHeaderTag(centredHeaderHtml($defaultSite, $defaultHome)));
});

it('lets an explicit none override a recipe nav container while null inherits it', function () {
    ['site' => $inheritedSite, 'home' => $inheritedHome] = centredHeaderSite(
        'centred-nav-inherited.example',
        chrome: 'centred-badge',
        recipeOverrides: [
            'nav_container_style' => 'pill',
            'nav_container_fill' => 'brand',
        ],
    );
    ['site' => $noneSite, 'home' => $noneHome] = centredHeaderSite(
        'centred-nav-explicit-none.example',
        chrome: 'centred-badge',
        siteAttrs: [
            'nav_container_style' => 'none',
            'nav_container_fill' => 'surface',
        ],
        recipeOverrides: [
            'nav_container_style' => 'pill',
            'nav_container_fill' => 'brand',
        ],
    );

    expect(centredHeaderTag(centredHeaderHtml($inheritedSite, $inheritedHome)))
        ->toContain('data-nav-container-style="pill"')
        ->toContain('data-nav-container-fill="brand"')
        ->and(centredHeaderTag(centredHeaderHtml($noneSite, $noneHome)))
        ->not->toContain('data-nav-container');
});

it('shrinks centred nav container padding only when the recipe sticky shrink is on', function () {
    ['site' => $shrinkingSite, 'home' => $shrinkingHome] = centredHeaderSite(
        'centred-nav-shrinking.example',
        chrome: 'centred-badge',
        siteAttrs: ['nav_container_style' => 'pill'],
        recipeOverrides: ['sticky_shrink' => 'on'],
    );
    ['site' => $fixedSite, 'home' => $fixedHome] = centredHeaderSite(
        'centred-nav-fixed.example',
        chrome: 'centred-badge',
        siteAttrs: ['nav_container_style' => 'pill'],
        recipeOverrides: ['sticky_shrink' => 'off'],
    );

    expect(centredHeaderTag(centredHeaderHtml($shrinkingSite, $shrinkingHome)))
        ->toContain("stuck ? 'px-3 py-1.5' : 'px-5 py-2'")
        ->and(centredHeaderTag(centredHeaderHtml($fixedSite, $fixedHome)))
        ->toContain('data-nav-container')
        ->toContain('px-5 py-2')
        ->not->toContain("stuck ? 'px-3 py-1.5' : 'px-5 py-2'");
});

it('a centred nav row with a dark bg override and dots pattern paints the strip and uses light links', function () {
    ['site' => $site, 'home' => $home] = centredHeaderSite(
        'centred-nav-row-dots.example',
        chrome: 'centred-badge',
        siteAttrs: [
            'nav_row_bg' => '#111111',
            'nav_row_pattern' => 'dots',
        ],
    );
    $header = centredHeaderTag(centredHeaderHtml($site, $home));

    expect($header)->toContain('data-chrome-nav-row')
        ->toContain('data-nav-row-pattern="dots"')
        ->toContain('id="nav-row-pattern"')
        ->toContain('background-color: #111111')
        ->toContain('text-white/80 hover:text-white')
        ->not->toContain('data-nav-row-image');
});

it('stuck nav-row store controls go white on a dark nav row', function () {
    ['site' => $site, 'home' => $home] = centredHeaderSite(
        'centred-nav-row-dark-controls.example',
        chrome: 'centred-badge',
        shopMode: 'cart',
        siteAttrs: ['nav_row_bg' => '#2f1a45'],
    );
    $header = centredHeaderTag(centredHeaderHtml($site, $home));

    expect($header)->toContain('data-shop-cart-control')
        ->toContain('color: #ffffff;');
});

it('a standard nav row with a dark bg override and dots pattern paints the strip and uses light links', function () {
    ['site' => $site, 'home' => $home] = centredHeaderSite(
        'standard-nav-row-dots.example',
        siteAttrs: [
            'nav_row_bg' => '#111111',
            'nav_row_pattern' => 'dots',
        ],
    );
    $header = centredHeaderTag(centredHeaderHtml($site, $home));

    expect($header)->not->toContain('data-header-layout')
        ->toContain('data-nav-row-pattern="dots"')
        ->toContain('id="nav-row-pattern"')
        ->toContain('background-color: #111111')
        ->toContain('text-white/80 hover:text-white')
        ->not->toContain('data-nav-row-image');
});

it('null nav-row knobs omit the pattern layer on both layouts', function (string $chrome) {
    ['site' => $site, 'home' => $home] = centredHeaderSite(
        "nav-row-null-{$chrome}.example",
        chrome: $chrome,
    );
    $header = centredHeaderTag(centredHeaderHtml($site, $home));

    expect($header)->not->toContain('data-nav-row-pattern')
        ->not->toContain('data-nav-row-image')
        ->not->toContain('id="nav-row-pattern"')
        ->not->toContain('data-nav-row-accent');
})->with(['classic', 'centred-badge']);

it('a nav-row accent border paints an accent rule on both layouts', function (string $chrome) {
    ['site' => $site, 'home' => $home] = centredHeaderSite(
        "nav-row-accent-{$chrome}.example",
        chrome: $chrome,
        siteAttrs: ['nav_row_accent_border' => 'on'],
    );
    $header = centredHeaderTag(centredHeaderHtml($site, $home));

    expect($header)->toContain('data-nav-row-accent')
        ->toContain('background-color: var(--color-accent)');
})->with(['classic', 'centred-badge']);

it('no_hero accent mode suppresses the rule on pages starting with a hero and paints it elsewhere', function (string $chrome) {
    ['site' => $site, 'home' => $home] = centredHeaderSite(
        "nav-row-accent-nohero-{$chrome}.example",
        chrome: $chrome,
        siteAttrs: ['nav_row_accent_border' => 'no_hero'],
    );

    // The fixture home page starts with a hero → no always-on rule, but a
    // stuck-only one so the floating nav matches heroless pages once scrolled.
    $heroHeader = centredHeaderTag(centredHeaderHtml($site, $home));
    expect($heroHeader)->not->toContain('data-nav-row-accent="always"')
        ->toContain('data-nav-row-accent="stuck"')
        ->toContain('x-cloak');

    // Same page without the leading hero (public render reads the published revision) → painted outright.
    PageRevision::find($home->published_revision_id)
        ->update(['content_data' => ['sections' => [['type' => 'about-text', 'title' => 'Welcome', 'content' => 'Hello']]]]);
    $plainHeader = centredHeaderTag(centredHeaderHtml($site, $home->fresh()));
    expect($plainHeader)->toContain('data-nav-row-accent="always"')
        ->not->toContain('data-nav-row-accent="stuck"');
})->with(['classic', 'centred-badge']);

it('a nav-row image pattern honours opacity fit and position on both layouts', function (string $chrome) {
    Storage::fake('s3');
    $path = 'sites/61/nav-row/pins.webp';
    ['site' => $site, 'home' => $home] = centredHeaderSite(
        "nav-row-image-{$chrome}.example",
        chrome: $chrome,
        siteAttrs: [
            'nav_row_pattern' => 'image',
            'nav_row_image_path' => $path,
            'nav_row_image_opacity' => 20,
            'nav_row_image_fit' => 'cover',
            'nav_row_image_position_y' => 15,
        ],
    );
    $header = centredHeaderTag(centredHeaderHtml($site, $home));
    $url = ChromeKnobs::navRowImageUrl($site);

    expect($url)->not->toBeNull()
        ->and($header)->toContain('data-nav-row-pattern="image"')
        ->toContain('data-nav-row-image')
        ->toContain("background-image: url('".e($url)."')")
        ->toContain('background-size: cover')
        ->toContain('background-position: center 15%')
        ->toContain('opacity: 0.2')
        ->not->toContain('id="nav-row-pattern"');
})->with(['classic', 'centred-badge']);
