<?php

use App\Enums\Shop\OrderStatus;
use App\Models\Client;
use App\Models\Shop\Category;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Shop\ShippingRate;
use App\Models\Site;
use App\Models\User;
use App\Services\Site\Editor\CommerceOperations;
use Database\Seeders\Shop\TaxClassSeeder;
use Livewire\Livewire;
use Tests\Support\CommerceReads;

/**
 * Client user on the customer domain whose client_id matches a site that
 * has an established shop (one published product).
 *
 * @return array{0: Site, 1: User, 2: Product}
 */
function portalShopSite(array $siteAttrs = []): array
{
    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(array_merge(['client_id' => $tenant->id], $siteAttrs));
    $product = Product::factory()->for($site)->published()->create();

    return [$site, $client, $product];
}

beforeEach(function () {
    $this->withoutVite();
});

function portalShopOrder(Site $site, string $number = 'P-1001'): Order
{
    return Order::create([
        'site_id' => $site->id,
        'number' => $number,
        'email' => 'buyer@example.com',
        'name' => 'Buyer',
        'status' => OrderStatus::Paid->value,
        'refund_status' => 'none',
        'subtotal_cents' => 1000,
        'shipping_cents' => 0,
        'tax_cents' => 0,
        'shipping_tax_cents' => 0,
        'total_cents' => 1000,
        'tax_country_code' => 'GB',
        'shipping_address_json' => [],
        'shipping_method_label' => 'Std',
        'placed_at' => now(),
    ]);
}

// ─── Owning client, established shop ─────────────────────────────────────────

it('lets the owning client open shop, product editor, orders and order detail', function () {
    [$site, $client, $product] = portalShopSite();
    $order = portalShopOrder($site);

    $this->actingAs($client)
        ->get(route('client.portal.shop', $site))
        ->assertRedirect(route('client.portal.shop.products', $site));

    $this->actingAs($client)
        ->get(route('client.portal.shop.products', $site))
        ->assertOk()
        ->assertSeeLivewire('shop.products-list');

    $this->actingAs($client)
        ->get(route('client.portal.shop.categories', $site))
        ->assertOk()
        ->assertSeeLivewire('shop.category-manager');

    $this->actingAs($client)
        ->get(route('client.portal.shop.products.edit', ['site' => $site, 'product' => $product->id]))
        ->assertOk()
        ->assertSeeLivewire('shop.product-editor');

    $this->actingAs($client)
        ->get(route('client.portal.orders', $site))
        ->assertOk()
        ->assertSeeLivewire('shop.orders-list');

    $this->actingAs($client)
        ->get(route('client.portal.orders.show', ['site' => $site, 'order' => $order->id]))
        ->assertOk()
        ->assertSeeLivewire('shop.order-detail');
});

// ─── Brochure site ───────────────────────────────────────────────────────────

it('404s shop and orders pages on a brochure site', function () {
    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);

    $this->actingAs($client)
        ->get(route('client.portal.shop', $site))
        ->assertNotFound();

    $this->actingAs($client)
        ->get(route('client.portal.shop.products.edit', ['site' => $site, 'product' => 1]))
        ->assertNotFound();

    $this->actingAs($client)
        ->get(route('client.portal.orders', $site))
        ->assertNotFound();

    $this->actingAs($client)
        ->get(route('client.portal.orders.show', ['site' => $site, 'order' => 1]))
        ->assertNotFound();
});

// ─── Enquire mode ────────────────────────────────────────────────────────────

it('keeps orders pages on an enquire-mode shop that has taken an order', function () {
    [$site, $client, $product] = portalShopSite(['shop_mode' => 'enquire']);
    $order = portalShopOrder($site);

    $this->actingAs($client)
        ->get(route('client.portal.shop.products', $site))
        ->assertOk();

    $this->actingAs($client)
        ->get(route('client.portal.shop.products.edit', ['site' => $site, 'product' => $product->id]))
        ->assertOk();

    $this->actingAs($client)
        ->get(route('client.portal.orders', $site))
        ->assertOk();

    $this->actingAs($client)
        ->get(route('client.portal.orders.show', ['site' => $site, 'order' => $order->id]))
        ->assertOk();
});

// ─── Cross-tenant ────────────────────────────────────────────────────────────

