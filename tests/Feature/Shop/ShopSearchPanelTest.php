<?php

use App\Enums\PageKind;
use App\Enums\PreviewLayout;
use App\Enums\Shop\ProductStatus;
use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
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
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $siteAttrs
 * @return array{site: Site, host: string}
 */
function shopSearchPanelSite(string $host, string $shopMode = 'cart', array $siteAttrs = []): array
{
    config(['site.use_versioned_renderer' => true]);

    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'business_name' => 'Bloom & Stem',
        'shop_mode' => $shopMode,
        'preview_layout' => PreviewLayout::MultiPage,
        ...$siteAttrs,
    ]);

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
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Welcome to the bakery'],
        ]],
    ]);
    $aboutRev = PageRevision::factory()->for($about, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'About the bakery'],
        ]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);
    $about->update(['published_revision_id' => $aboutRev->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => [
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

    $product = Product::factory()->for($site)->create([
        'slug' => 'bakewell',
        'name' => 'Bakewell Tart',
        'status' => ProductStatus::Published,
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'sku' => 'BT-1',
        'label' => 'Each',
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
            'categories' => [
                'tarts' => [
                    'id' => 1,
                    'slug' => 'tarts',
                    'name' => 'Tarts',
                    'product_slugs' => ['bakewell'],
                ],
                'empty-shelf' => [
                    'id' => 2,
                    'slug' => 'empty-shelf',
                    'name' => 'Empty shelf',
                    'product_slugs' => [],
                ],
            ],
            'products' => [
                'bakewell' => [
                    'id' => $product->id,
                    'slug' => 'bakewell',
                    'status' => 'published',
                    'primary_category_slug' => 'tarts',
                    'price_cents' => 450,
                    'price_display' => '£4.50',
                    'in_stock_any' => true,
                    'variant_in_stock' => [$variant->id => true],
                    'image_urls' => ['thumb' => '/bakewell-thumb.jpg', 'card' => '/bakewell-card.jpg', 'full' => '/bakewell-full.jpg'],
                    'product_card' => ['slug' => 'bakewell', 'name' => 'Bakewell Tart', 'price_display' => '£4.50'],
                    'product_detail' => ['slug' => 'bakewell', 'name' => 'Bakewell Tart', 'description' => 'Almond'],
                    'variants' => [['id' => $variant->id, 'sku' => 'BT-1', 'label' => 'Each', 'price_cents' => 450, 'image_urls' => null]],
                    'is_ai_seeded' => false,
                    'is_ai_reviewed' => false,
                ],
            ],
            'featured_slugs' => ['bakewell'],
        ],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create([
        'site_id' => $site->id,
        'snapshot_id' => $snap->id,
        'updated_at' => now(),
    ]);

    return ['site' => $site->fresh(), 'host' => $host];
}

function shopSearchPanelHtml(string $html): void
{
    expect($html)->toContain('data-shop-search-toggle')
        ->and($html)->toMatch('/<button\b[^>]*\btype="button"[^>]*\bdata-shop-search-toggle/i')
        ->and($html)->toMatch('/data-shop-search-toggle[^>]*aria-label="Search the bakery"/i')
        ->and($html)->toMatch('/data-shop-search-toggle[^>]*aria-expanded="false"/i')
        ->and($html)->toMatch('/data-shop-search-toggle[^>]*aria-controls="shop-search-panel"/i')
        ->and($html)->toContain('data-lucide="search"');

    preg_match_all('/<button\b[^>]*\bdata-shop-search-toggle/', $html, $toggles);
    expect($toggles[0])->toHaveCount(2);
}

test('the header search toggle renders on every page of a purchasable shop', function (string $shopMode, string $path) {
    $host = 'panel-'.$shopMode.'-'.substr(md5($path), 0, 8).'.example';
    shopSearchPanelSite($host, $shopMode);

    shopSearchPanelHtml(
        $this->get('http://'.$host.$path)->assertSuccessful()->getContent()
    );
})->with(['cart', 'enquire'])->with([
    'home' => '/',
    'about' => '/about',
    'shop' => '/shop',
    'category' => '/collections/tarts',
    'product' => '/products/bakewell',
    'search' => '/shop/search',
]);

test('a site without a shop does not render the header search toggle', function () {
    config(['site.use_versioned_renderer' => true]);

    $site = Site::factory()->create([
        'custom_domain' => 'no-shop-panel.example',
        'custom_domain_status' => 'active',
        'preview_layout' => PreviewLayout::MultiPage,
    ]);
    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
    ]);
    $about = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'about',
        'kind' => PageKind::Core,
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Hello']]],
    ]);
    $aboutRev = PageRevision::factory()->for($about, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'About']]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);
    $about->update(['published_revision_id' => $aboutRev->id]);
    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
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

    expect($this->get('http://no-shop-panel.example/')->assertSuccessful()->getContent())
        ->not->toContain('data-shop-search-toggle')
        ->and($this->get('http://no-shop-panel.example/about')->assertSuccessful()->getContent())
        ->not->toContain('data-shop-search-toggle');
});

