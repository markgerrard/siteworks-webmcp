<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\Product;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\Browser\ServerManager;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const SHOP_FILTERS_BROWSER_HOST = 'shop-test.domain.com';

function shopFiltersToolbarBrowserSite(): Site
{
    $site = Site::factory()->create([
        'custom_domain' => SHOP_FILTERS_BROWSER_HOST,
        'custom_domain_status' => 'active',
        'business_name' => 'Camino Bakery',
        'shop_mode' => 'cart',
        'shop_currency' => 'GBP',
    ]);

    Product::factory()->published()->for($site)->create(['slug' => 'row', 'name' => 'Row']);

    $products = [
        'low' => shopFiltersToolbarProduct('low', 'Low Cake', 1000, ['cakes']),
        'mid' => shopFiltersToolbarProduct('mid', 'Mid Cake', 2000, ['cakes']),
        'high' => shopFiltersToolbarProduct('high', 'High Cake', 9000, ['cakes', 'wedding-cakes']),
        'tall' => shopFiltersToolbarProduct('tall', 'Tall Cake', 8000, ['cakes', 'wedding-cakes']),
    ];

    $facets = [
        'category' => [
            ['slug' => 'wedding-cakes', 'name' => 'Wedding Cakes', 'count' => 2],
        ],
        'price' => [
            ['id' => 0, 'min' => 0, 'max' => 3000, 'label' => 'Under £30.00', 'count' => 2],
            ['id' => 2, 'min' => 8000, 'max' => null, 'label' => '£80.00+', 'count' => 2],
        ],
        'availability' => [],
        'options' => [],
    ];

    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'product_count' => 4,
        'json' => [
            'meta' => ['site_id' => $site->id, 'product_count' => 4, 'currency' => 'GBP'],
            'categories' => [
                'cakes' => [
                    'id' => 1,
                    'slug' => 'cakes',
                    'name' => 'Cakes',
                    'path' => 'cakes',
                    'visibility' => 'visible',
                    'children' => ['wedding-cakes'],
                    'product_slugs' => ['low', 'mid', 'high', 'tall'],
                    'breadcrumb' => [['name' => 'Cakes', 'path' => 'cakes']],
                    'facets' => $facets,
                ],
                'wedding-cakes' => [
                    'id' => 2,
                    'slug' => 'wedding-cakes',
                    'name' => 'Wedding Cakes',
                    'path' => 'cakes/wedding-cakes',
                    'visibility' => 'visible',
                    'children' => [],
                    'product_slugs' => ['high', 'tall'],
                    'breadcrumb' => [
                        ['name' => 'Cakes', 'path' => 'cakes'],
                        ['name' => 'Wedding Cakes', 'path' => 'cakes/wedding-cakes'],
                    ],
                    'facets' => $facets,
                ],
            ],
            'category_paths' => [
                'cakes' => 'cakes',
                'cakes/wedding-cakes' => 'wedding-cakes',
            ],
            'products' => $products,
            'featured_slugs' => ['low', 'mid', 'high', 'tall'],
            'facets' => $facets,
        ],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create([
        'site_id' => $site->id,
        'snapshot_id' => $snap->id,
        'updated_at' => now(),
    ]);

    return $site;
}

/**
 * @param  list<string>  $cats
 * @return array<string, mixed>
 */
function shopFiltersToolbarProduct(string $slug, string $name, int $price, array $cats): array
{
    return [
        'id' => crc32($slug),
        'slug' => $slug,
        'status' => 'published',
        'primary_category_slug' => 'cakes',
        'price_cents' => $price,
        'price_display' => '£'.number_format($price / 100, 2),
        'in_stock_any' => true,
        'variant_in_stock' => [1 => true],
        'image_urls' => ['thumb' => '/a.jpg', 'card' => '/a.jpg', 'full' => '/a.jpg'],
        'product_card' => ['slug' => $slug, 'name' => $name, 'price_display' => '£'.number_format($price / 100, 2)],
        'product_detail' => ['slug' => $slug, 'name' => $name, 'description' => $name],
        'variants' => [['id' => abs(crc32($slug)), 'sku' => strtoupper($slug), 'label' => 'Std', 'price_cents' => $price, 'image_urls' => null]],
        'is_ai_seeded' => false,
        'is_ai_reviewed' => false,
        'f' => ['c' => $cats, 'p' => $price, 'a' => 'in', 'o' => []],
    ];
}