it('forbids a client of a different site from shop and orders pages', function () {
    [$site, , $product] = portalShopSite();
    $order = portalShopOrder($site);
    [, $stranger] = portalShopSite();

    $this->actingAs($stranger)
        ->get(route('client.portal.shop', $site))
        ->assertForbidden();

    $this->actingAs($stranger)
        ->get(route('client.portal.shop.products', $site))
        ->assertForbidden();

    $this->actingAs($stranger)
        ->get(route('client.portal.shop.categories', $site))
        ->assertForbidden();

    $this->actingAs($stranger)
        ->get(route('client.portal.shop.products.edit', ['site' => $site, 'product' => $product->id]))
        ->assertForbidden();

    $this->actingAs($stranger)
        ->get(route('client.portal.orders', $site))
        ->assertForbidden();

    $this->actingAs($stranger)
        ->get(route('client.portal.orders.show', ['site' => $site, 'order' => $order->id]))
        ->assertForbidden();
});

it('404s an order that belongs to another site (IDOR)', function () {
    [$site, $client] = portalShopSite();
    [$otherSite] = portalShopSite();
    $foreignOrder = portalShopOrder($otherSite, 'P-FOREIGN');

    $this->actingAs($client)
        ->get(route('client.portal.orders.show', ['site' => $site, 'order' => $foreignOrder->id]))
        ->assertNotFound();
});

// ─── Guests ──────────────────────────────────────────────────────────────────

it('redirects guests from shop and orders pages to login', function () {
    [$site, , $product] = portalShopSite();
    $order = portalShopOrder($site);

    $this->get(route('client.portal.shop', $site))->assertRedirect();
    $this->get(route('client.portal.shop.products.edit', ['site' => $site, 'product' => $product->id]))->assertRedirect();
    $this->get(route('client.portal.orders', $site))->assertRedirect();
    $this->get(route('client.portal.orders.show', ['site' => $site, 'order' => $order->id]))->assertRedirect();
});

// ─── Pages (help, heading, cards, back link) ─────────────────────────────────

it('the client categories page shares the manager copy and hides storefront paths without a public host', function () {
    [$site, $client] = portalShopSite();
    $site->forceFill(['preview_domain' => null, 'custom_domain' => null, 'custom_domain_status' => null])->save();

    $html = $this->actingAs($client)
        ->get(route('client.portal.shop.categories', $site))
        ->assertOk()
        ->assertSeeLivewire('shop.category-manager')
        ->getContent();

    expect($html)->toContain('Add category')
        ->and($html)->toContain('No categories yet — add one to start organising the shop.')
        ->and($html)->not->toContain('/collections/');
});

it('the products and categories pages have a help slot and their Livewire card', function () {
    [$site, $client] = portalShopSite();

    $this->actingAs($client)->get(route('client.portal.shop.products', $site))->assertOk()->assertSee('Products');
    $this->actingAs($client)->get(route('client.portal.shop.categories', $site))->assertOk()->assertSee('Categories');

    $productsBlade = file_get_contents(resource_path('views/client/portal/shop-products.blade.php'));
    $categoriesBlade = file_get_contents(resource_path('views/client/portal/shop-categories.blade.php'));
    expect($productsBlade)->toContain('<x-slot:help>')
        ->toContain('livewire:shop.products-list');
    expect($categoriesBlade)->toContain('<x-slot:help>')
        ->toContain('livewire:shop.category-manager');
});

it('the product editor page links back to the portal shop page', function () {
    [$site, $client, $product] = portalShopSite();

    $this->actingAs($client)
        ->get(route('client.portal.shop.products.edit', ['site' => $site, 'product' => $product->id]))
        ->assertOk()
        ->assertSee(route('client.portal.shop.products', $site), false);
});

it('the orders page mounts orders-list and the order page mounts order-detail', function () {
    [$site, $client] = portalShopSite();
    $order = portalShopOrder($site);

    $this->actingAs($client)
        ->get(route('client.portal.orders', $site))
        ->assertOk()
        ->assertSeeLivewire('shop.orders-list');

    $this->actingAs($client)
        ->get(route('client.portal.orders.show', ['site' => $site, 'order' => $order->id]))
        ->assertOk()
        ->assertSeeLivewire('shop.order-detail');
});

