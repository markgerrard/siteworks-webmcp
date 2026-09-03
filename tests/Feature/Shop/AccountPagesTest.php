<?php

use App\Enums\Shop\OrderStatus;
use App\Models\Shop\Customer;
use App\Models\Shop\Order;
use App\Models\Shop\OrderItem;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Site;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

/**
 * @return array{site: Site, customer: Customer, product: Product, variant: ProductVariant, homeHref: string}
 */
function accountPagesShop(): array
{
    $site = Site::factory()->create([
        'custom_domain' => 'flowers.example',
        'custom_domain_status' => 'active',
        'business_name' => 'Bloom & Stem',
        'slug' => 'bloom-stem',
    ]);
    $product = Product::factory()->published()->for($site)->create([
        'slug' => 'conserve',
        'name' => 'Strawberry Conserve',
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'sku' => 'SC-1',
        'label' => 'Jar',
        'price_cents' => 595,
    ]);
    $customer = Customer::create([
        'site_id' => $site->id,
        'email' => 'ava@example.com',
        'email_verified_at' => now(),
        'name' => 'Ava O\'Neil',
    ]);

    return [
        'site' => $site,
        'customer' => $customer,
        'product' => $product,
        'variant' => $variant,
        'homeHref' => app(PageRenderer::class)->layoutContext($site)['homeHref'],
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function accountPagesOrder(Customer $customer, Product $product, ProductVariant $variant, array $overrides = []): Order
{
    $subtotal = $overrides['subtotal_cents'] ?? 1190;
    $shipping = $overrides['shipping_cents'] ?? 350;
    $tax = $overrides['tax_cents'] ?? 198;
    $shippingTax = $overrides['shipping_tax_cents'] ?? 58;
    $qty = $overrides['qty'] ?? 2;

    $order = Order::create([
        'site_id' => $customer->site_id,
        'number' => $overrides['number'] ?? 'BLOOM-000077',
        'email' => $customer->email,
        'name' => $overrides['name'] ?? 'Ava O\'Neil',
        'customer_id' => $customer->id,
        'status' => ($overrides['status'] ?? OrderStatus::Paid)->value,
        'refund_status' => 'none',
        'subtotal_cents' => $subtotal,
        'shipping_cents' => $shipping,
        'tax_cents' => $tax,
        'shipping_tax_cents' => $shippingTax,
        'total_cents' => $overrides['total_cents'] ?? ($subtotal + $shipping),
        'tax_country_code' => $overrides['tax_country_code'] ?? 'GB',
        'shipping_address_json' => $overrides['shipping_address_json'] ?? [
            'line1' => '14 Rose Lane',
            'line2' => 'Flat 2',
            'city' => 'Lancaster',
            'postcode' => 'LA1 1AA',
            'country_code' => 'GB',
        ],
        'shipping_method_label' => 'Royal Mail 48',
        'placed_at' => $overrides['placed_at'] ?? now()->setDate(2026, 3, 14)->setTime(10, 0),
        'paid_at' => $overrides['paid_at'] ?? null,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'variant_id' => $variant->id,
        'product_id' => $product->id,
        'product_name_snapshot' => $overrides['product_name'] ?? 'Strawberry Conserve',
        'variant_label_snapshot' => 'Jar',
        'sku_snapshot' => $variant->sku,
        'qty' => $qty,
        'unit_price_cents' => 595,
        'tax_class_code' => 'standard',
        'tax_rate_percent' => 20,
        'tax_amount_cents' => 198,
        'line_total_cents' => $overrides['line_total_cents'] ?? 1190,
    ]);

    return $order;
}

function accountPagesPounds(int $cents): string
{
    return '£'.number_format($cents / 100, 2);
}

function accountPagesGet(Customer $customer, string $path): string
{
    auth('customer')->login($customer);

    return test()->get('http://flowers.example'.$path)
        ->assertOk()
        ->getContent();
}

function accountPagesMain(string $html): string
{
    preg_match('/<main\b[^>]*>(.*?)<\/main>/is', $html, $main);

    return $main[1] ?? $html;
}

/**
 * @return array{labels: list<string>, html: string}
 */
function accountPagesBreadcrumbs(string $html): array
{
    preg_match('/<nav\b[^>]*aria-label="Breadcrumb"[^>]*>(.*?)<\/nav>/is', $html, $nav);
    expect($nav)->not->toBeEmpty();

    preg_match_all('/<li\b([^>]*)>(.*?)<\/li>/is', $nav[1], $matches, PREG_SET_ORDER);
    $labels = [];
    foreach ($matches as $match) {
        $labels[] = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5));
    }

    $last = $matches[array_key_last($matches)];
    expect($last[1])->toContain('aria-current="page"')
        ->and($last[2])->not->toMatch('/<a\b/i');

    return ['labels' => $labels, 'html' => $nav[1]];
}

