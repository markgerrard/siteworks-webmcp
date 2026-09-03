<?php

use App\Enums\AgentRole;
use App\Enums\Shop\OrderStatus;
use App\Models\Client;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

/**
 * Slice immediately before the probe so wrapper classes can be compared
 * without Flux/Livewire ids that change between renders.
 */
function cpWidthPreface(string $html, string $probe = 'data-probe'): string
{
    $pos = strpos($html, $probe);
    expect($pos)->not->toBeFalse('probe marker missing from render');

    return substr($html, max(0, $pos - 500), 500);
}

function assertCpWidthMode(string $html, string $mode): void
{
    if ($mode === 'page') {
        expect($html)->toContain('data-cp-width="page"')
            ->and($html)->toContain('lg:max-w-[62.5rem]')
            ->and($html)->toContain('lg:mx-auto')
            ->and($html)->toContain('w-full')
            ->and($html)->not->toMatch('/(?<!lg:)max-w-\[62\.5rem\]/');

        return;
    }

    expect($html)->not->toContain('data-cp-width="page"')
        ->and($html)->not->toContain('max-w-[62.5rem]');
}

it('renders omitted and explicit full wrappers byte-identically with no page constraint', function () {
    $omitted = Blade::render('<x-cp-page-width><span data-probe>x</span></x-cp-page-width>');
    $full = Blade::render('<x-cp-page-width width="full"><span data-probe>x</span></x-cp-page-width>');

    expect($omitted)->toBe($full)
        ->and($omitted)->toBe('<span data-probe>x</span>');
    assertCpWidthMode($omitted, 'full');
});

it('wraps page mode with the centred max-width classes', function () {
    $html = Blade::render('<x-cp-page-width width="page"><span data-probe>x</span></x-cp-page-width>');

    assertCpWidthMode($html, 'page');
    expect($html)->toContain('<span data-probe>x</span>');
});

it('keeps page mode identical to full below the lg breakpoint', function () {
    $html = Blade::render('<x-cp-page-width width="page"><span data-probe>x</span></x-cp-page-width>');

    expect($html)->toContain('lg:max-w-[62.5rem]')
        ->and($html)->toContain('lg:mx-auto')
        ->and($html)->not->toMatch('/(?<!lg:)max-w-\[62\.5rem\]/')
        ->and($html)->not->toMatch('/(?<!lg:)mx-auto/');
});

it('agents layout omitted width matches explicit full and page adds the wrapper', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $this->actingAs($agent);

    $omitted = Blade::render('<x-layouts::app><span data-probe>x</span></x-layouts::app>');
    $full = Blade::render('<x-layouts::app width="full"><span data-probe>x</span></x-layouts::app>');
    $page = Blade::render('<x-layouts::app width="page"><span data-probe>x</span></x-layouts::app>');

    expect(cpWidthPreface($omitted))->toBe(cpWidthPreface($full));
    assertCpWidthMode($omitted, 'full');
    assertCpWidthMode($full, 'full');
    assertCpWidthMode($page, 'page');
});

it('client layout omitted width matches explicit full and page adds the wrapper', function () {
    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);
    $this->actingAs($client);

    $omitted = Blade::render('<x-layouts::client :site="$site"><span data-probe>x</span></x-layouts::client>', ['site' => $site]);
    $full = Blade::render('<x-layouts::client :site="$site" width="full"><span data-probe>x</span></x-layouts::client>', ['site' => $site]);
    $page = Blade::render('<x-layouts::client :site="$site" width="page"><span data-probe>x</span></x-layouts::client>', ['site' => $site]);

    expect(cpWidthPreface($omitted))->toBe(cpWidthPreface($full));
    assertCpWidthMode($omitted, 'full');
    assertCpWidthMode($full, 'full');
    assertCpWidthMode($page, 'page');
});

/**
 * @return array{site: Site, agent: User}
 */
function cpWidthAgentSite(bool $admin = false): array
{
    $agent = User::factory()->staff($admin ? AgentRole::Admin : AgentRole::Agent)->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $agent->id,
        'preview_domain' => 'cpw-'.uniqid(),
        'preview_brand' => 'a',
    ]);
    GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    Preview::factory()->for($site)->create(['slug' => 'cpw-'.uniqid()]);
    test()->actingAs($agent);

    return compact('site', 'agent');
}

function cpWidthGet(string $url): string
{
    return test()->get($url)->assertOk()->getContent();
}