it('the agents order detail page mounts the same order-detail component as the portal', function () {
    $staff = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $staff->id]);
    $order = portalShopOrder($site);

    $this->actingAs($staff)
        ->get(route('shop.admin.orders.show', ['site' => $site->id, 'order' => $order->id]))
        ->assertOk()
        ->assertSeeLivewire('shop.order-detail');
});

// ─── Route overrides so portal links stay on the customer surface ────────────

it('products-list defaults to the agents editor route and accepts a portal editRoute', function () {
    $staff = User::factory()->staff()->create();
    $agentSite = Site::factory()->create(['created_by_user_id' => $staff->id]);
    $agentProduct = Product::factory()->for($agentSite)->published()->create();

    Livewire::actingAs($staff)
        ->test('shop.products-list', ['siteId' => $agentSite->id])
        ->assertSee(route('shop.admin.products.edit', ['site' => $agentSite->id, 'product' => $agentProduct->id]), false);

    [$site, $client, $product] = portalShopSite();

    Livewire::actingAs($client)
        ->test('shop.products-list', [
            'siteId' => $site->id,
            'editRoute' => 'client.portal.shop.products.edit',
        ])
        ->assertSee(route('client.portal.shop.products.edit', ['site' => $site->id, 'product' => $product->id]), false);
});

it('hides delete but exposes export from the products list for a client user', function () {
    [$site, $client, $product] = portalShopSite();

    $html = Livewire::actingAs($client)
        ->test('shop.products-list', [
            'siteId' => $site->id,
            'editRoute' => 'client.portal.shop.products.edit',
            'exportRoute' => 'client.portal.shop.products.export',
        ])
        ->html();

    expect($html)->toContain('Add product')
        ->and($html)->toContain(route('client.portal.shop.products.edit', ['site' => $site->id, 'product' => $product->id]))
        ->and($html)->toContain('Export CSV')
        ->and($html)->not->toContain('Delete');

    // Export is the client's own catalogue read, gated by SitePolicy `view`
    // (belongs-to-tenant) — not the staff-only `delete` ability.
    $this->actingAs($client)
        ->get(route('client.portal.shop.products.export', $site))
        ->assertSuccessful();
});

it('the portal shop page links product edit at the client route, not the agents route', function () {
    [$site, $client, $product] = portalShopSite();
    $html = $this->actingAs($client)->get(route('client.portal.shop.products', $site))->assertOk()->getContent();

    expect($html)
        ->toContain(route('client.portal.shop.products.edit', ['site' => $site, 'product' => $product->id]))
        ->not->toContain(route('shop.admin.products.edit', ['site' => $site, 'product' => $product->id]));
});

it('orders-list defaults to the agents order route and accepts a portal routeName', function () {
    $staff = User::factory()->staff()->create();
    $agentSite = Site::factory()->create(['created_by_user_id' => $staff->id]);
    $agentOrder = portalShopOrder($agentSite, 'A-1');

    Livewire::actingAs($staff)
        ->test('shop.orders-list', ['siteId' => $agentSite->id])
        ->assertSee(route('shop.admin.orders.show', ['site' => $agentSite->id, 'order' => $agentOrder->id]), false);

    [$site, $client] = portalShopSite();
    $order = portalShopOrder($site);

    Livewire::actingAs($client)
        ->test('shop.orders-list', [
            'siteId' => $site->id,
            'routeName' => 'client.portal.orders.show',
        ])
        ->assertSee(route('client.portal.orders.show', ['site' => $site->id, 'order' => $order->id]), false);
});

it('the portal orders page links order detail at the client route, not the agents route', function () {
    [$site, $client] = portalShopSite();
    $order = portalShopOrder($site);
    $html = $this->actingAs($client)->get(route('client.portal.orders', $site))->assertOk()->getContent();

    expect($html)
        ->toContain(route('client.portal.orders.show', ['site' => $site, 'order' => $order->id]))
        ->not->toContain(route('shop.admin.orders.show', ['site' => $site, 'order' => $order->id]));
});

// ─── Sidebar ─────────────────────────────────────────────────────────────────