test('the account dashboard has one h1, the account trail, and csrf on sign-out', function () {
    ['customer' => $customer, 'homeHref' => $homeHref] = accountPagesShop();

    $html = accountPagesGet($customer, '/shop/account');
    $main = accountPagesMain($html);

    preg_match_all('/<h1\b/i', $main, $headings);
    expect($headings[0])->toHaveCount(1);

    $crumbs = accountPagesBreadcrumbs($main);
    expect($crumbs['labels'])->toBe(['Home', 'Shop', 'Account']);

    preg_match('/<a\b[^>]*href="([^"]*)"[^>]*>\s*Home\s*<\/a>/i', $crumbs['html'], $home);
    expect(parse_url(html_entity_decode($home[1], ENT_QUOTES | ENT_HTML5), PHP_URL_PATH) ?? $home[1])
        ->toBe(parse_url($homeHref, PHP_URL_PATH) ?? $homeHref);

    expect($main)->toMatch('/href="[^"]*\/shop\/account\/orders"/')
        ->and($main)->toMatch('/href="[^"]*\/shop\/account\/settings"/')
        ->and($main)->not->toContain('text-blue-600')
        ->and($main)->not->toContain('text-gray-500');

    preg_match('#<form\b[^>]*action="[^"]*/shop/account/logout"[^>]*>(.*?)</form>#s', $main, $logout);
    expect($logout)->not->toBeEmpty();
    expect($logout[1])->toMatch('/name="_token"/')
        ->and($logout[1])->toMatch('/value="[^"]+"/');
});

test('an account with no orders renders the empty state instead of a blank list', function () {
    ['customer' => $customer] = accountPagesShop();

    $html = accountPagesGet($customer, '/shop/account/orders');
    $main = accountPagesMain($html);

    preg_match_all('/<h1\b/i', $main, $headings);
    expect($headings[0])->toHaveCount(1);

    $crumbs = accountPagesBreadcrumbs($main);
    expect($crumbs['labels'])->toBe(['Home', 'Shop', 'Account', 'Orders']);

    expect($main)->toMatch('/no orders/i')
        ->and($main)->toMatch('/href="[^"]*\/shop"/')
        ->and($main)->not->toContain('BLOOM-')
        ->and(strtolower($main))->not->toContain('sorry');
});

test('the orders list shows independently computed totals and a status pill, not the raw enum', function () {
    ['customer' => $customer, 'product' => $product, 'variant' => $variant] = accountPagesShop();
    $order = accountPagesOrder($customer, $product, $variant, [
        'number' => 'BLOOM-000077',
        'total_cents' => 1540,
        'status' => OrderStatus::Paid,
    ]);
    $expectedTotal = accountPagesPounds($order->total_cents);

    $html = accountPagesGet($customer, '/shop/account/orders');
    $main = accountPagesMain($html);

    expect($main)->toContain($order->number)
        ->and($main)->toContain($expectedTotal)
        ->and($main)->toMatch('/--color-surface-alt[^"]*"[^>]*>\s*Paid\s*</')
        ->and($main)->toMatch('/href="[^"]*\/shop\/account\/orders\/'.$order->id.'"/')
        ->and($main)->not->toContain('text-gray-500');

    $text = html_entity_decode(strip_tags($main), ENT_QUOTES | ENT_HTML5);
    expect($text)->not->toMatch('/\bpaid\b/')
        ->and($expectedTotal)->not->toBe(accountPagesPounds(0));
});

