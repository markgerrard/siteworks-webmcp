<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\Browser\ServerManager;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const SHOP_BROWSER_HOST = 'shop-test.domain.com';

/**
 * @param  list<array{name: string, slug: string, stock?: int, variants?: int, price?: int}>  $catalogue
 * @return array{site: Site, host: string, products: array<string, array{product: Product, variants: list<ProductVariant>}>}
 */
function cartDrawerBrowserSite(string $shopMode = 'cart', array $catalogue = [], string $businessName = 'B A Berry'): array
{
    $host = SHOP_BROWSER_HOST;
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'business_name' => $businessName,
        'shop_mode' => $shopMode,
        'shop_currency' => 'GBP',
    ]);

    if ($catalogue === []) {
        $catalogue = [
            ['name' => 'Victoria Sponge', 'slug' => 'victoria-sponge', 'stock' => 10, 'price' => 1800],
            ['name' => 'Lemon Drizzle', 'slug' => 'lemon-drizzle', 'stock' => 8, 'price' => 1600],
        ];
    }

    $products = [];
    $snapshotProducts = [];
    $featured = [];
    foreach ($catalogue as $row) {
        $product = Product::factory()->published()->for($site)->create([
            'name' => $row['name'],
            'slug' => $row['slug'],
        ]);
        $variantCount = $row['variants'] ?? 1;
        $price = $row['price'] ?? 1800;
        $stock = $row['stock'] ?? 10;
        $variants = [];
        $variantPayload = [];
        $variantInStock = [];
        for ($i = 0; $i < $variantCount; $i++) {
            $variant = ProductVariant::factory()->for($product)->create([
                'label' => $variantCount === 1 ? 'Std' : 'Size '.($i + 1),
                'price_cents' => $price + ($i * 100),
                'sku' => strtoupper($row['slug']).'-'.$i,
            ]);
            VariantStock::create(['variant_id' => $variant->id, 'on_hand' => $stock]);
            $variants[] = $variant;
            $variantInStock[$variant->id] = $stock > 0;
            $variantPayload[] = [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'label' => $variant->label,
                'price_cents' => $variant->price_cents,
                'image_urls' => null,
            ];
        }

        $priceDisplay = '£'.number_format($price / 100, 2);
        $snapshotProducts[$row['slug']] = [
            'id' => $product->id,
            'slug' => $row['slug'],
            'status' => 'published',
            'primary_category_slug' => 'cakes',
            'price_cents' => $price,
            'price_display' => $priceDisplay,
            'in_stock_any' => $stock > 0,
            'variant_in_stock' => $variantInStock,
            'image_urls' => ['thumb' => '/a.jpg', 'card' => '/a.jpg', 'full' => '/a.jpg'],
            'product_card' => ['slug' => $row['slug'], 'name' => $row['name'], 'price_display' => $priceDisplay],
            'product_detail' => ['slug' => $row['slug'], 'name' => $row['name'], 'description' => 'A cake'],
            'variants' => $variantPayload,
            'is_ai_seeded' => false,
            'is_ai_reviewed' => false,
        ];
        $featured[] = $row['slug'];
        $products[$row['slug']] = ['product' => $product->fresh(['variants']), 'variants' => $variants];
    }

    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'product_count' => count($snapshotProducts),
        'json' => [
            'meta' => ['site_id' => $site->id, 'product_count' => count($snapshotProducts)],
            'categories' => [
                'cakes' => ['id' => 1, 'slug' => 'cakes', 'name' => 'Cakes', 'product_slugs' => $featured],
            ],
            'products' => $snapshotProducts,
            'featured_slugs' => $featured,
        ],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create([
        'site_id' => $site->id,
        'snapshot_id' => $snap->id,
        'updated_at' => now(),
    ]);

    return ['site' => $site, 'host' => $host, 'products' => $products];
}

function cartDrawerBrowserUrl(string $path = '/shop'): string
{
    $serverBase = ServerManager::instance()->http()->rewrite('/');
    $port = parse_url($serverBase, PHP_URL_PORT);
    $origin = 'http://'.SHOP_BROWSER_HOST.($port ? ':'.$port : '');
    // pest-plugin-browser force-origins url() at 127.0.0.1; shop JSON
    // endpoints must stay on the custom domain or ShopDomainResolver 404s.
    app('url')->useOrigin($origin);
    config(['app.url' => $origin]);

    return $origin.$path;
}