it('sidebar shows Products then Categories then Orders, with Enquiries after the Shop group', function () {
    [$site, $client] = portalShopSite();
    $html = $this->actingAs($client)
        ->get(route('client.portal.enquiries', $site))
        ->assertOk()
        ->getContent();

    $products = strpos($html, route('client.portal.shop.products', $site));
    $categories = strpos($html, route('client.portal.shop.categories', $site));
    $orders = strpos($html, route('client.portal.orders', $site));
    $enquiries = strpos($html, route('client.portal.enquiries', $site));

    expect($products)->not->toBeFalse()
        ->and($categories)->not->toBeFalse()
        ->and($orders)->not->toBeFalse()
        ->and($enquiries)->not->toBeFalse()
        ->and($products)->toBeLessThan($categories)
        ->and($categories)->toBeLessThan($orders)
        ->and($orders)->toBeLessThan($enquiries);

    $sidebar = file_get_contents(resource_path('views/layouts/client/sidebar.blade.php'));
    expect($sidebar)->toContain("'shopping-bag'")
        ->toContain("'inbox-stack'");
});

it('sidebar hides Shop and Orders on a brochure site', function () {
    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);

    $html = $this->actingAs($client)
        ->get(route('client.portal.enquiries', $site))
        ->assertOk()
        ->getContent();

    expect($html)
        ->not->toContain('/sites/'.$site->id.'/products')
        ->not->toContain('/sites/'.$site->id.'/shop')
        ->not->toContain('/sites/'.$site->id.'/storefront')
        ->not->toContain('/sites/'.$site->id.'/orders');
});

it('sidebar hides Orders on an enquire-mode shop but still shows Shop', function () {
    [$site, $client] = portalShopSite(['shop_mode' => 'enquire']);
    $html = $this->actingAs($client)
        ->get(route('client.portal.shop.products', $site))
        ->assertOk()
        ->getContent();

    expect($html)->toContain(route('client.portal.shop.products', $site))
        ->not->toContain(route('client.portal.orders', $site));
});

// ─── CSP nonce (customer surface) ────────────────────────────────────────────

/**
 * Inline <script> tags on a rendered page that lack the response CSP nonce.
 *
 * @return list<string>
 */
function portalShopInlineScriptsMissingNonce(string $html, string $nonce): array
{
    preg_match_all('/<script\b([^>]*)>/i', $html, $matches);
    $offenders = [];

    foreach ($matches[1] as $attrs) {
        if (preg_match('/(?<![-\w])src\s*=/i', $attrs) === 1) {
            continue;
        }

        if (preg_match('/\bnonce\s*=\s*["\']'.preg_quote($nonce, '/').'["\']/', $attrs) !== 1) {
            $offenders[] = '<script'.$attrs.'>';
        }
    }

    return $offenders;
}

it('shop and orders portal pages ship no inline script without the layout nonce', function () {
    [$site, $client, $product] = portalShopSite();
    $order = portalShopOrder($site);
    $host = (string) config('domains.customer_domain');

    $pages = [
        'shop' => route('client.portal.shop.products', $site),
        'product-edit' => route('client.portal.shop.products.edit', ['site' => $site, 'product' => $product->id]),
        'orders' => route('client.portal.orders', $site),
        'order' => route('client.portal.orders.show', ['site' => $site, 'order' => $order->id]),
    ];

    foreach ($pages as $label => $url) {
        $response = $this->actingAs($client)
            ->withServerVariables(['HTTP_HOST' => $host])
            ->get($url);

        $response->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');
        expect($csp)->not->toBe('')
            ->toMatch("/script-src 'self' 'nonce-[^']+'/");

        preg_match("/script-src [^;]*'nonce-([^']+)'/", $csp, $nonceMatch);
        $nonce = $nonceMatch[1] ?? '';

        expect(portalShopInlineScriptsMissingNonce($response->getContent(), $nonce))
            ->toBe([], "inline <script> without the layout nonce on {$label}");
    }
});

it('302s the combined shop URL to the products page', function () {
    [$site, $client] = portalShopSite();

    $this->actingAs($client)
        ->get(route('client.portal.shop', $site))
        ->assertRedirect(route('client.portal.shop.products', $site));
});

it('serves dedicated products and categories pages', function () {
    [$site, $client] = portalShopSite();

    $this->actingAs($client)
        ->get(route('client.portal.shop.products', $site))
        ->assertOk()
        ->assertSeeLivewire('shop.products-list')
        ->assertDontSeeLivewire('shop.category-manager');

    $this->actingAs($client)
        ->get(route('client.portal.shop.categories', $site))
        ->assertOk()
        ->assertSeeLivewire('shop.category-manager')
        ->assertDontSeeLivewire('shop.products-list');
});

