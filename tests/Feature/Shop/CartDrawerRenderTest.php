<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\Product;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $product
 */
function cartDrawerRenderSite(string $host, string $shopMode, array $product, string $currency = 'GBP'): Site
{
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'business_name' => 'Camino Bakehouse',
        'shop_mode' => $shopMode,
        'shop_currency' => $currency,
    ]);
    Product::factory()->published()->for($site)->create(['slug' => $product['slug'], 'name' => $product['product_card']['name']]);

    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'product_count' => 1,
        'json' => [
            'meta' => ['site_id' => $site->id, 'product_count' => 1],
            'categories' => [
                'cakes' => ['id' => 1, 'slug' => 'cakes', 'name' => 'Cakes', 'product_slugs' => [$product['slug']]],
            ],
            'products' => [$product['slug'] => $product],
            'featured_slugs' => [$product['slug']],
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
 * @param  list<array{id: int, label?: string}>  $variants
 * @return array<string, mixed>
 */
function cartDrawerCardProduct(string $slug, string $name, array $variants, bool $inStock = true): array
{
    return [
        'id' => 1,
        'slug' => $slug,
        'status' => 'published',
        'primary_category_slug' => 'cakes',
        'price_cents' => 4500,
        'price_display' => '£45.00',
        'in_stock_any' => $inStock,
        'variant_in_stock' => collect($variants)->mapWithKeys(fn ($v) => [$v['id'] => $inStock])->all(),
        'image_urls' => ['thumb' => '/a.jpg', 'card' => '/a.jpg', 'full' => '/a.jpg'],
        'product_card' => ['slug' => $slug, 'name' => $name, 'price_display' => '£45.00'],
        'product_detail' => ['slug' => $slug, 'name' => $name, 'description' => 'A cake'],
        'variants' => collect($variants)->map(fn ($v) => [
            'id' => $v['id'],
            'sku' => 'SKU-'.$v['id'],
            'label' => $v['label'] ?? 'Std',
            'price_cents' => 4500,
            'image_urls' => null,
        ])->all(),
        'is_ai_seeded' => false,
        'is_ai_reviewed' => false,
    ];
}

function cartDrawerShopHtml(string $host): string
{
    return test()->get('http://'.$host.'/shop')->assertOk()->getContent();
}

function cartDrawerMainAnchorInner(string $html, string $slug): string
{
    $pattern = '#<a\b[^>]*href="[^"]*/products/'.preg_quote($slug, '#').'"[^>]*>(.*?)</a>#s';
    preg_match($pattern, $html, $m);
    expect($m)->not->toBeEmpty();

    return $m[1];
}

test('a single-variant cart-mode card shows an Add to cart pill sibling, not nested in the anchor', function () {
    cartDrawerRenderSite('card-single.example', 'cart', cartDrawerCardProduct('rose', 'Red Rose', [['id' => 11]]));
    $html = cartDrawerShopHtml('card-single.example');
    $inner = cartDrawerMainAnchorInner($html, 'rose');

    expect($html)->toContain('Add to cart')
        ->and($html)->toContain('data-shop-card-pill')
        ->and($html)->toMatch('/<form\b[^>]*action="[^"]*\/shop\/cart\/add"/i')
        ->and($inner)->not->toContain('<form')
        ->and($inner)->not->toContain('<button')
        ->and($html)->toContain('shop-product-card__img')
        ->and($html)->toContain('scale(1.04)')
        ->and($html)->toContain('prefers-reduced-motion');
});

test('a multi-variant cart-mode card shows Choose options and does not nest it in the image anchor', function () {
    cartDrawerRenderSite('card-multi.example', 'cart', cartDrawerCardProduct('layer', 'Layer Cake', [
        ['id' => 21, 'label' => 'Small'],
        ['id' => 22, 'label' => 'Large'],
    ]));
    $html = cartDrawerShopHtml('card-multi.example');
    $inner = cartDrawerMainAnchorInner($html, 'layer');

    expect($html)->toContain('Choose options')
        ->and($html)->not->toContain('Add to cart')
        ->and($inner)->not->toContain('Choose options');
});

test('an enquire-mode card shows Enquire, no cart pill form, and still zooms', function () {
    cartDrawerRenderSite('card-enquire.example', 'enquire', cartDrawerCardProduct('conserve', 'Strawberry Conserve', [['id' => 31]]));
    $html = cartDrawerShopHtml('card-enquire.example');

    expect($html)->toContain('Enquire')
        ->and($html)->toContain('Strawberry Conserve')
        ->and($html)->not->toContain('Add to cart')
        ->and($html)->not->toContain('/shop/cart/add')
        ->and($html)->toContain('shop-product-card__img')
        ->and($html)->toContain('scale(1.04)');
});

test('an out-of-stock cart-mode card has no add pill', function () {
    cartDrawerRenderSite('card-oos.example', 'cart', cartDrawerCardProduct('gone', 'Sold Out', [['id' => 41]], inStock: false));
    $html = cartDrawerShopHtml('card-oos.example');

    expect($html)->toContain('Sold Out')
        ->and($html)->toContain('Out of stock')
        ->and($html)->not->toContain('Add to cart')
        ->and($html)->not->toContain('data-shop-card-pill');
});

test('the drawer footer drops taxes-included copy on non-GBP sites', function () {
    cartDrawerRenderSite('drawer-gbp-copy.example', 'cart', cartDrawerCardProduct('rose', 'Red Rose', [['id' => 101]]));
    $gbp = cartDrawerShopHtml('drawer-gbp-copy.example');
    expect($gbp)->toContain('Taxes included and shipping calculated at checkout.');

    cartDrawerRenderSite('drawer-usd-copy.example', 'cart', cartDrawerCardProduct('rose', 'Red Rose', [['id' => 102]]), 'USD');
    $usd = cartDrawerShopHtml('drawer-usd-copy.example');
    expect($usd)->toContain('Shipping calculated at checkout.')
        ->and($usd)->not->toContain('Taxes included');
});

test('the cart drawer markup is present only in cart mode', function () {
    cartDrawerRenderSite('drawer-cart.example', 'cart', cartDrawerCardProduct('rose', 'Red Rose', [['id' => 51]]));
    $cartHtml = cartDrawerShopHtml('drawer-cart.example');

    expect($cartHtml)->toContain('id="shop-cart-drawer"')
        ->and($cartHtml)->toMatch('/role="dialog"/')
        ->and($cartHtml)->toContain('aria-modal="true"')
        ->and($cartHtml)->toContain('aria-labelledby="shop-cart-drawer-title"')
        ->and($cartHtml)->toContain('aria-live="polite"')
        ->and($cartHtml)->toContain('function shopCartDrawer')
        ->and($cartHtml)->toContain('href="/shop/cart"')
        ->and($cartHtml)->toContain('data-shop-cart-control');

    cartDrawerRenderSite('drawer-enquire.example', 'enquire', cartDrawerCardProduct('rose', 'Red Rose', [['id' => 52]]));
    $enquireHtml = cartDrawerShopHtml('drawer-enquire.example');

    expect($enquireHtml)->not->toContain('id="shop-cart-drawer"')
        ->and($enquireHtml)->not->toContain('function shopCartDrawer')
        ->and($enquireHtml)->not->toContain('data-shop-cart-control');
});

test('the drawer restores focus to a visible control when the trigger is hidden', function () {
    cartDrawerRenderSite('drawer-focus.example', 'cart', cartDrawerCardProduct('rose', 'Red Rose', [['id' => 91]]));
    $html = cartDrawerShopHtml('drawer-focus.example');

    expect($html)->toContain('function chooseFocusRestoreTarget')
        ->and($html)->toContain('chooseFocusRestoreTarget(')
        ->and($html)->toContain("setAttribute('tabindex', '-1')")
        ->and($html)->toContain("removeAttribute('tabindex')")
        ->and($html)->toContain('button[aria-controls="mobile-nav-panel"]');
});

test('the drawer line item renders variant_label as text under the product name', function () {
    cartDrawerRenderSite('drawer-label.example', 'cart', cartDrawerCardProduct('rose', 'Red Rose', [['id' => 81]]));
    $html = cartDrawerShopHtml('drawer-label.example');
    $partial = file_get_contents(resource_path('views/shop/partials/cart-drawer.blade.php'));

    expect($html)->toContain('data-cart-variant-label')
        ->and($html)->toMatch('/x-text="item\.variant_label"/')
        ->and($html)->toMatch('/x-show="item\.variant_label"/')
        ->and($partial)->not->toContain('x-html');
});

test('the drawer keeps last-valid cart state when a JSON mutation fails', function () {
    cartDrawerRenderSite('drawer-fail.example', 'cart', cartDrawerCardProduct('rose', 'Red Rose', [['id' => 71]]));
    $html = cartDrawerShopHtml('drawer-fail.example');

    expect($html)->toContain('function applyMutationResult')
        ->and($html)->toContain('applyMutationResult(')
        ->and($html)->toMatch('/role="alert"/')
        ->and($html)->toContain("Couldn't update your cart")
        ->and($html)->toContain('res.ok')
        ->and($html)->toContain('stopImmediatePropagation');
});

test('Camino Bakehouse enquire-mode demo still renders its product cards', function () {
    cartDrawerRenderSite(
        'camino.example',
        'enquire',
        cartDrawerCardProduct('sourdough', 'Sourdough', [['id' => 61]]),
    );
    $html = cartDrawerShopHtml('camino.example');

    expect($html)->toContain('Camino Bakehouse')
        ->and($html)->toContain('Sourdough')
        ->and($html)->toContain('£45.00')
        ->and($html)->toContain('Enquire')
        ->and($html)->not->toContain('id="shop-cart-drawer"');
});

test('pill slot never takes pointer events itself and the drawer script marks the background inert', function () {
    $css = file_get_contents(resource_path('views/shop/partials/product-card-styles.blade.php'));
    // The overlay slot must stay pointer-events:none in every state; only the button opts in.
    expect(preg_match_all('/\.shop-product-card__pill-slot\s*\{[^}]*pointer-events:\s*auto/s', $css))->toBe(0)
        ->and($css)->toContain('.shop-product-card:hover .shop-product-card__pill-btn')
        ->and($css)->toContain('.shop-product-card:focus-within .shop-product-card__pill-btn');

    $drawer = file_get_contents(resource_path('views/shop/partials/cart-drawer.blade.php'));
    expect($drawer)->toContain("setAttribute('inert', '')")
        ->and($drawer)->toContain('setBackgroundInert(true)')
        ->and($drawer)->toContain('setBackgroundInert(false)');
});

test('header cart control leaves modifier clicks to the browser, the trap skips hidden nodes, and multi-variant upsells say Choose options', function () {
    $drawer = file_get_contents(resource_path('views/shop/partials/cart-drawer.blade.php'));
    expect($drawer)->toContain('event.metaKey || event.ctrlKey || event.shiftKey || event.altKey')
        ->and($drawer)->toContain('.filter((el) => isVisibleFocusTarget(el))')
        ->and($drawer)->toContain('>Choose options</a>')
        ->and($drawer)->not->toContain("apply(data) {");
});