function cpWidthPaidOrder(Site $site): Order
{
    return Order::create([
        'site_id' => $site->id,
        'number' => 'P-'.random_int(1000, 9999),
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

it('tags the agents route with the declared width mode', function (string $mode, Closure $visit) {
    assertCpWidthMode($visit(), $mode);
})->with([
    'dashboard is full — it is a list (2026-08-31 ruling)' => ['full', function () {
        cpWidthAgentSite();

        return cpWidthGet(route('dashboard'));
    }],
    'sites index is full' => ['full', function () {
        cpWidthAgentSite();

        return cpWidthGet(route('sites.index'));
    }],
    'sites create is page' => ['page', function () {
        cpWidthAgentSite();

        return cpWidthGet(route('sites.create'));
    }],
    'site overview is page (D58)' => ['page', function () {
        ['site' => $site] = cpWidthAgentSite();

        return cpWidthGet(route('sites.show', $site));
    }],
    'clients index is full' => ['full', function () {
        cpWidthAgentSite();

        return cpWidthGet(route('clients.index'));
    }],
    'clients create is page' => ['page', function () {
        cpWidthAgentSite();

        return cpWidthGet(route('clients.create'));
    }],
    'clients edit is page' => ['page', function () {
        cpWidthAgentSite();
        $client = Client::factory()->create();

        return cpWidthGet(route('clients.edit', $client));
    }],
    'pages section is page (2026-08-31)' => ['page', function () {
        ['site' => $site] = cpWidthAgentSite();

        return cpWidthGet(route('sites.section', ['site' => $site, 'section' => 'pages']));
    }],
    'enquiries list is full' => ['full', function () {
        ['site' => $site] = cpWidthAgentSite();

        return cpWidthGet(route('sites.section', ['site' => $site, 'section' => 'enquiries']));
    }],
    'history is full' => ['full', function () {
        ['site' => $site] = cpWidthAgentSite();

        return cpWidthGet(route('sites.section', ['site' => $site, 'section' => 'history']));
    }],
    'ops is full' => ['full', function () {
        ['site' => $site] = cpWidthAgentSite(admin: true);

        return cpWidthGet(route('sites.section', ['site' => $site, 'section' => 'ops']));
    }],
    'media library is full' => ['full', function () {
        ['site' => $site] = cpWidthAgentSite();

        return cpWidthGet(route('sites.media', $site));
    }],
    'deliverables is full' => ['full', function () {
        ['site' => $site] = cpWidthAgentSite(admin: true);

        return cpWidthGet(route('site.managed-content.deliverables', $site).'?month='.now()->format('Y-m'));
    }],
    'admin index is full' => ['full', function () {
        cpWidthAgentSite(admin: true);

        return cpWidthGet(route('admin.index'));
    }],
    'ai safety is full' => ['full', function () {
        cpWidthAgentSite(admin: true);

        return cpWidthGet(route('admin.ai-safety'));
    }],
    'products list is full' => ['full', function () {
        ['site' => $site] = cpWidthAgentSite();

        return cpWidthGet(route('sites.shop.products', $site));
    }],
    'orders list is full' => ['full', function () {
        ['site' => $site] = cpWidthAgentSite();
        cpWidthPaidOrder($site);

        return cpWidthGet(route('sites.shop.orders', $site));
    }],
    'categories manager is full' => ['full', function () {
        ['site' => $site] = cpWidthAgentSite();

        return cpWidthGet(route('sites.shop.categories', $site));
    }],
    'product editor is full (matches categories manager)' => ['full', function () {
        ['site' => $site] = cpWidthAgentSite();
        $product = Product::factory()->for($site)->create();

        return cpWidthGet(route('shop.admin.products.edit', ['site' => $site, 'product' => $product->id]));
    }],
    'order detail is page' => ['page', function () {
        ['site' => $site] = cpWidthAgentSite();
        $order = cpWidthPaidOrder($site);

        return cpWidthGet(route('shop.admin.orders.show', ['site' => $site, 'order' => $order->id]));
    }],
    'storefront settings is page' => ['page', function () {
        ['site' => $site] = cpWidthAgentSite();

        return cpWidthGet(route('sites.shop.storefront', $site));
    }],
    'design settings is page' => ['page', function () {
        ['site' => $site] = cpWidthAgentSite();

        return cpWidthGet(route('sites.section', ['site' => $site, 'section' => 'design']));
    }],
    'site settings is page' => ['page', function () {
        ['site' => $site] = cpWidthAgentSite();

        return cpWidthGet(route('sites.section', ['site' => $site, 'section' => 'settings']));
    }],
    'navigation is page' => ['page', function () {
        ['site' => $site] = cpWidthAgentSite();

        return cpWidthGet(route('sites.section', ['site' => $site, 'section' => 'navigation']));
    }],
    'personalise is page' => ['page', function () {
        ['site' => $site] = cpWidthAgentSite();

        return cpWidthGet(route('sites.section', ['site' => $site, 'section' => 'personalise']));
    }],
    'chatbot is page' => ['page', function () {
        ['site' => $site] = cpWidthAgentSite();

        return cpWidthGet(route('sites.section', ['site' => $site, 'section' => 'chatbot']));
    }],
]);
it('keeps the product editor full width with a plain header row and in-column save bar', function () {
    ['site' => $site] = cpWidthAgentSite();
    $product = Product::factory()->for($site)->create();

    $html = cpWidthGet(route('shop.admin.products.edit', ['site' => $site, 'product' => $product->id]));
    $saveBarPos = strpos($html, 'data-save-bar-chrome');

    expect($html)->not->toContain('data-cp-width="page"')
        ->and($html)->not->toContain('sticky top-0 z-20')
        ->and($saveBarPos)->not->toBeFalse();

    // The D48 guard's intent: the SAVE BAR stays in-column, never viewport-fixed.
    // Scope it to the save-bar element — T47's assistant overlay backdrop is
    // legitimate fixed viewport chrome and must not trip this.
    $saveBarTag = substr($html, $saveBarPos, strpos($html, '>', $saveBarPos) - $saveBarPos);
    expect($saveBarTag)->not->toContain('fixed inset-x-0');
});

it('spans the product editor unsaved-changes bar across the page column, not the viewport', function () {
    ['site' => $site] = cpWidthAgentSite();
    $product = Product::factory()->for($site)->create();

    $html = Livewire::test('shop.product-editor', [
        'siteId' => $site->id,
        'productId' => $product->id,
    ])->set('hasUnsavedChanges', true)->html();

    expect($html)->toContain('sticky bottom-0')
        ->and($html)->toContain('w-full')
        ->and($html)->not->toContain('fixed inset-x-0');
});

/**
 * @return array{site: Site, client: User, product: Product}
 */
function cpWidthPortalSite(): array
{
    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create([
        'client_id' => $tenant->id,
        'native_reviews_enabled' => true,
    ]);
    $product = Product::factory()->for($site)->published()->create();
    config(['site.native_reviews_enabled' => true]);
    test()->actingAs($client);

    return compact('site', 'client', 'product');
}

it('tags the client portal route with the same width mode as agents', function (string $mode, Closure $visit) {
    assertCpWidthMode($visit(), $mode);
})->with([
    'portal sites index is full' => ['full', function () {
        cpWidthPortalSite();

        return cpWidthGet(route('client.portal.sites'));
    }],
    'portal pages is full' => ['full', function () {
        ['site' => $site] = cpWidthPortalSite();

        return cpWidthGet(route('client.portal.site', $site));
    }],
    'portal products list is full' => ['full', function () {
        ['site' => $site] = cpWidthPortalSite();

        return cpWidthGet(route('client.portal.shop.products', $site));
    }],
    'portal categories is full' => ['full', function () {
        ['site' => $site] = cpWidthPortalSite();

        return cpWidthGet(route('client.portal.shop.categories', $site));
    }],
    'portal orders list is full' => ['full', function () {
        ['site' => $site] = cpWidthPortalSite();
        cpWidthPaidOrder($site);

        return cpWidthGet(route('client.portal.orders', $site));
    }],
    'portal enquiries list is full' => ['full', function () {
        ['site' => $site] = cpWidthPortalSite();

        return cpWidthGet(route('client.portal.enquiries', $site));
    }],
    'portal history is full' => ['full', function () {
        ['site' => $site] = cpWidthPortalSite();
        config(['site.use_versioned_renderer' => true]);

        return cpWidthGet(route('client.portal.history', $site));
    }],
    'portal reviews is full' => ['full', function () {
        ['site' => $site] = cpWidthPortalSite();

        return cpWidthGet(route('client.portal.reviews', $site));
    }],
    'portal product editor is page' => ['page', function () {
        ['site' => $site, 'product' => $product] = cpWidthPortalSite();

        return cpWidthGet(route('client.portal.shop.products.edit', ['site' => $site, 'product' => $product->id]));
    }],
    'portal order detail is page' => ['page', function () {
        ['site' => $site] = cpWidthPortalSite();
        $order = cpWidthPaidOrder($site);

        return cpWidthGet(route('client.portal.orders.show', ['site' => $site, 'order' => $order->id]));
    }],
    'portal design is page' => ['page', function () {
        ['site' => $site] = cpWidthPortalSite();

        return cpWidthGet(route('client.portal.design', $site));
    }],
    'portal navigation is page' => ['page', function () {
        ['site' => $site] = cpWidthPortalSite();

        return cpWidthGet(route('client.portal.navigation', $site));
    }],
    'portal personalise is page' => ['page', function () {
        ['site' => $site] = cpWidthPortalSite();

        return cpWidthGet(route('client.portal.personalise', $site));
    }],
    'portal chatbot is page' => ['page', function () {
        ['site' => $site] = cpWidthPortalSite();

        return cpWidthGet(route('client.portal.chatbot', $site));
    }],
    'portal business info is page' => ['page', function () {
        ['site' => $site] = cpWidthPortalSite();

        return cpWidthGet(route('client.portal.business-info', $site));
    }],
    'portal domain is page' => ['page', function () {
        ['site' => $site] = cpWidthPortalSite();

        return cpWidthGet(route('client.portal.domain', $site));
    }],
    'portal account is page' => ['page', function () {
        cpWidthPortalSite();

        return cpWidthGet(route('client.account'));
    }],
    'portal team is page' => ['page', function () {
        cpWidthPortalSite();

        return cpWidthGet(route('client.team'));
    }],
]);
