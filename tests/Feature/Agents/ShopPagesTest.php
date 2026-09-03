<?php

use App\Enums\AgentRole;
use App\Enums\Shop\OrderStatus;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * @return array{0: Site, 1: User}
 */
function shopPageSite(bool $admin = false, bool $shopEnabled = true): array
{
    $agent = User::factory()->staff($admin ? AgentRole::Admin : AgentRole::Agent)->create();
    $site = Site::factory()
        ->{$shopEnabled ? 'shopEnabled' : 'shopDisabled'}()
        ->create([
            'created_by_user_id' => $agent->id,
            'preview_domain' => 'shop-pages-'.uniqid(),
            'preview_brand' => 'a',
            'business_name' => 'Shop Pages Candles',
        ]);
    GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    Preview::factory()->for($site)->create(['slug' => 'shop-pages-'.uniqid()]);

    return [$site, $agent];
}

it('registers dedicated shop page routes without colliding with editor JSON names', function () {
    expect(Route::has('sites.shop.products'))->toBeTrue()
        ->and(Route::has('sites.shop.products.export'))->toBeTrue()
        ->and(Route::has('sites.shop.categories'))->toBeTrue()
        ->and(Route::has('sites.shop.orders'))->toBeTrue()
        ->and(Route::has('sites.shop.storefront'))->toBeTrue()
        ->and(Route::has('sites.shop.reviews'))->toBeTrue()
        ->and(Route::has('site.editor.list-products'))->toBeTrue()
        ->and(Route::has('shop.admin.products.edit'))->toBeTrue()
        ->and(Route::has('shop.admin.orders.show'))->toBeTrue();

    [$site] = shopPageSite();

    expect(route('sites.shop.products', $site))->toEndWith('/sites/'.$site->id.'/products')
        ->and(route('sites.shop.categories', $site))->toEndWith('/sites/'.$site->id.'/products/categories')
        ->and(route('sites.shop.orders', $site))->toEndWith('/sites/'.$site->id.'/orders')
        ->and(route('sites.shop.storefront', $site))->toEndWith('/sites/'.$site->id.'/storefront')
        ->and(route('sites.shop.reviews', $site))->toEndWith('/sites/'.$site->id.'/reviews')
        ->and(route('site.editor.list-products', $site))->toEndWith('/sites/'.$site->id.'/shop/products');
});

it('serves the products page to an authenticated agent and mounts products-list', function () {
    [$site, $agent] = shopPageSite();

    $this->actingAs($agent)
        ->get(route('sites.shop.products', $site))
        ->assertOk()
        ->assertSeeLivewire('shop.products-list')
        ->assertDontSeeLivewire('shop.category-manager')
        ->assertDontSeeLivewire('shop.shop-hero-picker');
});

it('serves the categories page and mounts category-manager', function () {
    [$site, $agent] = shopPageSite();

    $html = $this->actingAs($agent)
        ->get(route('sites.shop.categories', $site))
        ->assertOk()
        ->assertSeeLivewire('shop.category-manager')
        ->assertDontSeeLivewire('shop.products-list')
        ->getContent();

    expect($html)->toContain('Add category')
        ->and($html)->toContain('No categories yet — add one to start organising the shop.');
});

it('serves the storefront page and mounts the hero picker', function () {
    [$site, $agent] = shopPageSite();

    $this->actingAs($agent)
        ->get(route('sites.shop.storefront', $site))
        ->assertOk()
        ->assertSeeLivewire('shop.shop-hero-picker')
        ->assertSeeLivewire('shop.shipping-rate-editor')
        ->assertSeeLivewire('shop.listing-knobs')
        ->assertSeeLivewire('shop.tags-badges-settings')
        ->assertSeeLivewire('shop.shop-index-blocks-settings')
        ->assertSeeLivewire('shop.fulfilment-editor')
        ->assertDontSeeLivewire('shop.products-list');
});

it('renders the storefront page as tabs with both hero-picker sections mounted', function () {
    [$site, $agent] = shopPageSite();

    $html = $this->actingAs($agent)
        ->get(route('sites.shop.storefront', $site))
        ->assertOk()
        ->getContent();

    foreach (['Hero', 'Category hero', 'Shipping', 'Listing', 'Fulfilment', 'Tags &amp; badges', 'Shop index blocks'] as $label) {
        expect($html)->toContain('role="tab"')
            ->and($html)->toContain($label);
    }

    expect($html)->toContain('role="tabpanel"')
        ->and(substr_count($html, 'role="tab"'))->toBe(7)
        ->and(substr_count($html, 'role="tabpanel"'))->toBe(7)
        // Both shop-hero-picker cards are mounted (not conditionally rendered),
        // identified by their distinct card headings.
        ->and($html)->toContain('Shop Index Hero')
        ->and($html)->toContain('Category hero (shared)');
});

it('serves the orders page and mounts orders-list', function () {
    [$site, $agent] = shopPageSite();

    $this->actingAs($agent)
        ->get(route('sites.shop.orders', $site))
        ->assertOk()
        ->assertSeeLivewire('shop.orders-list');
});