test('layoutContext enables shopSearchEnabled for cart and enquire shops but not for a site without a shop', function () {
    ['site' => $cart] = shopSearchPanelSite('ctx-cart-search.example', 'cart');
    ['site' => $enquire] = shopSearchPanelSite('ctx-enquire-search.example', 'enquire');
    $plain = Site::factory()->create();

    $renderer = app(PageRenderer::class);

    expect($renderer->layoutContext($cart)['shopSearchEnabled'])->toBeTrue()
        ->and($renderer->layoutContext($enquire)['shopSearchEnabled'])->toBeTrue()
        ->and($renderer->layoutContext($plain)['shopSearchEnabled'])->toBeFalse();
});

test('the header search panel is present on a shop site and absent without a shop', function () {
    shopSearchPanelSite('panel-present.example');

    $shopHtml = $this->get('http://panel-present.example/shop')->assertSuccessful()->getContent();
    expect($shopHtml)->toContain('id="shop-search-panel"')
        ->and($shopHtml)->toMatch('/<header[^>]*x-data="\{[^"]*\.\.\.shopSearch\(\)[^"]*\}"[^>]*data-shop-search-url=/')
        ->and($shopHtml)->toMatch('/id="shop-search-panel"[^>]*x-show="open"/')
        ->and($shopHtml)->toMatch('/id="shop-search-panel"[^>]*x-cloak/')
        ->and($shopHtml)->toContain('@keydown.escape.window="close()"')
        ->and($shopHtml)->toContain('@click.outside="close()"');

    preg_match('/id="shop-search-panel".*$/s', $shopHtml, $fromPanel);
    $panelTail = $fromPanel[0] ?? '';
    expect($panelTail)->not->toContain('document.write')
        ->and($panelTail)->not->toContain('onclick=')
        ->and($panelTail)->not->toContain('jsdelivr')
        ->and($panelTail)->not->toContain('cdn.jsdelivr.net');

    config(['site.use_versioned_renderer' => true]);
    $plain = Site::factory()->create([
        'custom_domain' => 'panel-absent.example',
        'custom_domain_status' => 'active',
        'preview_layout' => PreviewLayout::MultiPage,
    ]);
    $home = GeneratedPage::factory()->for($plain)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
    ]);
    $rev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Hello']]],
    ]);
    $home->update(['published_revision_id' => $rev->id]);
    $version = SiteVersion::create([
        'site_id' => $plain->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'],
            'homepage_page_id' => $home->id,
        ],
        'page_revisions' => [['page_id' => $home->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create([
        'site_id' => $plain->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);

    expect($this->get('http://panel-absent.example/')->assertSuccessful()->getContent())
        ->not->toContain('id="shop-search-panel"');
});

test('the search panel form posts a GET to shop search with bakery copy', function () {
    shopSearchPanelSite('panel-form.example');
    $html = $this->get('http://panel-form.example/shop')->assertSuccessful()->getContent();

    preg_match('/<div[^>]*id="shop-search-panel".*?<\/div>\s*<\/div>/s', $html, $panelWrap);
    $panel = $panelWrap[0] ?? $html;

    expect($panel)->toMatch('/<form\b[^>]*\bmethod="GET"[^>]*\brole="search"/i')
        ->and($panel)->toMatch('/action="[^"]*\/shop\/search"/')
        ->and($panel)->toMatch('/<input\b[^>]*\btype="search"[^>]*\bname="q"/i')
        ->and($panel)->toMatch('/placeholder="Search the bakery"/')
        ->and($panel)->toMatch('/aria-label="Search the bakery"/')
        ->and($panel)->toMatch('/autocomplete="off"/')
        ->and($panel)->toContain('x-ref="q"')
        ->and($panel)->toContain('x-model.debounce.250ms="q"')
        ->and($panel)->toMatch('/<button\b[^>]*\btype="submit"[^>]*>Search<\/button>/')
        ->and($panel)->toMatch('/<button\b[^>]*\btype="button"[^>]*aria-label="Close search"/');
});

test('panel chips list only categories with published products', function () {
    shopSearchPanelSite('panel-chips.example');
    $html = $this->get('http://panel-chips.example/shop')->assertSuccessful()->getContent();

    preg_match('/<nav[^>]*aria-label="Browse by category".*?<\/nav>/s', $html, $nav);
    expect($nav)->not->toBeEmpty();
    expect($nav[0])->toContain('Tarts')
        ->and($nav[0])->toMatch('/href="[^"]*\/collections\/tarts"/')
        ->and($nav[0])->not->toContain('Empty shelf')
        ->and($nav[0])->not->toContain('/collections/empty-shelf');
});

test('the current category chip is marked current on its category page', function () {
    shopSearchPanelSite('panel-current.example');
    $html = $this->get('http://panel-current.example/collections/tarts')->assertSuccessful()->getContent();

    preg_match('/<nav[^>]*aria-label="Browse by category".*?<\/nav>/s', $html, $nav);
    expect($nav)->not->toBeEmpty();
    expect($nav[0])->toMatch('/href="[^"]*\/collections\/tarts"[^>]*aria-current="true"/');
});

test('the results page prefills the header search input from q', function () {
    shopSearchPanelSite('panel-prefill.example');
    $html = $this->get('http://panel-prefill.example/shop/search?q=Bakewell')->assertSuccessful()->getContent();

    expect($html)->toContain('id="shop-search-panel"')
        ->and($html)->toMatch('/x-ref="q"[^>]*(?:value="Bakewell")|(?:value="Bakewell")[^>]*x-ref="q"/');
});

test('an empty query panel lists popular featured products', function () {
    shopSearchPanelSite('panel-popular.example');
    $html = $this->get('http://panel-popular.example/shop')->assertSuccessful()->getContent();

    expect($html)->toContain('Popular')
        ->and($html)->toContain('Bakewell Tart')
        ->and($html)->toMatch('/href="[^"]*\/products\/bakewell"/');
});

test('the search icon renders in overlay and solid header modes', function () {
    ['site' => $site, 'host' => $host] = shopSearchPanelSite('panel-overlay.example', 'cart', [
        'header_mode' => 'overlay',
    ]);
    HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/bakery-hero.jpg',
    ]);

    $overlay = $this->get('http://'.$host.'/')->assertSuccessful()->getContent();
    $solid = $this->get('http://'.$host.'/shop')->assertSuccessful()->getContent();

    expect($overlay)->toContain('data-header-mode="overlay"')
        ->and($overlay)->toContain('data-shop-search-toggle')
        ->and($overlay)->toContain('data-lucide="search"')
        ->and($solid)->not->toContain('data-header-mode="overlay"')
        ->and($solid)->toContain('data-shop-search-toggle')
        ->and($solid)->toContain('data-lucide="search"');
});