it('404s products and categories on a brochure site and keeps orders gating', function () {
    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);

    $this->actingAs($client)->get(route('client.portal.shop.products', $site))->assertNotFound();
    $this->actingAs($client)->get(route('client.portal.shop.categories', $site))->assertNotFound();
});

it('groups Content and Shop in the client sidebar with mini-state titles on group headers', function () {
    [$site, $client] = portalShopSite();

    $html = $this->actingAs($client)
        ->get(route('client.portal.shop.products', $site))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('siteworks.nav.content')
        ->and($html)->toContain('siteworks.nav.shop')
        ->and($html)->toContain(__('Content'))
        ->and($html)->toContain(__('Shop'))
        ->and($html)->toContain(route('client.portal.shop.products', $site))
        ->and($html)->toContain(route('client.portal.shop.categories', $site))
        ->and($html)->toContain(route('client.portal.shop.storefront', $site))
        ->and($html)->toContain(route('client.portal.orders', $site))
        ->and($html)->toMatch('/title="Content"/')
        ->and($html)->toMatch('/title="Shop"/');

    // On a Shop child page (Products active) the Shop group header tints its
    // label with accent text but must NOT paint the filled bg-accent/15 block —
    // only the active leaf carries the single fill, so exactly one bg-accent/15
    // survives in the sidebar. Scope to the aside so the help-slot's own
    // bg-accent/15 in <main> doesn't leak into the count.
    preg_match('/<aside[^>]*\bdata-cp-sidebar\b[\s\S]*?<\/nav>/', $html, $sidebarMatch);
    $sidebar = $sidebarMatch[0] ?? '';
    expect($sidebar)->not->toBeEmpty()
        ->and(substr_count($sidebar, 'bg-accent/15'))->toBe(1);
});

// ─── Storefront (fulfilment host) ─────────────────────────────────────────────

it('the products page no longer hosts the fulfilment editor or shipping summary', function () {
    [$site, $client] = portalShopSite();
    ShippingRate::create([
        'site_id' => $site->id,
        'strategy' => 'weight_tiers',
        'flat_amount_cents' => 0,
        'method_label' => 'Weight',
    ]);

    $this->actingAs($client)
        ->get(route('client.portal.shop.products', $site))
        ->assertOk()
        ->assertSeeLivewire('shop.products-list')
        ->assertDontSeeLivewire('shop.fulfilment-editor')
        ->assertDontSee('Weight tiers');
});

it('lets the owning client open the storefront page', function () {
    [$site, $client] = portalShopSite();

    $this->actingAs($client)
        ->get(route('client.portal.shop.storefront', $site))
        ->assertOk()
        ->assertSee('Storefront');
});

it('the storefront page mounts the fulfilment editor and the shipping summary', function () {
    [$site, $client] = portalShopSite();
    ShippingRate::create([
        'site_id' => $site->id,
        'strategy' => 'weight_tiers',
        'flat_amount_cents' => 0,
        'method_label' => 'Weight',
    ]);

    $this->actingAs($client)
        ->get(route('client.portal.shop.storefront', $site))
        ->assertOk()
        ->assertSeeLivewire('shop.fulfilment-editor')
        ->assertSee('Weight tiers')
        ->assertDontSeeLivewire('shop.shop-hero-picker')
        ->assertDontSeeLivewire('shop.shipping-rate-editor')
        ->assertDontSeeLivewire('shop.products-list');
});

it('forbids a client of a different site from the storefront page', function () {
    [$site] = portalShopSite();
    [, $stranger] = portalShopSite();

    $this->actingAs($stranger)
        ->get(route('client.portal.shop.storefront', $site))
        ->assertForbidden();
});

it('404s the storefront page on a brochure site', function () {
    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);

    $this->actingAs($client)
        ->get(route('client.portal.shop.storefront', $site))
        ->assertNotFound();
});

it('redirects guests from the storefront page to login', function () {
    [$site] = portalShopSite();

    $this->get(route('client.portal.shop.storefront', $site))->assertRedirect();
});