function shopFiltersToolbarUrl(string $path = '/shop/c/cakes'): string
{
    $serverBase = ServerManager::instance()->http()->rewrite('/');
    $port = parse_url($serverBase, PHP_URL_PORT);
    $origin = 'http://'.SHOP_FILTERS_BROWSER_HOST.($port ? ':'.$port : '');
    app('url')->useOrigin($origin);
    config(['app.url' => $origin]);

    return $origin.$path;
}

function visitShopFiltersToolbar(string $path = '/shop/c/cakes')
{
    $page = visit(shopFiltersToolbarUrl($path))->withHost(SHOP_FILTERS_BROWSER_HOST);
    $page->resize(1440, 900);
    $ready = $page->script(<<<'JS'
        new Promise((resolve) => {
            const start = performance.now();
            const tick = () => {
                const toolbar = document.getElementById('shop-listing-toolbar');
                if (window.Alpine && toolbar) {
                    resolve({ ok: true });
                    return;
                }
                if (performance.now() - start > 4000) {
                    resolve({ ok: false, alpine: Boolean(window.Alpine), toolbar: Boolean(toolbar) });
                    return;
                }
                requestAnimationFrame(tick);
            };
            tick();
        })
    JS);
    expect($ready['ok'] ?? false)->toBeTrue();

    return $page;
}

it('opens the sort menu, picks price high to low, and updates the card order and URL', function () {
    shopFiltersToolbarBrowserSite();
    $page = visitShopFiltersToolbar();

    $clicked = $page->script(<<<'JS'
        (() => {
            const btn = document.getElementById('shop-sort-button');
            if (! btn) {
                return { clicked: false };
            }
            btn.click();
            const item = Array.from(document.querySelectorAll('#shop-sort-menu [role="menuitem"]'))
                .find((el) => (el.textContent || '').includes('Highest'));
            if (! item) {
                return { clicked: true, item: false, open: true };
            }
            item.click();
            return { clicked: true, item: true };
        })()
    JS);
    expect($clicked['clicked'] ?? false)->toBeTrue()
        ->and($clicked['item'] ?? false)->toBeTrue();

    $page->wait(0.6);
    $state = $page->script(<<<'JS'
        (() => {
            const names = Array.from(document.querySelectorAll('.shop-product-card .font-semibold'))
                .map((el) => (el.textContent || '').trim());
            return { names, href: window.location.href };
        })()
    JS);

    expect($state['href'] ?? '')->toContain('sort=price_desc')
        ->and($state['names'][0] ?? '')->toBe('High Cake')
        ->and($state['names'][1] ?? '')->toBe('Tall Cake');
    $page->assertNoJavaScriptErrors();
});

it('applies category and price filters from the drawer, shows pills, then clears back to all', function () {
    shopFiltersToolbarBrowserSite();
    $page = visitShopFiltersToolbar();

    $opened = $page->script(<<<'JS'
        (() => {
            const btn = Array.from(document.querySelectorAll('button'))
                .find((el) => (el.getAttribute('aria-controls') === 'shop-filters-drawer'));
            if (! btn) {
                return { opened: false };
            }
            btn.click();
            const drawer = document.getElementById('shop-filters-drawer');
            const cat = drawer && drawer.querySelector('input[name="cat[]"][value="wedding-cakes"]');
            const price = drawer && drawer.querySelector('input[name="price[]"][value="2"]');
            if (cat) {
                cat.checked = true;
            }
            if (price) {
                price.checked = true;
            }
            return {
                opened: Boolean(drawer),
                cat: Boolean(cat),
                price: Boolean(price),
            };
        })()
    JS);
    expect($opened['opened'] ?? false)->toBeTrue()
        ->and($opened['cat'] ?? false)->toBeTrue()
        ->and($opened['price'] ?? false)->toBeTrue();

    $page->press('Apply filters');
    $page->wait(0.8);

    $filtered = $page->script(<<<'JS'
        (() => {
            const names = Array.from(document.querySelectorAll('.shop-product-card .font-semibold'))
                .map((el) => (el.textContent || '').trim());
            const count = (document.querySelector('#shop-listing-toolbar [aria-live]') || {}).textContent || '';
            const pills = document.querySelector('[aria-label="Active filters"]');
            return { names, count, pills: Boolean(pills), href: window.location.href };
        })()
    JS);

    expect($filtered['count'] ?? '')->toContain('Showing 2 items')
        ->and($filtered['pills'] ?? false)->toBeTrue()
        ->and($filtered['names'])->toHaveCount(2);

    $page->press('#shop-filter-button');
    $page->press('Clear filters');
    $page->wait(0.8);
    $cleared = $page->script(<<<'JS'
        (() => {
            const count = (document.querySelector('#shop-listing-toolbar [aria-live]') || {}).textContent || '';
            const names = Array.from(document.querySelectorAll('.shop-product-card .font-semibold')).length;
            return { count, names };
        })()
    JS);
    expect($cleared['count'] ?? '')->toContain('Showing 4 items')
        ->and($cleared['names'])->toBe(4);
    $page->assertNoJavaScriptErrors();
});

