<?php

use App\Enums\Shop\OrderStatus;
use App\Http\Controllers\Shop\CartController;
use App\Models\Shop\Customer;
use App\Models\Shop\CustomerAddress;
use App\Models\Shop\CustomerMagicLink;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShippingRate;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Services\Shop\CartService;
use App\Services\Shop\CustomerAddressService;
use App\Services\Shop\StripeService;
use Database\Seeders\Shop\TaxClassSeeder;
use Database\Seeders\Shop\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $siteAttrs
 * @return array{site: Site, customer: Customer, sessionId: string, host: string}
 */
function signedInCheckoutCart(string $host = 'flowers.example', array $siteAttrs = []): array
{
    test()->seed(TaxClassSeeder::class);
    test()->seed(TaxRateSeeder::class);

    $site = Site::factory()->create(array_merge([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
    ], $siteAttrs));
    ShippingRate::create([
        'site_id' => $site->id,
        'strategy' => 'flat_with_free_threshold',
        'flat_amount_cents' => 350,
        'free_threshold_cents' => null,
        'method_label' => 'Royal Mail 48',
    ]);
    $product = Product::factory()->published()->for($site)->create(['name' => 'Strawberry Conserve']);
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 595, 'label' => 'Jar']);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 10]);

    $customer = Customer::create([
        'site_id' => $site->id,
        'email' => 'ava@example.com',
        'name' => 'Ava O\'Neil',
        'email_verified_at' => now(),
    ]);

    $sessionId = 'signed-in-checkout';
    $cart = app(CartService::class)->getOrCreate($site->id, $sessionId);
    app(CartService::class)->addItem($cart, $variant->id, 1);

    return compact('site', 'customer', 'sessionId', 'host');
}

function fakeShopStripeRedirect(): void
{
    $client = new class
    {
        public object $checkout;

        public function __construct()
        {
            $this->checkout = new class
            {
                public object $sessions;

                public function __construct()
                {
                    $this->sessions = new class
                    {
                        public function create(array $params): object
                        {
                            return (object) ['id' => 'cs_test', 'url' => 'https://stripe.test/pay'];
                        }
                    };
                }
            };
        }
    };

    app()->instance(StripeService::class, new StripeService($client));
}

/**
 * @return array<string, string>
 */
function signedInCheckoutFields(string $html): array
{
    preg_match('#<form\b[^>]*action="[^"]*/shop/checkout/start"[^>]*>(.*?)</form>#s', $html, $form);
    expect($form)->not->toBeEmpty();

    $fields = [];
    preg_match_all('#<(input|textarea)\b[^>]*>#i', $form[1], $inputs);
    foreach ($inputs[0] as $input) {
        if (! preg_match('#\bname="([^"]+)"#', $input, $name)) {
            continue;
        }
        preg_match('#\bvalue="([^"]*)"#', $input, $value);
        $fields[$name[1]] = html_entity_decode($value[1] ?? '', ENT_QUOTES | ENT_HTML5);
    }

    return $fields;
}

test('checkout pre-fills from the default shipping address', function () {
    ['customer' => $customer, 'sessionId' => $sessionId, 'host' => $host] = signedInCheckoutCart();
    app(CustomerAddressService::class)->create($customer, [
        'label' => 'Home',
        'name' => 'Ava O\'Neil',
        'phone' => '01234 567890',
        'line1' => '14 Rose Lane',
        'line2' => 'Flat 2',
        'city' => 'Lancaster',
        'region' => 'Lancashire',
        'postcode' => 'LA1 1AA',
        'country_code' => 'GB',
        'is_default_shipping' => true,
    ]);
    auth('customer')->login($customer);

    $html = $this->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->get("http://{$host}/shop/checkout")
        ->assertOk()
        ->getContent();

    $fields = signedInCheckoutFields($html);
    expect($fields['name'])->toBe('Ava O\'Neil')
        ->and($fields['email'])->toBe('ava@example.com')
        ->and($fields['phone'])->toBe('01234 567890')
        ->and($fields['line1'])->toBe('14 Rose Lane')
        ->and($fields['line2'])->toBe('Flat 2')
        ->and($fields['city'])->toBe('Lancaster')
        ->and($fields['postcode'])->toBe('LA1 1AA')
        ->and($fields['country_code'])->toBe('GB');

    expect($html)->toContain('Use a different address')
        ->and($html)->toMatch('/<details\b/i')
        ->and($html)->not->toContain('Have an account? Sign in for a faster checkout');
});

test('a saved address whose country differs from the site prefills except country', function () {
    ['customer' => $customer, 'sessionId' => $sessionId, 'host' => $host] = signedInCheckoutCart('camino-prefill.example', [
        'shop_currency' => 'USD',
        'country' => 'US',
    ]);
    app(CustomerAddressService::class)->create($customer, [
        'label' => 'Home',
        'name' => 'Ava O\'Neil',
        'phone' => '01234 567890',
        'line1' => '14 Rose Lane',
        'line2' => 'Flat 2',
        'city' => 'Lancaster',
        'region' => 'Lancashire',
        'postcode' => 'LA1 1AA',
        'country_code' => 'FR',
        'is_default_shipping' => true,
    ]);
    auth('customer')->login($customer);

    $html = $this->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->get("http://{$host}/shop/checkout")
        ->assertOk()
        ->getContent();

    $fields = signedInCheckoutFields($html);
    expect($fields['name'])->toBe('Ava O\'Neil')
        ->and($fields['email'])->toBe('ava@example.com')
        ->and($fields['phone'])->toBe('01234 567890')
        ->and($fields['line1'])->toBe('14 Rose Lane')
        ->and($fields['line2'])->toBe('Flat 2')
        ->and($fields['city'])->toBe('Lancaster')
        ->and($fields['postcode'])->toBe('LA1 1AA')
        ->and($fields['country_code'])->toBe('US');

    expect($html)->toMatch('/name="country_code"[^>]*\breadonly\b|\breadonly\b[^>]*name="country_code"/i');
});