it('sidebar shows Storefront after Categories and before Reviews for a shop-enabled site', function () {
    [$site, $client] = portalShopSite();
    $html = $this->actingAs($client)
        ->get(route('client.portal.shop.products', $site))
        ->assertOk()
        ->getContent();

    $products = strpos($html, route('client.portal.shop.products', $site));
    $categories = strpos($html, route('client.portal.shop.categories', $site));
    $storefront = strpos($html, route('client.portal.shop.storefront', $site));
    $reviews = strpos($html, route('client.portal.shop.reviews', $site));

    expect($products)->not->toBeFalse()
        ->and($categories)->not->toBeFalse()
        ->and($storefront)->not->toBeFalse()
        ->and($reviews)->not->toBeFalse()
        ->and($products)->toBeLessThan($categories)
        ->and($categories)->toBeLessThan($storefront)
        ->and($storefront)->toBeLessThan($reviews);

    $sidebar = file_get_contents(resource_path('views/layouts/client/sidebar.blade.php'));
    expect($sidebar)->toContain("'building-storefront'")
        ->toContain('client.portal.shop.storefront');
});

// ─── WebMCP seed (client portal shop pages) ──────────────────────────────────

/**
 * @return array<string, mixed>
 */
function portalShopShellConfig(string $html): array
{
    preg_match("/window\\.__siteworks_editor_shell_config__ = JSON\\.parse\\('(.*)'\\);/", $html, $matches);
    expect($matches)->toHaveKey(1);

    $json = json_decode('"'.$matches[1].'"', true, 512, JSON_THROW_ON_ERROR);

    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @return array<string, string>
 */
function portalShopToolPages(Site $site): array
{
    return [
        'products' => route('client.portal.shop.products', $site),
        'categories' => route('client.portal.shop.categories', $site),
        'storefront' => route('client.portal.shop.storefront', $site),
        'reviews' => route('client.portal.shop.reviews', $site),
    ];
}

/**
 * @return array{0: Site, 1: User}
 */
function portalShopWithCatalogue(): array
{
    [$site, $client] = portalShopSite();
    Category::factory()->for($site)->create();
    CommerceReads::giveShop($site);

    return [$site, $client];
}

function enablePortalWebmcp(): void
{
    config([
        'editor.operations.enabled' => true,
        'editor.agent_tools.enabled' => true,
        'editor.agent_tools.roles' => ['staff', 'client'],
        'editor.agent_tools.client_portal_enabled' => true,
    ]);
}

it('does not advertise agent_tools on portal shop pages when the client-portal setting is off', function () {
    CommerceReads::enableFlags();
    [$site, $client] = portalShopWithCatalogue();

    foreach (portalShopToolPages($site) as $url) {
        $html = $this->actingAs($client)
            ->get($url)
            ->assertOk()
            ->getContent();

        $config = portalShopShellConfig($html);

        expect($config['surface'])->toBe('portal-shop')
            ->and($config['capabilities'])->not->toContain('agent_tools')
            ->and($config)->not->toHaveKey('agentTools')
            ->and($config)->not->toHaveKey('operationUrl')
            ->and($config)->not->toHaveKey('csrfToken')
            ->and($config)->not->toHaveKey('protocol');

        expect($html)->not->toContain('operationUrl')
            ->and($html)->not->toContain('/operations/__operation__');
    }
});

it('seeds portal-shop agent tools on the four portal shop pages when both gates are on', function () {
    enablePortalWebmcp();
    [$site, $client] = portalShopWithCatalogue();

    foreach (portalShopToolPages($site) as $label => $url) {
        $html = $this->actingAs($client)
            ->get($url)
            ->assertOk()
            ->getContent();

        $config = portalShopShellConfig($html);

        expect($config['surface'])->toBe('portal-shop')
            ->and($config['capabilities'])->toContain('agent_tools')
            ->and($config['agentTools'])->toEqualCanonicalizing(CommerceOperations::SANDBOX)
            ->and($config['operationUrl'])->toBe("/sites/{$site->id}/operations/__operation__")
            ->and($config['protocol'])->toBe('siteworks-editor-1')
            ->and($config['csrfToken'])->not->toBeEmpty();
    }
});

it('forbids a client of a different site from executing an editor operation against this site', function () {
    enablePortalWebmcp();
    [$site] = portalShopWithCatalogue();
    [, $stranger] = portalShopSite();

    $this->actingAs($stranger)
        ->withHeaders(['X-Editor-Channel' => 'webmcp'])
        ->postJson(route('site.editor.operation', ['site' => $site->id, 'operation' => 'list_products']), [
            'limit' => 10,
        ])
        ->assertForbidden()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'forbidden')
        ->assertJsonPath('error.message', 'Not allowed on this site.');
});