it('shop pages share the sites page chrome', function () {
    [$site, $agent] = shopPageSite();

    $html = $this->actingAs($agent)
        ->get(route('sites.shop.products', $site))
        ->assertOk()
        ->getContent();

    expect($html)->toContain(__('Sites'))
        ->and($html)->toContain($site->business_name)
        ->and($html)->not->toContain('max-w-6xl'); // T39: index pages are full-width
});

it('redirects guests from shop pages to login', function () {
    [$site] = shopPageSite();

    $this->get(route('sites.shop.products', $site))->assertRedirect(route('agent.login'));
    $this->get(route('sites.shop.categories', $site))->assertRedirect(route('agent.login'));
    $this->get(route('sites.shop.storefront', $site))->assertRedirect(route('agent.login'));
    $this->get(route('sites.shop.orders', $site))->assertRedirect(route('agent.login'));
});

it('404s products, categories and storefront when the shop flag is off', function () {
    [$site, $agent] = shopPageSite(shopEnabled: false);

    $this->actingAs($agent)->get(route('sites.shop.products', $site))->assertNotFound();
    $this->actingAs($agent)->get(route('sites.shop.categories', $site))->assertNotFound();
    $this->actingAs($agent)->get(route('sites.shop.storefront', $site))->assertNotFound();
});

it('404s orders when the flag is off and the site has never taken an order', function () {
    [$site, $agent] = shopPageSite(shopEnabled: false);

    $this->actingAs($agent)->get(route('sites.shop.orders', $site))->assertNotFound();
});

it('still serves orders when the flag is off after the site has taken an order', function () {
    [$site, $agent] = shopPageSite(shopEnabled: false);
    Order::create([
        'site_id' => $site->id,
        'number' => 'SHOP-PAGE-1',
        'email' => 'buyer@example.com',
        'name' => 'Buyer',
        'status' => OrderStatus::Paid->value,
        'refund_status' => 'none',
        'subtotal_cents' => 900,
        'shipping_cents' => 0,
        'tax_cents' => 0,
        'shipping_tax_cents' => 0,
        'total_cents' => 900,
        'tax_country_code' => 'GB',
        'shipping_address_json' => [],
        'shipping_method_label' => 'Std',
        'placed_at' => now(),
    ]);

    $this->actingAs($agent)
        ->get(route('sites.shop.orders', $site))
        ->assertOk()
        ->assertSeeLivewire('shop.orders-list');
});

it('302s the legacy shop section URL to products', function () {
    [$site, $agent] = shopPageSite();

    $this->actingAs($agent)
        ->get('/sites/'.$site->id.'/shop')
        ->assertRedirect(route('sites.shop.products', $site));
});

it('forbids an unassigned agent from the legacy shop redirect', function () {
    [$site] = shopPageSite();
    $stranger = User::factory()->staff(AgentRole::Agent)->create();

    $this->actingAs($stranger)
        ->get('/sites/'.$site->id.'/shop')
        ->assertForbidden();

    $this->actingAs($stranger)
        ->get(route('sites.shop.products', $site))
        ->assertForbidden();
});

it('serves the dedicated orders page at the legacy /orders section URL', function () {
    [$site, $agent] = shopPageSite();

    $this->actingAs($agent)
        ->get('/sites/'.$site->id.'/orders')
        ->assertOk()
        ->assertSeeLivewire('shop.orders-list');
});

it('leaves ops as a section page', function () {
    [$site, $admin] = shopPageSite(admin: true);

    $this->actingAs($admin)
        ->get(route('sites.section', ['site' => $site, 'section' => 'ops']))
        ->assertOk()
        ->assertSeeLivewire('shop.ai-seed-panel');
});

it('points the product editor back at the products page', function () {
    [$site, $agent] = shopPageSite();
    $product = Product::factory()->for($site)->create();

    $html = $this->actingAs($agent)
        ->get(route('shop.admin.products.edit', ['site' => $site, 'product' => $product->id]))
        ->assertOk()
        ->getContent();

    expect($html)->toContain(route('sites.shop.products', $site));
});

it('highlights the Shop group on the dedicated products page', function () {
    [$site, $agent] = shopPageSite();

    $html = $this->actingAs($agent)
        ->get(route('sites.shop.products', $site))
        ->assertOk()
        ->getContent();

    expect($html)->toContain(route('sites.shop.products', $site))
        ->and($html)->toContain(route('sites.shop.categories', $site))
        ->and($html)->toContain(route('sites.shop.orders', $site))
        ->and($html)->toContain(route('sites.shop.storefront', $site))
        ->and($html)->toContain(route('sites.shop.reviews', $site))
        ->and($html)->toContain('data-nav-current="1"')
        ->and($html)->toMatch('/data-current[^>]*href="'.preg_quote(route('sites.shop.products', $site), '/').'"|href="'.preg_quote(route('sites.shop.products', $site), '/').'"[^>]*data-current/');
});