test('order detail shows items, independently computed totals, the shipping address, and a status pill', function () {
    ['customer' => $customer, 'product' => $product, 'variant' => $variant] = accountPagesShop();
    $order = accountPagesOrder($customer, $product, $variant, [
        'number' => 'BLOOM-000077',
        'subtotal_cents' => 1190,
        'shipping_cents' => 350,
        'tax_cents' => 198,
        'shipping_tax_cents' => 58,
        'total_cents' => 1540,
        'qty' => 2,
        'line_total_cents' => 1190,
        'product_name' => 'Strawberry Conserve',
        'shipping_address_json' => [
            'line1' => '14 Rose Lane',
            'line2' => 'Flat 2',
            'city' => 'Lancaster',
            'postcode' => 'LA1 1AA',
            'country_code' => 'GB',
        ],
    ]);
    $vat = $order->tax_cents + $order->shipping_tax_cents;

    $html = accountPagesGet($customer, '/shop/account/orders/'.$order->id);
    $main = accountPagesMain($html);

    preg_match_all('/<h1\b/i', $main, $headings);
    expect($headings[0])->toHaveCount(1);

    $crumbs = accountPagesBreadcrumbs($main);
    expect($crumbs['labels'])->toBe(['Home', 'Shop', 'Account', 'Orders', $order->number]);

    expect($main)->toContain('Strawberry Conserve')
        ->and($main)->toContain('Jar')
        ->and($main)->toContain('× 2')
        ->and($main)->toContain(accountPagesPounds($order->items->first()->line_total_cents))
        ->and($main)->toContain(accountPagesPounds($order->subtotal_cents))
        ->and($main)->toContain(accountPagesPounds($order->shipping_cents))
        ->and($main)->toContain(accountPagesPounds($vat))
        ->and($main)->toContain(accountPagesPounds($order->total_cents))
        ->and($main)->toContain('14 Rose Lane')
        ->and($main)->toContain('Flat 2')
        ->and($main)->toContain('Lancaster')
        ->and($main)->toContain('LA1 1AA')
        ->and($main)->toMatch('/--color-surface-alt[^"]*"[^>]*>\s*Paid\s*</');

    expect($order->total_cents)->toBe($order->subtotal_cents + $order->shipping_cents)
        ->and($vat)->toBeGreaterThan(0)
        ->and($main)->not->toContain(accountPagesPounds($order->total_cents + $vat))
        ->and($main)->toContain('VAT')
        ->and($main)->not->toContain('>Tax<');
});

test('a USD account order omits the tax line and notes that no sales tax applies', function () {
    ['customer' => $customer, 'product' => $product, 'variant' => $variant, 'site' => $site] = accountPagesShop();
    $site->update(['shop_currency' => 'USD']);
    $order = accountPagesOrder($customer, $product, $variant, [
        'number' => 'CAMINO-0001',
        'tax_cents' => 0,
        'shipping_tax_cents' => 0,
        'tax_country_code' => 'US',
    ]);

    $html = accountPagesGet($customer, '/shop/account/orders/'.$order->id);
    $main = accountPagesMain($html);

    expect($main)->toContain('data-testid="checkout-tax-note"')
        ->and($main)->toContain('No sales tax applied.')
        ->and($main)->not->toContain('data-testid="checkout-vat"')
        ->and($main)->not->toContain('VAT')
        ->and($main)->not->toContain('>Tax<');
});