it('forbids a handcrafted client draft_product when the client-portal setting is off', function () {
    CommerceReads::enableFlags();
    config(['editor.agent_tools.roles' => ['staff', 'client']]);
    $this->seed(TaxClassSeeder::class);
    [$site, $client] = portalShopWithCatalogue();
    $category = Category::query()->where('site_id', $site->id)->first();

    $this->actingAs($client)
        ->withHeaders(['X-Editor-Channel' => 'webmcp'])
        ->postJson(
            route('site.editor.operation', ['site' => $site->id, 'operation' => 'draft_product']),
            CommerceReads::draftProductInput(['category_slug' => $category->slug]),
        )
        ->assertForbidden()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'forbidden')
        ->assertJsonPath('error.message', 'Agent tools are disabled for this actor.');

    expect(Product::query()->where('site_id', $site->id)->where('name', 'Hand-poured Candle')->exists())->toBeFalse();
});

it('forbids a client regenerate_hero when the portal channel is fully open', function () {
    enablePortalWebmcp();
    [$site, $client] = portalShopWithCatalogue();

    $this->actingAs($client)
        ->withHeaders(['X-Editor-Channel' => 'webmcp'])
        ->postJson(route('site.editor.operation', ['site' => $site->id, 'operation' => 'regenerate_hero']), [
            'composition_revision' => 0,
        ])
        ->assertForbidden()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'forbidden')
        ->assertJsonPath('error.message', 'Agent tools are disabled for this actor.');
});

it('forbids a client list_theme_token_presets posted without an editor channel header', function () {
    enablePortalWebmcp();
    [$site, $client] = portalShopWithCatalogue();

    $this->actingAs($client)
        ->postJson(route('site.editor.operation', ['site' => $site->id, 'operation' => 'list_theme_token_presets']))
        ->assertForbidden()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'forbidden')
        ->assertJsonPath('error.message', 'Agent tools are disabled for this actor.');
});

it('lets a same-tenant client draft_product through the editor operation endpoint', function () {
    enablePortalWebmcp();
    $this->seed(TaxClassSeeder::class);
    [$site, $client] = portalShopWithCatalogue();
    $category = Category::query()->where('site_id', $site->id)->first();

    $this->actingAs($client)
        ->withHeaders(['X-Editor-Channel' => 'webmcp'])
        ->postJson(
            route('site.editor.operation', ['site' => $site->id, 'operation' => 'draft_product']),
            CommerceReads::draftProductInput(['category_slug' => $category->slug]),
        )
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect(Product::query()->where('site_id', $site->id)->where('name', 'Hand-poured Candle')->exists())->toBeTrue();
});

it('forbids a client of a different site from draft_product on this site', function () {
    enablePortalWebmcp();
    $this->seed(TaxClassSeeder::class);
    [$site] = portalShopWithCatalogue();
    [, $stranger] = portalShopSite();
    $category = Category::query()->where('site_id', $site->id)->first();

    $this->actingAs($stranger)
        ->withHeaders(['X-Editor-Channel' => 'webmcp'])
        ->postJson(
            route('site.editor.operation', ['site' => $site->id, 'operation' => 'draft_product']),
            CommerceReads::draftProductInput(['category_slug' => $category->slug]),
        )
        ->assertForbidden()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'forbidden')
        ->assertJsonPath('error.message', 'Not allowed on this site.');

    expect(Product::query()->where('site_id', $site->id)->where('name', 'Hand-poured Candle')->exists())->toBeFalse();
});

it('still seeds shop-admin on the agents shop pages after the portal mount', function () {
    CommerceReads::enableFlags();
    [$actor, $site] = CommerceReads::shopSite();

    $html = $this->actingAs($actor)
        ->get(route('sites.shop.products', $site))
        ->assertOk()
        ->getContent();

    $config = portalShopShellConfig($html);

    expect($config['surface'])->toBe('shop-admin')
        ->and($config['capabilities'])->toContain('agent_tools')
        ->and($config['agentTools'])->toEqualCanonicalizing(CommerceOperations::SANDBOX);
});

