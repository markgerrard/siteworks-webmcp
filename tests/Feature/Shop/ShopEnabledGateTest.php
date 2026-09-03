<?php

use App\Enums\PageStatus;
use App\Enums\PreviewLayout;
use App\Enums\Shop\OrderStatus;
use App\Enums\Shop\ProductStatus;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Client;
use App\Models\GeneratedPage;
use App\Models\Shop\Customer;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Models\User;
use App\Services\Shop\SnapshotBuilder;
use Illuminate\Support\Facades\Mail;

/**
 * Catalogue that would be purchasable if the flag were on.
 *
 * @return array{0: Site, 1: Product}
 */
function shopFlagCatalogue(string $host, bool $shopEnabled): array
{
    $site = Site::factory()
        ->{$shopEnabled ? 'shopEnabled' : 'shopDisabled'}()
        ->create([
            'custom_domain' => $host,
            'custom_domain_status' => 'active',
            'preview_layout' => PreviewLayout::MultiPage,
        ]);
    $product = Product::factory()->published()->for($site)->create(['status' => ProductStatus::Published]);
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 900]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 3]);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));

    return [$site, $product];
}

function shopFlagPaidOrder(Site $site, string $number = 'FLG-0001'): Order
{
    return Order::create([
        'site_id' => $site->id,
        'number' => $number,
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
}

test('hasPurchasableShop is false when the flag is off even with products; hasEstablishedShop stays true once orders exist', function () {
    [$site] = shopFlagCatalogue('flag-off-pred.example', shopEnabled: false);
    shopFlagPaidOrder($site);

    expect($site->fresh()->shopEnabled())->toBeFalse()
        ->and($site->fresh()->hasPurchasableShop())->toBeFalse()
        ->and($site->fresh()->hasEstablishedShop())->toBeTrue();

    $site->update(['shop_enabled' => true]);

    expect($site->fresh()->hasPurchasableShop())->toBeTrue()
        ->and($site->fresh()->hasEstablishedShop())->toBeTrue();
});

test('ShopDomainResolver 404s public shop routes when the flag is off and creates no customer or mail', function () {
    Mail::fake();
    [$site] = shopFlagCatalogue('flag-off-domain.example', shopEnabled: false);

    foreach (['/shop', '/shop/cart', '/shop/checkout', '/shop/account/login'] as $path) {
        $this->get('http://flag-off-domain.example'.$path)
            ->assertNotFound("{$path} must 404 when shop_enabled is off");
    }

    $this->post('http://flag-off-domain.example/shop/account/login', [
        'email' => 'attacker@example.com',
    ])->assertNotFound();

    expect(Customer::query()->where('site_id', $site->id)->exists())->toBeFalse();
    Mail::assertNothingSent();
});

test('ShopDomainResolver still serves a flag-on catalogue', function () {
    shopFlagCatalogue('flag-on-domain.example', shopEnabled: true);

    $this->get('http://flag-on-domain.example/shop')->assertOk();
    $this->get('http://flag-on-domain.example/shop/account/login')->assertOk();
});

test('sitemap omits shop URLs when the flag is off even with an established catalogue', function () {
    config(['site.use_versioned_renderer' => true]);
    [$site] = shopFlagCatalogue('flag-off-map.example', shopEnabled: false);

    $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'status' => PageStatus::Published]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Home']]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);
    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'],
            'homepage_page_id' => $home->id,
        ],
        'page_revisions' => [['page_id' => $home->id, 'revision_id' => $homeRev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::query()->updateOrCreate(
        ['site_id' => $site->id],
        ['version_id' => $version->id, 'updated_at' => now()],
    );

    $xml = (string) $this->get('http://flag-off-map.example/sitemap.xml')->assertSuccessful()->getContent();

    expect($xml)->not->toContain('/shop');
});

test('client portal shop 404s when the flag is off but orders stay reachable for a site with orders', function () {
    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    [$site, $product] = shopFlagCatalogue('flag-off-portal.example', shopEnabled: false);
    $site->update(['client_id' => $tenant->id]);
    $order = shopFlagPaidOrder($site);

    $this->actingAs($client)
        ->get(route('client.portal.shop', $site))
        ->assertNotFound();
    $this->actingAs($client)
        ->get(route('client.portal.shop.products.edit', ['site' => $site, 'product' => $product->id]))
        ->assertNotFound();
    // Orders are owed after payment: a site that has taken an order keeps them, flag or not.
    $this->actingAs($client)
        ->get(route('client.portal.orders', $site))
        ->assertOk();
    $this->actingAs($client)
        ->get(route('client.portal.orders.show', ['site' => $site, 'order' => $order->id]))
        ->assertOk();
});

test('client portal sidebar omits Shop but keeps Orders for an ordered site when the flag is off', function () {
    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    [$site] = shopFlagCatalogue('flag-off-sidebar.example', shopEnabled: false);
    $site->update(['client_id' => $tenant->id]);
    shopFlagPaidOrder($site);

    $html = $this->actingAs($client)
        ->get(route('client.portal.site', $site))
        ->assertOk()
        ->getContent();

    // Shop goes; Orders stays because the site has taken an order (owed after payment).
    expect($html)->not->toContain(route('client.portal.shop.products', $site))
        ->and($html)->not->toContain(route('client.portal.shop', $site))
        ->and($html)->toContain(route('client.portal.orders', $site));
});

test('a paid-order site with the flag off keeps account orders reachable and 404s browse', function () {
    [$site] = shopFlagCatalogue('flag-owed.example', shopEnabled: true);
    $order = shopFlagPaidOrder($site);
    $customer = Customer::create([
        'site_id' => $site->id,
        'email' => 'buyer@example.com',
        'email_verified_at' => now(),
        'name' => 'Buyer',
    ]);
    $order->update(['customer_id' => $customer->id]);
    $site->update(['shop_enabled' => false]);

    $this->get('http://flag-owed.example/shop')->assertNotFound();

    auth('customer')->login($customer);
    $this->get('http://flag-owed.example/shop/account/orders')->assertOk();
});