it('opens the filter drawer from the Filter button with Enter and returns focus on Escape', function () {
    shopFiltersToolbarBrowserSite();
    $page = visitShopFiltersToolbar();

    $page->keys('#shop-sort-button', 'Tab');
    $focused = $page->script("document.activeElement && document.activeElement.id");
    expect($focused)->toBe('shop-filter-button');

    $page->keys('#shop-filter-button', 'Enter');
    $page->wait(0.1);
    $opened = $page->script(<<<'JS'
        (() => ({
            open: Boolean(window.Alpine && Alpine.$data(document.getElementById('shop-filters')).open),
            mainInert: document.querySelector('main').hasAttribute('inert'),
            activeLabel: document.activeElement && document.activeElement.getAttribute('aria-label'),
        }))()
    JS);

    expect($opened['open'] ?? false)->toBeTrue()
        ->and($opened['mainInert'] ?? false)->toBeTrue()
        ->and($opened['activeLabel'] ?? '')->toBe('Close filters');

    $page->keys('[aria-label="Close filters"]', 'Escape');
    $keys = $page->script(<<<'JS'
        (() => {
            const data = window.Alpine && Alpine.$data(document.getElementById('shop-filters'));

            return {
                closed: data ? data.open === false : null,
                activeId: document.activeElement && document.activeElement.id,
                mainInert: document.querySelector('main').hasAttribute('inert'),
            };
        })()
    JS);

    expect($keys['closed'] ?? null)->toBeTrue()
        ->and($keys['activeId'] ?? '')->toBe('shop-filter-button')
        ->and($keys['mainInert'] ?? true)->toBeFalse();
    $page->assertNoJavaScriptErrors();
});

it('keeps the toolbar on one row at 1280 and wraps below md', function () {
    shopFiltersToolbarBrowserSite();
    $page = visitShopFiltersToolbar();
    $page->resize(1280, 812);

    $desktop = $page->script(<<<'JS'
        (() => {
            const toolbar = document.getElementById('shop-listing-toolbar');
            const count = toolbar && toolbar.querySelector('[aria-live]');
            const controls = toolbar && count && count.nextElementSibling;

            return {
                countTop: count && Math.round(count.getBoundingClientRect().top),
                controlsTop: controls && Math.round(controls.getBoundingClientRect().top),
            };
        })()
    JS);

    expect($desktop['countTop'] ?? null)->toBe($desktop['controlsTop'] ?? null);

    $page->resize(375, 812);

    $mobile = $page->script(<<<'JS'
        (() => {
            const toolbar = document.getElementById('shop-listing-toolbar');
            const count = toolbar && toolbar.querySelector('[aria-live]');
            const controls = toolbar && count && count.nextElementSibling;

            return {
                countTop: count && Math.round(count.getBoundingClientRect().top),
                controlsTop: controls && Math.round(controls.getBoundingClientRect().top),
            };
        })()
    JS);

    expect($mobile['controlsTop'] ?? 0)->toBeGreaterThan($mobile['countTop'] ?? 0);

    $page->press('#shop-filter-button');
    $page->wait(0.1);
    $drawer = $page->script(<<<'JS'
        (() => {
            const doc = document.documentElement;
            const panel = document.getElementById('shop-filters-drawer');

            return {
                open: Boolean(panel && panel.getBoundingClientRect().width > 0),
                width: panel && Math.round(panel.getBoundingClientRect().width),
                inner: window.innerWidth,
                scroll: Math.max(doc.scrollWidth, document.body.scrollWidth),
            };
        })()
    JS);

    expect($drawer['open'] ?? false)->toBeTrue()
        ->and($drawer['width'] ?? 0)->toBeLessThanOrEqual($drawer['inner'] ?? 0)
        ->and($drawer['scroll'] ?? 999)->toBeLessThanOrEqual(($drawer['inner'] ?? 0) + 1);
    $page->assertNoJavaScriptErrors();
});