function visitCartDrawerShop(string $path = '/shop')
{
    $page = visit(cartDrawerBrowserUrl($path))->withHost(SHOP_BROWSER_HOST);
    $page->resize(1440, 900);
    $ready = $page->script(<<<'JS'
        new Promise((resolve) => {
            const start = performance.now();
            const tick = () => {
                const card = document.querySelector('.shop-product-card');
                if (window.Alpine && card) {
                    resolve({ ok: true });
                    return;
                }
                if (performance.now() - start > 4000) {
                    resolve({
                        ok: false,
                        alpine: Boolean(window.Alpine),
                        card: Boolean(card),
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

function cartDrawerBrowserState(mixed $page): array
{
    /** @var array<string, mixed> $state */
    $state = $page->script(<<<'JS'
        (() => {
            const root = document.getElementById('shop-cart-drawer-root');
            if (! root || ! window.Alpine) {
                return { missing: true, open: false, items: [], count: 0, error: '' };
            }
            const d = Alpine.$data(root);
            const aside = document.getElementById('shop-cart-drawer');
            const cs = aside ? getComputedStyle(aside) : null;
            const alert = aside ? aside.querySelector('[role="alert"]') : null;
            const counts = Array.from(document.querySelectorAll('[data-shop-cart-count]')).map((el) => (el.textContent || '').trim());
            const inertSiblings = Array.from(document.body.children)
                .filter((el) => el !== root && ! el.contains(root))
                .map((el) => ({
                    tag: el.tagName.toLowerCase(),
                    inert: el.hasAttribute('inert'),
                    marked: el.hasAttribute('data-shop-drawer-inert'),
                }));
            const active = document.activeElement;

            return {
                missing: false,
                open: Boolean(d.open),
                count: d.count,
                items: (d.items || []).map((item) => ({ name: item.name, qty: item.qty, id: item.id })),
                error: d.error || '',
                display: cs ? cs.display : null,
                alertText: alert && getComputedStyle(alert).display !== 'none' ? (alert.textContent || '').trim() : '',
                emptyVisible: Boolean(d.items && d.items.length === 0 && aside && (aside.textContent || '').includes('Your cart is empty') && cs && cs.display !== 'none'),
                headerCounts: counts,
                inertSiblings,
                activeTag: active ? active.tagName.toLowerCase() : null,
                activeId: active && active.id ? active.id : null,
                activeLabel: active ? ((active.getAttribute('aria-label') || active.textContent || '').trim()) : null,
                pathname: window.location.pathname,
            };
        })()
    JS);

    return $state;
}

function hoverPillSnapshot(mixed $page, string $productName): array
{
    $page->hover('img[alt="'.$productName.'"] >> nth=0');
    $nameJson = json_encode($productName);

    /** @var array<string, mixed> $snap */
    $snap = $page->script(<<<JS
        (() => {
            const name = {$nameJson};
            const card = Array.from(document.querySelectorAll('.shop-product-card')).find((el) => {
                const named = el.querySelector('[data-product-name]');
                return named && named.getAttribute('data-product-name') === name;
            }) || document.querySelector('.shop-product-card');
            const slot = card ? card.querySelector('.shop-product-card__pill-slot') : null;
            const btn = card ? card.querySelector('.shop-product-card__pill-btn') : null;
            return {
                card: Boolean(card),
                slotOpacity: slot ? getComputedStyle(slot).opacity : null,
                btnPointer: btn ? getComputedStyle(btn).pointerEvents : null,
                btnText: btn ? (btn.textContent || '').trim() : null,
            };
        })()
    JS);

    return $snap;
}

function addProductViaHoverPill(mixed $page, string $productName): array
{
    $pill = hoverPillSnapshot($page, $productName);
    expect($pill['card'] ?? false)->toBeTrue()
        ->and($pill['slotOpacity'] ?? '0')->toBe('1')
        ->and($pill['btnPointer'] ?? 'none')->toBe('auto')
        ->and($pill['btnText'] ?? '')->toBe('Add to cart');

    $nameJson = json_encode($productName);
    $clicked = $page->script(<<<JS
        (() => {
            const orig = window.fetch.bind(window);
            window.fetch = async (...args) => {
                const res = await orig(...args);
                let body = null;
                try { body = await res.clone().text(); } catch (e) { body = String(e); }
                window.__lastCartFetch = { url: String(args[0]), status: res.status, body: body && body.slice(0, 500) };
                return res;
            };
            const name = {$nameJson};
            const card = Array.from(document.querySelectorAll('.shop-product-card')).find((el) => {
                const named = el.querySelector('[data-product-name]');
                return named && named.getAttribute('data-product-name') === name;
            });
            const btn = card && card.querySelector('.shop-product-card__pill-btn');
            if (! btn) {
                return { clicked: false };
            }
            btn.click();
            return { clicked: true };
        })()
    JS);
    expect($clicked['clicked'] ?? false)->toBeTrue();

    $page->wait(0.4);
    $state = cartDrawerBrowserState($page);
    if (! ($state['open'] ?? false)) {
        $page->wait(0.4);
        $state = cartDrawerBrowserState($page);
    }

    $fetch = $page->script('window.__lastCartFetch || null');
    expect($fetch['body'] ?? '')->toContain('"items"')
        ->and($fetch['status'] ?? null)->toBe(200)
        ->and($state['open'] ?? false)->toBeTrue();

    return $state;
}

it('hovers a product card, reveals the add-to-cart pill, and clicking it opens the drawer with that item at qty 1', function () {
    cartDrawerBrowserSite();
    $page = visitCartDrawerShop();

    $state = addProductViaHoverPill($page, 'Victoria Sponge');

    expect($state['items'])->toHaveCount(1)
        ->and($state['items'][0]['name'])->toBe('Victoria Sponge')
        ->and($state['items'][0]['qty'])->toBe(1)
        ->and($state['count'])->toBe(1)
        ->and($state['activeLabel'] ?? '')->toBe('Close cart');

    $page->assertNoJavaScriptErrors();
});

it('increments quantity to 2, decrements to 1, then remove shows the empty state and updates the header cart count', function () {
    cartDrawerBrowserSite();
    $page = visitCartDrawerShop();
    addProductViaHoverPill($page, 'Victoria Sponge');

    $inc = $page->script(<<<'JS'
        (() => {
            const btn = document.querySelector('[aria-label="Increase quantity of Victoria Sponge"]');
            if (! btn) {
                return { clicked: false };
            }
            btn.click();
            return { clicked: true };
        })()
    JS);
    expect($inc['clicked'] ?? false)->toBeTrue();
    $page->wait(0.4);
    $atTwo = cartDrawerBrowserState($page);
    if ((int) ($atTwo['items'][0]['qty'] ?? 0) !== 2) {
        $page->wait(0.4);
        $atTwo = cartDrawerBrowserState($page);
    }
    expect($atTwo['items'][0]['qty'] ?? null)->toBe(2)
        ->and($atTwo['count'])->toBe(2)
        ->and($atTwo['headerCounts'] ?? [])->toContain('2');

    $dec = $page->script(<<<'JS'
        (() => {
            const btn = document.querySelector('[aria-label="Decrease quantity of Victoria Sponge"]');
            if (! btn) {
                return { clicked: false };
            }
            btn.click();
            return { clicked: true };
        })()
    JS);
    expect($dec['clicked'] ?? false)->toBeTrue();
    $page->wait(0.4);
    $atOne = cartDrawerBrowserState($page);
    if ((int) ($atOne['items'][0]['qty'] ?? 0) !== 1) {
        $page->wait(0.4);
        $atOne = cartDrawerBrowserState($page);
    }
    expect($atOne['items'][0]['qty'] ?? null)->toBe(1)
        ->and($atOne['count'])->toBe(1);

    $removed = $page->script(<<<'JS'
        (() => {
            const btn = Array.from(document.querySelectorAll('#shop-cart-drawer button')).find((el) => (el.textContent || '').trim() === 'Remove');
            if (! btn) {
                return { clicked: false };
            }
            btn.click();
            return { clicked: true };
        })()
    JS);
    expect($removed['clicked'] ?? false)->toBeTrue();
    $page->wait(0.4);
    $empty = cartDrawerBrowserState($page);
    if (! ($empty['emptyVisible'] ?? false)) {
        $page->wait(0.4);
        $empty = cartDrawerBrowserState($page);
    }
    expect($empty['emptyVisible'] ?? false)->toBeTrue()
        ->and($empty['items'])->toBe([])
        ->and($empty['count'])->toBe(0)
        ->and($empty['headerCounts'] ?? [])->toContain('0');

    $page->assertNoJavaScriptErrors();
});

it('closes the drawer on Escape with focus restored to the opener, and a backdrop click closes likewise', function () {
    cartDrawerBrowserSite();
    $page = visitCartDrawerShop();
    addProductViaHoverPill($page, 'Victoria Sponge');

    $afterEsc = $page->script(<<<'JS'
        new Promise((resolve) => {
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', code: 'Escape', bubbles: true, cancelable: true }));
            const start = performance.now();
            const tick = () => {
                const root = document.getElementById('shop-cart-drawer-root');
                const d = root && window.Alpine ? Alpine.$data(root) : null;
                const aside = document.getElementById('shop-cart-drawer');
                const display = aside ? getComputedStyle(aside).display : null;
                const active = document.activeElement;
                if (d && ! d.open && display === 'none') {
                    resolve({
                        open: false,
                        display,
                        activeTag: active ? active.tagName.toLowerCase() : null,
                        activeText: active ? (active.textContent || '').trim() : null,
                        activeLabel: active ? (active.getAttribute('aria-label') || '') : '',
                    });
                    return;
                }
                if (performance.now() - start > 4000) {
                    resolve({
                        open: d ? Boolean(d.open) : null,
                        display,
                        activeTag: active ? active.tagName.toLowerCase() : null,
                        timeout: true,
                    });
                    return;
                }
                requestAnimationFrame(tick);
            };
            tick();
        })
    JS);

    expect($afterEsc['open'] ?? true)->toBeFalse()
        ->and($afterEsc['display'] ?? null)->toBe('none')
        ->and($afterEsc['activeText'] ?? '')->toBe('Add to cart');

    $afterHeader = $page->script(<<<'JS'
        new Promise((resolve) => {
            const control = Array.from(document.querySelectorAll('[data-shop-cart-control]'))
                .find((el) => el.offsetParent !== null);
            if (! control) {
                resolve({ opened: false, controlFound: false });
                return;
            }
            control.click();
            const start = performance.now();
            const tick = () => {
                const root = document.getElementById('shop-cart-drawer-root');
                const d = root && window.Alpine ? Alpine.$data(root) : null;
                const aside = document.getElementById('shop-cart-drawer');
                const display = aside ? getComputedStyle(aside).display : null;
                if (d && d.open && display !== 'none') {
                    resolve({ opened: true, controlFound: true });
                    return;
                }
                if (performance.now() - start > 4000) {
                    resolve({ opened: false, controlFound: true, timeout: true });
                    return;
                }
                requestAnimationFrame(tick);
            };
            tick();
        })
    JS);
    expect($afterHeader['opened'] ?? false)->toBeTrue();

    $clickedBackdrop = $page->script(<<<'JS'
        (() => {
            const backdrop = document.querySelector('#shop-cart-drawer-root > [aria-hidden="true"]');
            if (! backdrop) {
                return { backdropFound: false };
            }
            backdrop.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
            return { backdropFound: true };
        })()
    JS);
    expect($clickedBackdrop['backdropFound'] ?? false)->toBeTrue();
    $page->wait(0.4);
    $afterBackdrop = cartDrawerBrowserState($page);
    $focus = $page->script(<<<'JS'
        (() => {
            const active = document.activeElement;
            return {
                open: Boolean(window.Alpine && Alpine.$data(document.getElementById('shop-cart-drawer-root'))?.open),
                display: document.getElementById('shop-cart-drawer') ? getComputedStyle(document.getElementById('shop-cart-drawer')).display : null,
                activeIsCartControl: Boolean(active && active.closest && active.closest('[data-shop-cart-control]')),
            };
        })()
    JS);

    expect($afterBackdrop['open'] ?? true)->toBeFalse()
        ->and($focus['display'] ?? null)->toBe('none')
        ->and($focus['activeIsCartControl'] ?? false)->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

it('marks background siblings inert while the drawer is open and Tab from the last visible focusable wraps to the first without landing on body', function () {
    cartDrawerBrowserSite();
    $page = visitCartDrawerShop();
    $open = addProductViaHoverPill($page, 'Victoria Sponge');

    expect($open['inertSiblings'] ?? [])->not->toBeEmpty();
    foreach ($open['inertSiblings'] as $sibling) {
        expect($sibling['inert'])->toBeTrue()
            ->and($sibling['marked'])->toBeTrue();
    }

    $trap = $page->script(<<<'JS'
        new Promise(async (resolve) => {
            const drawer = document.getElementById('shop-cart-drawer');
            if (! drawer) {
                resolve({ ok: false, reason: 'missing-drawer' });
                return;
            }
            const isVisible = (el) => el && el.offsetParent !== null && el.getClientRects().length > 0;
            const all = Array.from(drawer.querySelectorAll('a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])'));
            const visible = all.filter(isVisible);
            const hidden = all.filter((el) => ! isVisible(el));
            if (visible.length === 0) {
                resolve({ ok: false, reason: 'no-visible-focusable', hiddenCount: hidden.length });
                return;
            }
            const first = visible[0];
            const last = visible[lastIndex(visible)];
            function lastIndex(list) { return list.length - 1; }

            last.focus();
            await new Promise((r) => requestAnimationFrame(r));
            const beforeWrapTag = document.activeElement ? document.activeElement.tagName.toLowerCase() : null;
            const tab = new KeyboardEvent('keydown', { key: 'Tab', code: 'Tab', bubbles: true, cancelable: true, shiftKey: false });
            drawer.dispatchEvent(tab);
            await new Promise((r) => requestAnimationFrame(r));
            const afterWrap = document.activeElement;
            const afterWrapIsBody = afterWrap === document.body;
            const afterWrapIsFirst = afterWrap === first;
            const afterWrapVisible = isVisible(afterWrap);

            first.focus();
            await new Promise((r) => requestAnimationFrame(r));
            const shift = new KeyboardEvent('keydown', { key: 'Tab', code: 'Tab', bubbles: true, cancelable: true, shiftKey: true });
            drawer.dispatchEvent(shift);
            await new Promise((r) => requestAnimationFrame(r));
            const afterShift = document.activeElement;
            const afterShiftIsBody = afterShift === document.body;
            const afterShiftIsLast = afterShift === last;
            const afterShiftVisible = isVisible(afterShift);

            resolve({
                ok: true,
                hiddenCount: hidden.length,
                visibleCount: visible.length,
                firstLabel: (first.getAttribute('aria-label') || first.textContent || '').trim(),
                lastText: (last.textContent || '').trim(),
                beforeWrapTag,
                afterWrapIsBody,
                afterWrapIsFirst,
                afterWrapVisible,
                afterWrapTag: afterWrap ? afterWrap.tagName.toLowerCase() : null,
                afterShiftIsBody,
                afterShiftIsLast,
                afterShiftVisible,
                afterShiftTag: afterShift ? afterShift.tagName.toLowerCase() : null,
            });
        })
    JS);

    expect($trap['ok'] ?? false)->toBeTrue()
        ->and($trap['hiddenCount'] ?? 0)->toBeGreaterThan(0)
        ->and($trap['afterWrapIsBody'] ?? true)->toBeFalse()
        ->and($trap['afterWrapIsFirst'] ?? false)->toBeTrue()
        ->and($trap['afterWrapVisible'] ?? false)->toBeTrue()
        ->and($trap['afterShiftIsBody'] ?? true)->toBeFalse()
        ->and($trap['afterShiftIsLast'] ?? false)->toBeTrue()
        ->and($trap['afterShiftVisible'] ?? false)->toBeTrue()
        ->and($trap['afterWrapTag'] ?? 'body')->not->toBe('body')
        ->and($trap['firstLabel'] ?? '')->toBe('Close cart');

    $page->assertNoJavaScriptErrors();
});

it('clicking the product image while the hover pill is shown still navigates to the product page', function () {
    cartDrawerBrowserSite();
    $page = visitCartDrawerShop();

    $pill = hoverPillSnapshot($page, 'Victoria Sponge');
    expect($pill['slotOpacity'] ?? '0')->toBe('1');

    $hit = $page->script(<<<'JS'
        (() => {
            const img = document.querySelector('.shop-product-card:has([data-product-name="Victoria Sponge"]) .shop-product-card__img');
            if (! img) {
                return { ok: false, reason: 'missing-img' };
            }
            const rect = img.getBoundingClientRect();
            const x = rect.left + (rect.width / 2);
            const y = rect.top + (rect.height / 4);
            const el = document.elementFromPoint(x, y);
            const anchor = img.closest('a');
            return {
                hitIsPill: Boolean(el && el.closest && el.closest('.shop-product-card__pill-btn')),
                hitIsImageOrAnchor: Boolean(el && (el === img || (el.closest && el.closest('a[href*="/products/victoria-sponge"]')))),
                hitTag: el ? el.tagName.toLowerCase() : null,
                href: anchor ? anchor.getAttribute('href') : null,
            };
        })()
    JS);
    expect($hit['hitIsPill'] ?? true)->toBeFalse()
        ->and($hit['hitIsImageOrAnchor'] ?? false)->toBeTrue();

    $page->script(<<<'JS'
        (() => {
            const img = document.querySelector('.shop-product-card:has([data-product-name="Victoria Sponge"]) .shop-product-card__img');
            const anchor = img && img.closest('a');
            if (anchor) {
                anchor.click();
            }
        })()
    JS);
    $page->wait(0.5);
    $pathname = $page->script('window.location.pathname');
    expect($pathname)->toContain('/products/victoria-sponge');

    $page->assertNoJavaScriptErrors();
});

it('a 422 from POST /shop/cart/add shows the error in the open drawer and keeps existing items intact', function () {
    cartDrawerBrowserSite();
    $page = visitCartDrawerShop();
    $open = addProductViaHoverPill($page, 'Victoria Sponge');
    expect($open['items'])->toHaveCount(1);

    $result = $page->script(<<<'JS'
        new Promise(async (resolve) => {
            const root = document.getElementById('shop-cart-drawer-root');
            if (! root || ! window.Alpine) {
                resolve({ ok: false, reason: 'missing-root' });
                return;
            }
            const d = Alpine.$data(root);
            const body = new URLSearchParams();
            body.set('_token', d.csrf);
            body.set('product_slug', 'victoria-sponge');
            body.set('variant_id', '999999');
            body.set('qty', '1');
            await d.postAdd(body, 'Ghost', d.lastTrigger);
            const start = performance.now();
            const tick = () => {
                const aside = document.getElementById('shop-cart-drawer');
                const alert = aside ? aside.querySelector('[role="alert"]') : null;
                const alertText = alert && getComputedStyle(alert).display !== 'none' ? (alert.textContent || '').trim() : '';
                if (d.error && alertText) {
                    resolve({
                        ok: true,
                        open: Boolean(d.open),
                        error: d.error,
                        alertText,
                        items: (d.items || []).map((item) => ({ name: item.name, qty: item.qty })),
                        count: d.count,
                    });
                    return;
                }
                if (performance.now() - start > 4000) {
                    resolve({
                        ok: false,
                        open: Boolean(d.open),
                        error: d.error,
                        alertText,
                        items: (d.items || []).map((item) => ({ name: item.name, qty: item.qty })),
                        timeout: true,
                    });
                    return;
                }
                requestAnimationFrame(tick);
            };
            tick();
        })
    JS);

    expect($result['ok'] ?? false)->toBeTrue()
        ->and($result['open'] ?? false)->toBeTrue()
        ->and($result['error'] ?? '')->toBe('That option is not available.')
        ->and($result['alertText'] ?? '')->toBe('That option is not available.')
        ->and($result['items'])->toHaveCount(1)
        ->and($result['items'][0]['name'])->toBe('Victoria Sponge')
        ->and($result['items'][0]['qty'])->toBe(1)
        ->and($result['count'])->toBe(1);

    $page->assertNoJavaScriptErrors();
});

it('a plain click on the header Cart link opens the drawer, and a Cmd-click is not preventDefaulted', function () {
    cartDrawerBrowserSite();
    $page = visitCartDrawerShop();

    $plain = $page->script(<<<'JS'
        new Promise((resolve) => {
            const control = Array.from(document.querySelectorAll('[data-shop-cart-control]'))
                .find((el) => el.offsetParent !== null);
            if (! control) {
                resolve({ opened: false, controlFound: false });
                return;
            }
            control.click();
            const start = performance.now();
            const tick = () => {
                const root = document.getElementById('shop-cart-drawer-root');
                const d = root && window.Alpine ? Alpine.$data(root) : null;
                const aside = document.getElementById('shop-cart-drawer');
                const display = aside ? getComputedStyle(aside).display : null;
                if (d && d.open && display !== 'none') {
                    resolve({ opened: true, controlFound: true });
                    return;
                }
                if (performance.now() - start > 4000) {
                    resolve({ opened: false, controlFound: true, timeout: true, open: d ? d.open : null });
                    return;
                }
                requestAnimationFrame(tick);
            };
            tick();
        })
    JS);
    expect($plain['opened'] ?? false)->toBeTrue();

    $closed = $page->script(<<<'JS'
        new Promise((resolve) => {
            const root = document.getElementById('shop-cart-drawer-root');
            if (root && window.Alpine) {
                Alpine.$data(root).close();
            }
            const start = performance.now();
            const tick = () => {
                const d = root && window.Alpine ? Alpine.$data(root) : null;
                const aside = document.getElementById('shop-cart-drawer');
                const display = aside ? getComputedStyle(aside).display : null;
                if (d && ! d.open && display === 'none') {
                    resolve({ open: false });
                    return;
                }
                if (performance.now() - start > 4000) {
                    resolve({ open: d ? Boolean(d.open) : true, timeout: true });
                    return;
                }
                requestAnimationFrame(tick);
            };
            tick();
        })
    JS);
    expect($closed['open'] ?? true)->toBeFalse();

    $meta = $page->script(<<<'JS'
        (() => {
            const root = document.getElementById('shop-cart-drawer-root');
            const d = root && window.Alpine ? Alpine.$data(root) : null;
            const control = Array.from(document.querySelectorAll('[data-shop-cart-control]'))
                .find((el) => el.offsetParent !== null);
            if (! control || ! d) {
                return { controlFound: false };
            }
            const event = new MouseEvent('click', {
                bubbles: true,
                cancelable: true,
                metaKey: true,
                button: 0,
                view: window,
            });
            control.dispatchEvent(event);
            return {
                controlFound: true,
                defaultPrevented: event.defaultPrevented,
                open: Boolean(d.open),
            };
        })()
    JS);

    expect($meta['controlFound'] ?? false)->toBeTrue()
        ->and($meta['defaultPrevented'] ?? true)->toBeFalse()
        ->and($meta['open'] ?? true)->toBeFalse();

    $page->assertNoJavaScriptErrors();
});

it('an enquire-mode pill reads Enquire, clicking it goes to the enquiry target, and no drawer is present', function () {
    cartDrawerBrowserSite('enquire', [
        ['name' => 'Strawberry Conserve', 'slug' => 'conserve', 'stock' => 4, 'price' => 8500],
    ], 'Camino Bakehouse');
    $page = visitCartDrawerShop();

    $markup = $page->script(<<<'JS'
        (() => {
            const btn = document.querySelector('.shop-product-card__pill-btn');
            const form = document.querySelector('form[action*="/shop/cart/add"]');
            return {
                btnText: btn ? (btn.textContent || '').trim() : null,
                btnHref: btn && btn.getAttribute ? btn.getAttribute('href') : null,
                hasDrawer: Boolean(document.getElementById('shop-cart-drawer')),
                hasAddForm: Boolean(form),
            };
        })()
    JS);
    expect($markup['btnText'] ?? '')->toBe('Enquire')
        ->and($markup['hasDrawer'] ?? true)->toBeFalse()
        ->and($markup['hasAddForm'] ?? true)->toBeFalse()
        ->and($markup['btnHref'] ?? '')->toContain('/products/conserve');

    $pill = hoverPillSnapshot($page, 'Strawberry Conserve');
    expect($pill['slotOpacity'] ?? '0')->toBe('1')
        ->and($pill['btnText'] ?? '')->toBe('Enquire');

    $page->script(<<<'JS'
        (() => {
            const btn = document.querySelector('.shop-product-card__pill-btn');
            if (btn) {
                btn.click();
            }
        })()
    JS);
    $page->wait(0.5);
    $after = $page->script(<<<'JS'
        (() => {
            const enquire = document.querySelector('a[href*="/enquire?product=conserve"]');
            return {
                path: window.location.pathname + window.location.search,
                hasDrawer: Boolean(document.getElementById('shop-cart-drawer')),
                enquireHref: enquire ? enquire.getAttribute('href') : null,
            };
        })()
    JS);

    expect($after['path'] ?? '')->toContain('/products/conserve')
        ->and($after['hasDrawer'] ?? true)->toBeFalse()
        ->and($after['enquireHref'] ?? '')->toBe('/enquire?product=conserve');

    $page->assertNoJavaScriptErrors();
});