test('order detail renders a status timeline with future steps greyed', function () {
    ['customer' => $customer, 'product' => $product, 'variant' => $variant] = accountPagesShop();
    $order = accountPagesOrder($customer, $product, $variant, [
        'placed_at' => now()->setDate(2026, 3, 14)->setTime(10, 0),
        'paid_at' => now()->setDate(2026, 3, 14)->setTime(10, 5),
    ]);

    $html = accountPagesGet($customer, '/shop/account/orders/'.$order->id);
    $main = accountPagesMain($html);

    expect($main)->toContain('Placed')
        ->and($main)->toContain('Paid')
        ->and($main)->toContain('Dispatched')
        ->and($main)->toContain('Refunded')
        ->and($main)->toContain('14 Mar 2026')
        ->and($main)->toMatch('/data-timeline-step="placed"[^>]*data-done="1"/')
        ->and($main)->toMatch('/data-timeline-step="paid"[^>]*data-done="1"/')
        ->and($main)->toMatch('/data-timeline-step="dispatched"[^>]*data-done="0"/')
        ->and($main)->toMatch('/data-timeline-step="refunded"[^>]*data-done="0"/')
        ->and($main)->toMatch('/data-timeline-step="dispatched"[^>]*style="[^"]*--color-text-muted/');
});

test('account settings is a coherent page with one h1 and an export entry, not a download-export page of its own', function () {
    ['customer' => $customer] = accountPagesShop();

    $html = accountPagesGet($customer, '/shop/account/settings');
    $main = accountPagesMain($html);

    preg_match_all('/<h1\b/i', $main, $headings);
    expect($headings[0])->toHaveCount(1);

    $crumbs = accountPagesBreadcrumbs($main);
    expect($crumbs['labels'])->toBe(['Home', 'Shop', 'Account', 'Settings']);

    expect($main)->toMatch('/wire:click="requestExport"/')
        ->and($main)->toMatch('/email|inbox|download link/i')
        ->and($main)->not->toMatch('/href="[^"]*\/shop\/account\/download-export"/');
});

test('an expired magic link says what happened, offers a new link, and does not reveal whether the address is registered', function () {
    accountPagesShop();

    $html = test()->get('http://flowers.example/shop/account/verify?token=not-a-real-token')
        ->assertOk()
        ->getContent();
    $main = accountPagesMain($html);

    preg_match_all('/<h1\b/i', $main, $headings);
    expect($headings[0])->toHaveCount(1);

    $crumbs = accountPagesBreadcrumbs($main);
    expect($crumbs['labels'])->toBe(['Home', 'Shop', 'Account', 'Sign in']);

    $text = strtolower(html_entity_decode(strip_tags($main), ENT_QUOTES | ENT_HTML5));
    expect($text)->toMatch('/expired|already been used|no longer valid/')
        ->and($main)->toMatch('/href="[^"]*\/shop\/account\/login"/')
        ->and($text)->not->toContain('registered')
        ->and($text)->not->toContain('no account')
        ->and($text)->not->toContain('unknown')
        ->and($text)->not->toContain('not found')
        ->and($main)->not->toContain('text-blue-600');
});

test('claim-sent has an h1 and does not leak account existence', function () {
    Mail::fake();
    accountPagesShop();

    URL::forceRootUrl('http://flowers.example');
    $signed = URL::temporarySignedRoute(
        'shop.account.claim',
        now()->addHour(),
        ['email' => 'new-buyer@example.com'],
    );

    $html = test()->get($signed)->assertOk()->getContent();
    $main = accountPagesMain($html);

    preg_match_all('/<h1\b/i', $main, $headings);
    expect($headings[0])->toHaveCount(1);

    $crumbs = accountPagesBreadcrumbs($main);
    expect($crumbs['labels'])->toBe(['Home', 'Shop', 'Account']);

    $text = strtolower(html_entity_decode(strip_tags($main), ENT_QUOTES | ENT_HTML5));
    expect($text)->toMatch('/inbox|email|sign-in link|sign in link/')
        ->and($text)->not->toContain('registered')
        ->and($text)->not->toContain('no account');
});
