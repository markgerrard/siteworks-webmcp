<?php

use App\Enums\PageKind;
use App\Enums\PageStatus;
use App\Enums\PreviewLayout;
use App\Enums\Shop\ProductStatus;
use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\GeneratedPage;
use App\Models\LayoutPreset;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\Browser\Playwright\Client;
use Pest\Browser\ServerManager;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $siteAttrs
 * @return array{site: Site, host: string}
 */
function shopMegaMenuBrowserSite(string $layout = 'standard', array $siteAttrs = []): array
{
    config(['site.use_versioned_renderer' => true]);

    $host = 'shop-test.domain.com';
    $chrome = $layout === 'centred' ? 'centred-badge' : 'classic';

    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'business_name' => 'Camino Bakery',
        'preview_layout' => PreviewLayout::MultiPage,
        'shop_nav_style' => 'mega',
        'chrome_layout' => $chrome,
        ...$siteAttrs,
    ]);

    if ($layout === 'centred') {
        LayoutPreset::factory()->for($site)->active()->create([
            'page_kind' => 'chrome',
            'key' => 'centred-badge',
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
            ],
        ]);
    }

    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
        'nav_label' => 'Home',
        'status' => PageStatus::Published,
    ]);
    $about = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'about',
        'kind' => PageKind::Core,
        'nav_label' => 'About',
        'status' => PageStatus::Published,
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Welcome'],
            ['type' => 'services', 'title' => 'Our Services', 'items' => [
                ['title' => 'A', 'body' => str_repeat('Tall content. ', 80)],
                ['title' => 'B', 'body' => str_repeat('More content. ', 80)],
            ]],
        ]],
    ]);
    $aboutRev = PageRevision::factory()->for($about, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'About us']]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);
    $about->update(['published_revision_id' => $aboutRev->id]);

    $navItems = [
        ['type' => 'page', 'page_id' => $about->id, 'label' => 'About'],
        ['type' => 'shop', 'label' => 'Shop'],
    ];
    $composition = [
        'nav' => ['items' => $navItems],
        'footer' => ['columns' => [], 'show_credit' => true],
        'theme' => ['key' => 'trades-bold'],
        'homepage_page_id' => $home->id,
    ];

    SiteDraft::create([
        'site_id' => $site->id,
        'composition' => $composition,
        'updated_at' => now(),
    ]);
    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => $composition,
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
        'slug' => 'victoria',
        'name' => 'Victoria sponge',
        'status' => ProductStatus::Published,
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'sku' => 'VS-1',
        'price_cents' => 1800,
    ]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 4]);

    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'product_count' => 1,
        'json' => [
            'meta' => ['site_id' => $site->id, 'product_count' => 1],
            'categories' => [
                'cakes' => [
                    'id' => 1,
                    'slug' => 'cakes',
                    'name' => 'Celebration Cakes',
                    'parent_slug' => null,
                    'path' => 'cakes',
                    'depth' => 1,
                    'visibility' => 'visible',
                    'sort_order' => 1,
                    'children' => ['birthdays', 'wedding-cakes'],
                    'product_slugs' => ['victoria'],
                ],
                'birthdays' => [
                    'id' => 2,
                    'slug' => 'birthdays',
                    'name' => 'Birthdays',
                    'parent_slug' => 'cakes',
                    'path' => 'cakes/birthdays',
                    'depth' => 2,
                    'visibility' => 'visible',
                    'sort_order' => 1,
                    'children' => [],
                    'product_slugs' => [],
                ],
                'wedding-cakes' => [
                    'id' => 3,
                    'slug' => 'wedding-cakes',
                    'name' => 'Weddings',
                    'parent_slug' => 'cakes',
                    'path' => 'cakes/wedding-cakes',
                    'depth' => 2,
                    'visibility' => 'visible',
                    'sort_order' => 2,
                    'children' => [],
                    'product_slugs' => [],
                ],
                'tarts' => [
                    'id' => 5,
                    'slug' => 'tarts',
                    'name' => 'Tarts',
                    'parent_slug' => null,
                    'path' => 'tarts',
                    'depth' => 1,
                    'visibility' => 'visible',
                    'sort_order' => 2,
                    'children' => [],
                    'product_slugs' => [],
                ],
                'biscuits' => [
                    'id' => 6,
                    'slug' => 'biscuits',
                    'name' => 'Biscuits',
                    'parent_slug' => null,
                    'path' => 'biscuits',
                    'depth' => 1,
                    'visibility' => 'visible',
                    'sort_order' => 3,
                    'children' => [],
                    'product_slugs' => [],
                ],
            ],
            'products' => [
                'victoria' => [
                    'id' => $product->id,
                    'slug' => 'victoria',
                    'status' => 'published',
                    'name' => 'Victoria sponge',
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

    return ['site' => $site->fresh(), 'host' => $host];
}

function shopMegaMenuBrowserUrl(string $path = '/'): string
{
    $host = 'shop-test.domain.com';
    $serverBase = ServerManager::instance()->http()->rewrite('/');
    $port = parse_url($serverBase, PHP_URL_PORT);
    $origin = 'http://'.$host.($port ? ':'.$port : '');
    app('url')->useOrigin($origin);
    config(['app.url' => $origin]);

    return $origin.$path;
}

function visitShopMegaMenu()
{
    $page = visit(shopMegaMenuBrowserUrl('/'))->withHost('shop-test.domain.com');
    $page->resize(1280, 900);
    $ready = $page->script(<<<'JS'
        new Promise((resolve) => {
            const start = performance.now();
            const tick = () => {
                const trigger = document.querySelector('[data-shop-nav-style="mega"]:not([data-shop-nav-mobile]) [x-ref="shopTrigger"]');
                if (window.Alpine && trigger) {
                    resolve({ ok: true });
                    return;
                }
                if (performance.now() - start > 4000) {
                    resolve({
                        ok: false,
                        alpine: Boolean(window.Alpine),
                        trigger: Boolean(trigger),
                    });
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

/**
 * Real Playwright mouseMove (page GUID). Synthesized JS mouse events do not
 * generate mouseenter/leave across the header gap — that is the bug.
 */
function shopMegaMouseMove(mixed $page, float $x, float $y): void
{
    $playwrightPage = $page->page();
    $guid = (new ReflectionProperty($playwrightPage, 'guid'))->getValue($playwrightPage);
    iterator_to_array(Client::instance()->execute($guid, 'mouseMove', [
        'x' => $x,
        'y' => $y,
        'steps' => 1,
    ]));
}

function shopMegaPointerWalk(mixed $page, float $fromX, float $fromY, float $toX, float $toY, int $stepPx, int $delayMs): void
{
    $dx = $toX - $fromX;
    $dy = $toY - $fromY;
    $dist = hypot($dx, $dy);
    if ($dist <= 0.0) {
        shopMegaMouseMove($page, $toX, $toY);

        return;
    }
    $steps = max(1, (int) ceil($dist / $stepPx));
    for ($i = 1; $i <= $steps; $i++) {
        $t = $i / $steps;
        shopMegaMouseMove($page, $fromX + ($dx * $t), $fromY + ($dy * $t));
        usleep($delayMs * 1000);
    }
}

/**
 * @return array<string, mixed>
 */
function shopMegaMeasureJs(): string
{
    return <<<'JS'
        (() => {
            const wrap = document.querySelector('[data-shop-nav-style="mega"]:not([data-shop-nav-mobile])');
            const trigger = wrap ? wrap.querySelector('[x-ref="shopTrigger"]') : null;
            const panel = wrap ? (wrap.querySelector('[data-shop-mega-panel]') || Array.from(wrap.querySelectorAll('div')).find((el) => el !== wrap && getComputedStyle(el).position !== 'static' && (el.textContent || '').includes('Celebration Cakes'))) : null;
            const firstLink = panel ? panel.querySelector('a') : null;
            const tr = trigger ? trigger.getBoundingClientRect() : null;
            const panelCs = panel ? getComputedStyle(panel) : null;
            const panelVisible = Boolean(panelCs && panelCs.display !== 'none' && panelCs.visibility !== 'hidden');
            const pr = panelVisible && panel ? panel.getBoundingClientRect() : null;
            const lr = firstLink && panelVisible ? firstLink.getBoundingClientRect() : null;
            const open = wrap && window.Alpine ? Boolean(Alpine.$data(wrap).open) : false;
            const hit = lr ? document.elementFromPoint(lr.left + Math.min(12, lr.width / 2), lr.top + Math.min(12, lr.height / 2)) : null;
            return {
                open,
                aria: trigger ? trigger.getAttribute('aria-expanded') : null,
                trigger: tr ? {
                    left: tr.left, right: tr.right, top: tr.top, bottom: tr.bottom,
                    cx: (tr.left + tr.right) / 2, cy: (tr.top + tr.bottom) / 2,
                    width: tr.width, height: tr.height,
                } : null,
                panel: pr ? {
                    left: pr.left, right: pr.right, top: pr.top, bottom: pr.bottom,
                    width: pr.width, height: pr.height,
                    position: panelCs ? panelCs.position : null,
                } : null,
                firstLink: lr && firstLink ? {
                    left: lr.left, right: lr.right, top: lr.top, bottom: lr.bottom,
                    cx: (lr.left + lr.right) / 2, cy: (lr.top + lr.bottom) / 2,
                    href: firstLink.getAttribute('href'),
                    text: (firstLink.textContent || '').trim(),
                } : null,
                hitIsFirstLink: Boolean(hit && firstLink && (hit === firstLink || firstLink.contains(hit))),
                innerWidth: window.innerWidth,
                docScrollWidth: document.documentElement.scrollWidth,
                activeTag: document.activeElement ? document.activeElement.tagName : null,
                activeIsTrigger: Boolean(trigger && document.activeElement === trigger),
                gap: (tr && pr) ? (pr.top - tr.bottom) : null,
            };
        })()
    JS;
}

function shopMegaWaitOpen(mixed $page): array
{
    /** @var array<string, mixed> $state */
    $state = $page->script(<<<'JS'
        new Promise((resolve) => {
            const start = performance.now();
            const read = () => {
                const wrap = document.querySelector('[data-shop-nav-style="mega"]:not([data-shop-nav-mobile])');
                const trigger = wrap ? wrap.querySelector('[x-ref="shopTrigger"]') : null;
                const panel = wrap ? (wrap.querySelector('[data-shop-mega-panel]') || Array.from(wrap.querySelectorAll('div')).find((el) => el !== wrap && (el.textContent || '').includes('Celebration Cakes'))) : null;
                const firstLink = panel ? panel.querySelector('a') : null;
                const tr = trigger ? trigger.getBoundingClientRect() : null;
                const panelCs = panel ? getComputedStyle(panel) : null;
                const panelVisible = Boolean(panelCs && panelCs.display !== 'none' && panelCs.visibility !== 'hidden');
                const pr = panelVisible && panel ? panel.getBoundingClientRect() : null;
                const lr = firstLink && panelVisible ? firstLink.getBoundingClientRect() : null;
                const open = wrap && window.Alpine ? Boolean(Alpine.$data(wrap).open) : false;
                return {
                    open,
                    aria: trigger ? trigger.getAttribute('aria-expanded') : null,
                    trigger: tr ? {
                        left: tr.left, right: tr.right, top: tr.top, bottom: tr.bottom,
                        cx: (tr.left + tr.right) / 2, cy: (tr.top + tr.bottom) / 2,
                        width: tr.width, height: tr.height,
                    } : null,
                    panel: pr ? {
                        left: pr.left, right: pr.right, top: pr.top, bottom: pr.bottom,
                        width: pr.width, height: pr.height,
                        position: panelCs ? panelCs.position : null,
                    } : null,
                    firstLink: lr && firstLink ? {
                        left: lr.left, right: lr.right, top: lr.top, bottom: lr.bottom,
                        cx: (lr.left + lr.right) / 2, cy: (lr.top + lr.bottom) / 2,
                        href: firstLink.getAttribute('href'),
                        text: (firstLink.textContent || '').trim(),
                    } : null,
                    innerWidth: window.innerWidth,
                    docScrollWidth: document.documentElement.scrollWidth,
                    gap: (tr && pr) ? (pr.top - tr.bottom) : null,
                };
            };
            const tick = () => {
                const s = read();
                if (s.open && s.panel && s.firstLink) {
                    resolve(s);
                    return;
                }
                if (performance.now() - start > 4000) {
                    resolve(s);
                    return;
                }
                requestAnimationFrame(tick);
            };
            tick();
        })
    JS);

    return $state;
}

function shopMegaOpenViaHover(mixed $page): array
{
    $page->hover('[data-shop-nav-style="mega"]:not([data-shop-nav-mobile]) [x-ref="shopTrigger"]');

    $state = shopMegaWaitOpen($page);
    expect($state['open'] ?? false)->toBeTrue()
        ->and($state['panel'] ?? null)->not->toBeNull()
        ->and($state['trigger'] ?? null)->not->toBeNull()
        ->and($state['firstLink'] ?? null)->not->toBeNull();

    return $state;
}

function shopMegaAssertViewportFit(array $state): void
{
    $panel = $state['panel'];
    expect(abs((float) $panel['left']))->toBeLessThanOrEqual(0.5)
        ->and(abs((float) $panel['right'] - (float) ($state['innerWidth'] ?? 0)))->toBeLessThanOrEqual(0.5)
        ->and(abs((float) $panel['width'] - (float) ($state['innerWidth'] ?? 0)))->toBeLessThanOrEqual(0.5)
        ->and($state['docScrollWidth'])->toEqual($state['innerWidth']);
}

it('compacts nav container padding from 20px to 12px only when header shrink is enabled', function (string $layout, array $siteAttrs, string $expectedAfterScroll) {
    shopMegaMenuBrowserSite($layout, [
        'nav_container_style' => 'pill',
        'nav_container_fill' => 'surface',
        ...$siteAttrs,
    ]);
    $page = visitShopMegaMenu();
    $page->wait(0.3);

    /** @var array<string, string|float> $before */
    $before = $page->script(<<<'JS'
        (() => {
            const container = document.querySelector('[data-nav-container]');
            const style = getComputedStyle(container);

            return { paddingLeft: style.paddingLeft, scrollY: window.scrollY };
        })()
    JS);

    $page->script('window.scrollTo(0, 800)');
    $page->wait(0.5);

    /** @var array<string, string|float> $after */
    $after = $page->script(<<<'JS'
        (() => {
            const container = document.querySelector('[data-nav-container]');
            const style = getComputedStyle(container);

            return { paddingLeft: style.paddingLeft, scrollY: window.scrollY };
        })()
    JS);

    expect($before['paddingLeft'])->toBe('20px')
        ->and($before['scrollY'])->toBe(0)
        ->and($after['paddingLeft'])->toBe($expectedAfterScroll)
        ->and($after['scrollY'])->toBeGreaterThan(10);

    $page->assertNoJavaScriptErrors();
})->with([
    'standard shrink on' => ['standard', ['header_shrink' => 'on'], '12px'],
    'standard shrink off' => ['standard', ['header_shrink' => 'off'], '20px'],
    'centred sticky shrink on' => ['centred', [], '12px'],
]);

it('keeps glass and pattern ink AA-safe against their composited computed backgrounds with solid fallbacks', function (string $fill) {
    shopMegaMenuBrowserSite('standard', [
        'nav_container_style' => 'pill',
        'nav_container_fill' => $fill,
    ]);
    $page = visitShopMegaMenu();
    $page->wait(0.3);

    /** @var array<string, float|bool|string> $contrast */
    $contrast = $page->script(<<<'JS'
        (() => {
            const parse = (value) => {
                if (value.startsWith('#')) {
                    const hex = value.slice(1);

                    return {
                        r: parseInt(hex.slice(0, 2), 16),
                        g: parseInt(hex.slice(2, 4), 16),
                        b: parseInt(hex.slice(4, 6), 16),
                        a: 1,
                    };
                }
                const numbers = (value.match(/[\d.]+/g) || []).map(Number);
                const srgb = value.startsWith('color(srgb');

                return {
                    r: srgb ? numbers[0] * 255 : numbers[0],
                    g: srgb ? numbers[1] * 255 : numbers[1],
                    b: srgb ? numbers[2] * 255 : numbers[2],
                    a: numbers.length > 3 ? numbers[3] : 1,
                };
            };
            const composite = (front, back, opacity = front.a) => ({
                r: front.r * opacity + back.r * (1 - opacity),
                g: front.g * opacity + back.g * (1 - opacity),
                b: front.b * opacity + back.b * (1 - opacity),
                a: 1,
            });
            const luminance = (colour) => {
                const channel = (value) => {
                    const normal = value / 255;

                    return normal <= 0.04045 ? normal / 12.92 : Math.pow((normal + 0.055) / 1.055, 2.4);
                };

                return 0.2126 * channel(colour.r) + 0.7152 * channel(colour.g) + 0.0722 * channel(colour.b);
            };
            const ratio = (foreground, background) => {
                const lighter = Math.max(luminance(foreground), luminance(background));
                const darker = Math.min(luminance(foreground), luminance(background));

                return (lighter + 0.05) / (darker + 0.05);
            };
            const flattenRules = (rules) => Array.from(rules || []).flatMap((rule) => rule.cssRules ? [rule, ...flattenRules(rule.cssRules)] : [rule]);
            const container = document.querySelector('[data-nav-container]');
            const link = container.querySelector(':scope > a');
            const style = getComputedStyle(container);
            const ink = parse(getComputedStyle(link).color);
            const surface = parse(style.getPropertyValue('--color-surface').trim());
            const primary = parse(style.getPropertyValue('--brand-primary').trim());
            const fill = container.dataset.navContainerFill;
            const background = fill === 'glass'
                ? composite(parse(getComputedStyle(container, '::before').backgroundColor), parse(getComputedStyle(container.closest('header')).backgroundColor))
                : composite(surface, primary, 0.78);
            const rules = Array.from(document.styleSheets).flatMap((sheet) => {
                try {
                    return flattenRules(sheet.cssRules);
                } catch (error) {
                    return [];
                }
            });
            const supportsFallback = fill !== 'glass' || rules.some((rule) => rule.constructor.name === 'CSSSupportsRule'
                && rule.conditionText.includes('not')
                && rule.cssText.includes('data-nav-container-fill="glass"')
                && rule.cssText.includes('var(--color-surface)'));
            const reducedFallback = rules.some((rule) => rule.constructor.name === 'CSSMediaRule'
                && rule.conditionText.includes('prefers-reduced-transparency')
                && rule.cssText.includes(`data-nav-container-fill="${fill}"`)
                && rule.cssText.includes('var(--color-surface)'));

            return {
                ratio: ratio(ink, background),
                ink: getComputedStyle(link).color,
                background: `${background.r},${background.g},${background.b}`,
                supportsFallback,
                reducedFallback,
            };
        })()
    JS);

    expect($contrast['ratio'])->toBeGreaterThanOrEqual(4.5)
        ->and($contrast['supportsFallback'])->toBeTrue()
        ->and($contrast['reducedFallback'])->toBeTrue();

    $page->assertNoJavaScriptErrors();
})->with([
    'glass' => ['glass'],
    'pattern' => ['pattern'],
]);

it('lets nav container links change their computed ink on hover', function () {
    shopMegaMenuBrowserSite('standard', [
        'nav_container_style' => 'pill',
        'nav_container_fill' => 'surface',
    ]);
    $page = visitShopMegaMenu();
    $page->wait(0.3);

    $selector = '[data-nav-container] > a';
    $before = $page->script("getComputedStyle(document.querySelector('{$selector}')).color");
    $page->hover($selector);
    $page->wait(0.3);
    $after = $page->script("getComputedStyle(document.querySelector('{$selector}')).color");

    expect($after)->not->toBe($before);
    $page->assertNoJavaScriptErrors();
});

it('keeps the mega panel in the viewport and open through a straight-down pointer walk', function (string $layout, array $siteAttrs = []) {
    shopMegaMenuBrowserSite($layout, $siteAttrs);
    $page = visitShopMegaMenu();

    $open = shopMegaOpenViaHover($page);
    shopMegaAssertViewportFit($open);

    $fromX = (float) $open['trigger']['cx'];
    $fromY = (float) $open['trigger']['cy'];
    $toX = (float) $open['firstLink']['cx'];
    $toY = (float) $open['firstLink']['cy'];

    shopMegaPointerWalk($page, $fromX, $fromY, $fromX, $toY, 4, 8);
    if (abs($toX - $fromX) > 1) {
        shopMegaPointerWalk($page, $fromX, $toY, $toX, $toY, 4, 8);
    }

    /** @var array<string, mixed> $arrived */
    $arrived = $page->script(shopMegaMeasureJs());
    expect($arrived['open'] ?? false)->toBeTrue()
        ->and($arrived['hitIsFirstLink'] ?? false)->toBeTrue()
        ->and($arrived['firstLink']['text'] ?? '')->toContain('Celebration Cakes');

    $page->script(<<<'JS'
        (() => {
            const trigger = document.querySelector('[data-shop-nav-style="mega"]:not([data-shop-nav-mobile]) [x-ref="shopTrigger"]');
            if (trigger) {
                trigger.focus();
            }
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', code: 'Escape', bubbles: true, cancelable: true }));
        })()
    JS);
    $page->wait(0.15);

    /** @var array<string, mixed> $closed */
    $closed = $page->script(shopMegaMeasureJs());
    expect($closed['open'] ?? true)->toBeFalse()
        ->and($closed['activeIsTrigger'] ?? false)->toBeTrue();

    $page->assertNoJavaScriptErrors();
})->with([
    'standard header' => ['standard'],
    'centred header' => ['centred'],
    'standard header with pill nav container' => ['standard', ['nav_container_style' => 'pill', 'nav_container_fill' => 'brand']],
    'centred header with band nav container' => ['centred', ['nav_container_style' => 'band', 'nav_container_fill' => 'glass']],
    'standard header with pill glass nav container' => ['standard', ['nav_container_style' => 'pill', 'nav_container_fill' => 'glass']],
    'standard header with plate glass nav container' => ['standard', ['nav_container_style' => 'plate', 'nav_container_fill' => 'glass']],
    'standard header, overlay_glass always (backdrop-filter containing block)' => ['standard', ['overlay_glass' => 'always']],
    'standard header, overlay_glass scrolled' => ['standard', ['overlay_glass' => 'scrolled']],
]);

it('does not block header controls while the mega menu is open', function () {
    shopMegaMenuBrowserSite('standard');
    $page = visitShopMegaMenu();

    $open = shopMegaOpenViaHover($page);
    shopMegaAssertViewportFit($open);

    /** @var array<string, mixed> $probe */
    $probe = $page->script(<<<'JS'
        (() => {
            const wrap = document.querySelector('[data-shop-nav-style="mega"]:not([data-shop-nav-mobile])');
            const bridge = wrap ? wrap.querySelector('[data-shop-mega-bridge]') : null;
            const b = bridge ? bridge.getBoundingClientRect() : null;
            const header = wrap ? wrap.closest('header') : null;
            const links = header ? Array.from(header.querySelectorAll('a[href]')).filter(a => !wrap.contains(a)) : [];
            const sample = links.map(a => { const r = a.getBoundingClientRect(); return { href: a.getAttribute('href'), cx: r.left + r.width / 2, cy: r.bottom - 2, h: r.height }; }).filter(l => l.h > 0);
            const inBand = sample.filter(l => b && l.cy >= b.top && l.cy <= b.bottom);
            const results = inBand.map(l => { const el = document.elementFromPoint(l.cx, l.cy); return { href: l.href, hit: el ? el.tagName : null, isBridge: !!(el && el.hasAttribute('data-shop-mega-bridge')) }; });
            return { bridge: b ? { top: b.top, bottom: b.bottom } : null, checked: results.length, stolen: results.filter(r => r.isBridge).length };
        })()
    JS);

    expect($probe['bridge'])->not->toBeNull()
        ->and($probe['stolen'])->toBe(0);

    $page->assertNoJavaScriptErrors();
});

it('stays open on a diagonal pointer aim at the far end of the review', function (string $layout) {
    shopMegaMenuBrowserSite($layout);
    $page = visitShopMegaMenu();

    $open = shopMegaOpenViaHover($page);
    shopMegaAssertViewportFit($open);

    $fromX = (float) $open['trigger']['cx'];
    $fromY = (float) $open['trigger']['cy'];
    $toX = ((float) $open['panel']['left']) + 60;
    $toY = ((float) $open['panel']['top']) + 40;

    foreach ([[4, 8], [8, 25], [12, 40]] as [$stepPx, $delayMs]) {
        shopMegaPointerWalk($page, $fromX, $fromY, $toX, $toY, $stepPx, $delayMs);
        $page->wait(0.2);
        /** @var array<string, mixed> $arrived */
        $arrived = $page->script(shopMegaMeasureJs());
        expect($arrived['open'] ?? false)->toBeTrue("open after diagonal {$stepPx}px/{$delayMs}ms walk");
        shopMegaMouseMove($page, $fromX, $fromY);
        $page->wait(0.05);
    }

    $page->assertNoJavaScriptErrors();
})->with([
    'standard header' => ['standard'],
    'centred header' => ['centred'],
]);

it('stays open across the re-gate dead-band pointer walks on the standard header', function () {
    shopMegaMenuBrowserSite('standard');
    $page = visitShopMegaMenu();

    $speeds = [
        [2, 2],
        [4, 8],
        [8, 25],
    ];

    foreach ([false, true] as $scrolled) {
        if ($scrolled) {
            $page->script('window.scrollTo(0, 600);');
            $page->wait(0.35);
        }

        foreach ($speeds as [$stepPx, $delayMs]) {
            $open = shopMegaOpenViaHover($page);
            shopMegaAssertViewportFit($open);

            $fromX = (float) $open['trigger']['cx'];
            $fromY = (float) $open['trigger']['cy'];
            $toY = ((float) $open['panel']['top']) + 12;

            shopMegaPointerWalk($page, $fromX, $fromY, $fromX, $toY, $stepPx, $delayMs);

            /** @var array<string, mixed> $arrived */
            $arrived = $page->script(shopMegaMeasureJs());
            expect($arrived['open'] ?? false)->toBeTrue(
                "open stayed true after {$stepPx}px/{$delayMs}ms walk ".($scrolled ? 'scrolled' : 'top').
                ' gap='.($open['gap'] ?? '?')
            );

            $page->script(<<<'JS'
                (() => {
                    const wrap = document.querySelector('[data-shop-nav-style="mega"]:not([data-shop-nav-mobile])');
                    if (wrap && window.Alpine) {
                        Alpine.$data(wrap).open = false;
                    }
                })()
            JS);
            $page->wait(0.1);
        }
    }

    $page->assertNoJavaScriptErrors();
});