test('checkout pre-fills from the most recent order when no default address exists', function () {
    ['site' => $site, 'customer' => $customer, 'sessionId' => $sessionId, 'host' => $host] = signedInCheckoutCart();
    Order::create([
        'site_id' => $site->id,
        'number' => 'BLOOM-000099',
        'email' => $customer->email,
        'name' => 'Ava O\'Neil',
        'customer_id' => $customer->id,
        'status' => OrderStatus::Paid->value,
        'refund_status' => 'none',
        'subtotal_cents' => 1000,
        'shipping_cents' => 0,
        'tax_cents' => 0,
        'shipping_tax_cents' => 0,
        'total_cents' => 1000,
        'tax_country_code' => 'GB',
        'shipping_address_json' => [
            'name' => 'Ava O\'Neil',
            'email' => $customer->email,
            'phone' => '07000 111222',
            'line1' => '88 Canal Side',
            'city' => 'Preston',
            'postcode' => 'PR1 1AA',
            'country_code' => 'GB',
        ],
        'shipping_method_label' => 'Std',
        'placed_at' => now()->subDay(),
    ]);
    auth('customer')->login($customer);

    $html = $this->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->get("http://{$host}/shop/checkout")
        ->assertOk()
        ->getContent();

    $fields = signedInCheckoutFields($html);
    expect($fields['line1'])->toBe('88 Canal Side')
        ->and($fields['city'])->toBe('Preston')
        ->and($fields['postcode'])->toBe('PR1 1AA')
        ->and($fields['email'])->toBe('ava@example.com');
});

test('save-this-address is checked when no default exists and creates an address on start', function () {
    ['customer' => $customer, 'sessionId' => $sessionId, 'host' => $host] = signedInCheckoutCart();
    auth('customer')->login($customer);
    fakeShopStripeRedirect();

    $html = $this->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->get("http://{$host}/shop/checkout")
        ->assertOk()
        ->getContent();

    expect($html)->toMatch('/name="save_address"[^>]*\bchecked\b|\bchecked\b[^>]*name="save_address"/i');

    $this->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->post("http://{$host}/shop/checkout/start", [
            'name' => 'Ava O\'Neil',
            'email' => 'ava@example.com',
            'phone' => '01234 567890',
            'line1' => '14 Rose Lane',
            'line2' => 'Flat 2',
            'city' => 'Lancaster',
            'postcode' => 'LA1 1AA',
            'country_code' => 'GB',
            'save_address' => '1',
        ])
        ->assertRedirect('https://stripe.test/pay');

    $address = CustomerAddress::query()->sole();
    expect($address->customer_id)->toBe($customer->id)
        ->and($address->line1)->toBe('14 Rose Lane')
        ->and($address->is_default_shipping)->toBeTrue();
});

test('a signed-in checkout order links to the session customer even if a different email is posted', function () {
    ['customer' => $customer, 'sessionId' => $sessionId, 'host' => $host] = signedInCheckoutCart();
    $other = Customer::create([
        'site_id' => $customer->site_id,
        'email' => 'other@example.com',
        'email_verified_at' => now(),
    ]);
    auth('customer')->login($customer);
    fakeShopStripeRedirect();

    $this->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->post("http://{$host}/shop/checkout/start", [
            'name' => 'Someone Else',
            'email' => 'other@example.com',
            'line1' => '1 High St',
            'city' => 'Lancaster',
            'postcode' => 'LA1 1AA',
            'country_code' => 'GB',
        ])
        ->assertRedirect('https://stripe.test/pay');

    $order = Order::query()->latest('id')->first();
    expect($order->customer_id)->toBe($customer->id)
        ->and($order->customer_id)->not->toBe($other->id)
        ->and($order->email)->toBe('other@example.com');
});

test('magic-link verify honours a same-origin /shop/ return path and rejects open redirects', function () {
    Mail::fake();
    ['host' => $host] = signedInCheckoutCart();

    $this->post("http://{$host}/shop/account/login", [
        'email' => 'buyer@example.com',
        'return' => '/shop/checkout',
    ])->assertRedirect();

    $customer = Customer::where('email', 'buyer@example.com')->firstOrFail();
    $link = CustomerMagicLink::where('customer_id', $customer->id)->firstOrFail();
    $raw = Cache::get("magic_link_raw_{$link->id}");

    $this->get("http://{$host}/shop/account/verify?token={$raw}&return=/shop/checkout")
        ->assertRedirect('/shop/checkout');
});

test('magic-link verify ignores return paths that are not relative /shop/ URLs', function () {
    Mail::fake();
    ['site' => $site, 'host' => $host] = signedInCheckoutCart();
    $svc = app(\App\Services\Shop\CustomerAuthService::class);

    foreach (['https://evil.example/phish', '//evil.example/shop/checkout', '/admin', '/shop'] as $i => $unsafe) {
        $customer = $svc->requestLinkFor($site->id, "buyer-{$i}@example.com");
        $link = CustomerMagicLink::where('customer_id', $customer->id)->firstOrFail();
        $raw = Cache::get("magic_link_raw_{$link->id}");

        $this->get("http://{$host}/shop/account/verify?token={$raw}&return=".urlencode($unsafe))
            ->assertRedirect('/shop/account');

        auth('customer')->logout();
    }
});
