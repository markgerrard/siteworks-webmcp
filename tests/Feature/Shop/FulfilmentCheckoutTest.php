<?php

use App\Http\Controllers\Shop\CartController;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShippingRate;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Services\Shop\CartService;
use App\Services\Shop\StripeService;
use Database\Seeders\Shop\TaxClassSeeder;
use Database\Seeders\Shop\TaxRateSeeder;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FulfilmentFixtures;

/**
 * @return array{site: Site, host: string, sessionId: string, variant: ProductVariant}
 */
function fulfilmentCheckoutSite(string $host, array $fulfilment, int $priceCents = 595, int $qty = 1): array
{
    test()->seed(TaxClassSeeder::class);
    test()->seed(TaxRateSeeder::class);

    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'shop_currency' => 'GBP',
        'fulfilment' => $fulfilment,
    ]);

    ShippingRate::create([
        'site_id' => $site->id,
        'strategy' => 'flat_with_free_threshold',
        'flat_amount_cents' => 350,
        'free_threshold_cents' => null,
        'method_label' => 'Royal Mail 48',
    ]);

    $product = Product::factory()->published()->for($site)->create(['name' => 'Sourdough loaf']);
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => $priceCents, 'label' => 'Std']);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 50]);

    $sessionId = 'fulfilment-checkout-'.$host;
    $cart = app(CartService::class)->getOrCreate($site->id, $sessionId);
    app(CartService::class)->addItem($cart, $variant->id, $qty);

    return compact('site', 'host', 'sessionId', 'variant');
}

function fulfilmentCheckoutGet(string $host, string $sessionId, string $query = '')
{
    $url = 'http://'.$host.'/shop/checkout'.$query;

    return test()->withCookie(CartController::COOKIE_NAME, $sessionId)->get($url);
}

function fulfilmentCheckoutStart(string $host, string $sessionId, array $extra = [])
{
    return test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->post('http://'.$host.'/shop/checkout/start', array_merge([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'phone' => '01234 567890',
            'line1' => '1 High Street',
            'line2' => '',
            'city' => 'London',
            'postcode' => 'SW1A 1AA',
            'country_code' => 'GB',
        ], $extra));
}

function fulfilmentCheckoutFakeStripeCapture(): object
{
    $sessions = new class
    {
        public ?array $params = null;

        public function create(array $params): object
        {
            $this->params = $params;

            return (object) ['id' => 'cs_test', 'url' => 'https://stripe.test/pay'];
        }
    };
    $client = new class($sessions)
    {
        public object $checkout;

        public function __construct(object $sessions)
        {
            $this->checkout = new class($sessions)
            {
                public function __construct(public object $sessions) {}
            };
        }
    };
    app()->instance(StripeService::class, new StripeService($client));

    return $sessions;
}

function fulfilmentCheckoutTotalCents(string $html): int
{
    preg_match('/data-testid="checkout-total"[^>]*>(.*?)<\/div>/s', $html, $block);
    expect($block)->not->toBeEmpty();
    preg_match('/£(\d+\.\d{2})/', $block[1], $amount);
    expect($amount)->not->toBeEmpty();

    return (int) round((float) $amount[1] * 100);
}

function fulfilmentCheckoutStripeAmount(?array $params): ?int
{
    return $params['line_items'][0]['price_data']['unit_amount'] ?? null;
}

it('adds nullable fulfilment columns on shop_orders', function () {
    expect(Schema::hasColumns('shop_orders', [
        'fulfilment_method',
        'fulfilment_zone_name',
        'fulfilment_fee_cents',
        'fulfilment_postcode',
    ]))->toBeTrue();
});

test('checkout without fulfilment config has no method selector and still uses T19 shipping', function () {
    ['host' => $host, 'sessionId' => $sessionId] = fulfilmentCheckoutSite('co-none.example', []);
    Site::query()->where('custom_domain', $host)->update(['fulfilment' => null]);

    $html = fulfilmentCheckoutGet($host, $sessionId)->assertOk()->getContent();

    expect($html)->not->toContain('data-testid="fulfilment-method-form"')
        ->and($html)->toContain('data-testid="checkout-shipping"')
        ->and($html)->toContain('Royal Mail 48');
});

test('checkout lists only enabled methods and preselects delivery after a zone hit', function () {
    ['host' => $host, 'sessionId' => $sessionId] = fulfilmentCheckoutSite('co-camino.example', FulfilmentFixtures::camino());

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->from('http://'.$host.'/shop/checkout')
        ->get('http://'.$host.'/shop/fulfilment/check?postcode=SW1A1AA');

    $html = html_entity_decode(fulfilmentCheckoutGet($host, $sessionId)->assertOk()->getContent(), ENT_QUOTES | ENT_HTML5);

    expect($html)->toContain('data-testid="fulfilment-method-form"')
        ->and($html)->toContain('Local delivery')
        ->and($html)->toContain('Click & collect')
        ->and($html)->not->toContain('value="shipping"')
        ->and($html)->toContain('£4.00');
});

test('the fulfilment method form stays usable without JavaScript and changes the remembered-zone fee', function () {
    ['host' => $host, 'sessionId' => $sessionId] = fulfilmentCheckoutSite('co-method-update.example', FulfilmentFixtures::camino());

    $delivery = html_entity_decode(
        fulfilmentCheckoutGet($host, $sessionId, '?postcode=SW1A%201AA&fulfilment_method=delivery')->assertOk()->getContent(),
        ENT_QUOTES | ENT_HTML5,
    );
    $collect = html_entity_decode(
        fulfilmentCheckoutGet($host, $sessionId, '?postcode=SW1A%201AA&fulfilment_method=collect')->assertOk()->getContent(),
        ENT_QUOTES | ENT_HTML5,
    );

    expect($delivery)->toContain('data-testid="fulfilment-method-update"')
        ->and($delivery)->toMatch('/<button(?=[^>]*data-testid="fulfilment-method-update")(?=[^>]*type="submit")(?![^>]*\\bx-cloak\\b)(?![^>]*\\bhidden\\b)[^>]*>Update<\\/button>/')
        ->and(fulfilmentCheckoutTotalCents($collect))->not->toBe(fulfilmentCheckoutTotalCents($delivery))
        ->and($collect)->not->toContain('data-testid="checkout-shipping"')
        ->and($collect)->toContain('data-testid="checkout-collect-address"');
});

test('delivery fee is the zone fee and free_over zeros it', function () {
    ['host' => $host, 'sessionId' => $sessionId] = fulfilmentCheckoutSite(
        'co-free.example',
        FulfilmentFixtures::camino(),
        priceCents: 595,
        qty: 7,
    );

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->from('http://'.$host.'/shop/checkout')
        ->get('http://'.$host.'/shop/fulfilment/check?postcode=SW1A1AA');

    $html = fulfilmentCheckoutGet($host, $sessionId, '?fulfilment_method=delivery')->assertOk()->getContent();
    expect($html)->toContain('Free');

    $sessions = fulfilmentCheckoutFakeStripeCapture();
    fulfilmentCheckoutStart($host, $sessionId, [
        'fulfilment_method' => 'delivery',
        'postcode' => 'SW1A 1AA',
        'fee_cents' => 1,
    ])->assertRedirect('https://stripe.test/pay');

    $order = Order::query()->first();
    expect($order->shipping_cents)->toBe(0)
        ->and($order->fulfilment_method)->toBe('delivery')
        ->and($order->fulfilment_zone_name)->toBe('Inner')
        ->and($order->fulfilment_fee_cents)->toBe(0)
        ->and($order->fulfilment_postcode)->toBe('SW1A1AA')
        ->and(fulfilmentCheckoutStripeAmount($sessions->params))->toBe($order->total_cents)
        ->and(fulfilmentCheckoutStripeAmount($sessions->params))->toBe(fulfilmentCheckoutTotalCents($html));
});

test('min_order is enforced with an inline error and no order is created', function () {
    ['host' => $host, 'sessionId' => $sessionId] = fulfilmentCheckoutSite('co-min.example', FulfilmentFixtures::camino());
    $sessions = fulfilmentCheckoutFakeStripeCapture();
    $reason = 'Minimum order £15.00 for delivery to Outer';

    fulfilmentCheckoutGet($host, $sessionId, '?postcode=SW2%201AA&fulfilment_method=delivery')
        ->assertOk()
        ->assertSee($reason);

    fulfilmentCheckoutStart($host, $sessionId, [
        'fulfilment_method' => 'delivery',
        'postcode' => 'SW2 1AA',
    ])->assertRedirect()->assertSessionHasErrors([
        'fulfilment_method' => $reason,
    ]);

    expect(Order::query()->exists())->toBeFalse()
        ->and($sessions->params)->toBeNull();
});

test('collect hides the shipping line, shows the collect address, and stores the method', function () {
    ['host' => $host, 'sessionId' => $sessionId] = fulfilmentCheckoutSite('co-collect.example', FulfilmentFixtures::camino());

    $html = fulfilmentCheckoutGet($host, $sessionId, '?fulfilment_method=collect')->assertOk()->getContent();
    expect($html)->not->toContain('data-testid="checkout-shipping"')
        ->and($html)->toContain('data-testid="checkout-collect-address"')
        ->and($html)->toContain('12 High Street');

    $sessions = fulfilmentCheckoutFakeStripeCapture();
    fulfilmentCheckoutStart($host, $sessionId, [
        'fulfilment_method' => 'collect',
        'postcode' => 'SW1A 1AA',
    ])->assertRedirect('https://stripe.test/pay');

    $order = Order::query()->first();
    expect($order->shipping_cents)->toBe(0)
        ->and($order->fulfilment_method)->toBe('collect')
        ->and($order->fulfilment_zone_name)->toBeNull()
        ->and($order->fulfilment_fee_cents)->toBe(0)
        ->and(fulfilmentCheckoutStripeAmount($sessions->params))->toBe($order->total_cents)
        ->and(fulfilmentCheckoutStripeAmount($sessions->params))->toBe(fulfilmentCheckoutTotalCents($html));
});

test('nationwide shipping uses the T19 rate', function () {
    ['host' => $host, 'sessionId' => $sessionId] = fulfilmentCheckoutSite('co-ship.example', FulfilmentFixtures::florist());

    $html = fulfilmentCheckoutGet($host, $sessionId, '?fulfilment_method=shipping')->assertOk()->getContent();
    expect($html)->toContain('£3.50');

    $sessions = fulfilmentCheckoutFakeStripeCapture();
    fulfilmentCheckoutStart($host, $sessionId, [
        'fulfilment_method' => 'shipping',
        'postcode' => 'M1 1AA',
    ])->assertRedirect('https://stripe.test/pay');

    $order = Order::query()->first();
    expect($order->shipping_cents)->toBe(350)
        ->and(fulfilmentCheckoutStripeAmount($sessions->params))->toBe($order->total_cents)
        ->and(fulfilmentCheckoutStripeAmount($sessions->params))->toBe(fulfilmentCheckoutTotalCents($html));
});

test('checkout rematches the address postcode and never trusts the session or a posted fee', function () {
    ['host' => $host, 'sessionId' => $sessionId] = fulfilmentCheckoutSite('co-reval.example', FulfilmentFixtures::camino());
    $sessions = fulfilmentCheckoutFakeStripeCapture();

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->from('http://'.$host.'/shop/checkout')
        ->get('http://'.$host.'/shop/fulfilment/check?postcode=SW1A1AA');

    $matchedHtml = fulfilmentCheckoutGet($host, $sessionId, '?fulfilment_method=delivery')->assertOk()->getContent();

    fulfilmentCheckoutStart($host, $sessionId, [
        'fulfilment_method' => 'delivery',
        'postcode' => 'M1 1AA',
        'fee_cents' => 1,
        'zone_name' => 'Inner',
    ])->assertUnprocessable()->assertSessionHasErrors([
        'postcode' => 'This postcode is not in our delivery area.',
    ]);

    expect(Order::query()->exists())->toBeFalse()
        ->and($sessions->params)->toBeNull();

    fulfilmentCheckoutStart($host, $sessionId, [
        'fulfilment_method' => 'delivery',
        'postcode' => 'SW1A 1AA',
        'fee_cents' => 1,
    ])->assertRedirect('https://stripe.test/pay');

    $order = Order::query()->first();
    expect($order->shipping_cents)->toBe(400)
        ->and($order->fulfilment_fee_cents)->toBe(400)
        ->and($order->fulfilment_zone_name)->toBe('Inner')
        ->and(fulfilmentCheckoutStripeAmount($sessions->params))->toBe($order->total_cents)
        ->and(fulfilmentCheckoutStripeAmount($sessions->params))->toBe(fulfilmentCheckoutTotalCents($matchedHtml));
});

test('delivery is disabled and the summary never quotes Free until a zone matches', function () {
    ['host' => $host, 'sessionId' => $sessionId] = fulfilmentCheckoutSite('co-pending.example', FulfilmentFixtures::camino());

    $html = html_entity_decode(
        fulfilmentCheckoutGet($host, $sessionId, '?fulfilment_method=delivery')->assertOk()->getContent(),
        ENT_QUOTES | ENT_HTML5,
    );

    expect($html)->toContain('Enter your postcode to see delivery')
        ->and($html)->toMatch('/name="fulfilment_method"[^>]*value="delivery"[^>]*\bdisabled\b|\bdisabled\b[^>]*name="fulfilment_method"[^>]*value="delivery"|value="delivery"[^>]*\bdisabled\b/');

    preg_match('/data-testid="checkout-shipping"[^>]*>(.*?)<\/div>/s', $html, $shipping);
    if ($shipping !== []) {
        expect($shipping[1])->not->toContain('Free')
            ->and($shipping[1])->not->toContain('£0');
    }

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->from('http://'.$host.'/shop/checkout')
        ->get('http://'.$host.'/shop/fulfilment/check?postcode=SW1A1AA');

    $matched = html_entity_decode(
        fulfilmentCheckoutGet($host, $sessionId, '?fulfilment_method=delivery')->assertOk()->getContent(),
        ENT_QUOTES | ENT_HTML5,
    );

    expect($matched)->toContain('£4.00')
        ->and($matched)->not->toContain('Enter your postcode to see delivery');
    expect($matched)->not->toMatch('/value="delivery"[^>]*\bdisabled\b|\bdisabled\b[^>]*value="delivery"/');
});

test('the stripe session amount equals the rendered checkout total', function (string $case) {
    $sessions = fulfilmentCheckoutFakeStripeCapture();

    if ($case === 'shipping') {
        ['host' => $host, 'sessionId' => $sessionId] = fulfilmentCheckoutSite('co-amt-ship.example', FulfilmentFixtures::florist());
        $query = '?fulfilment_method=shipping';
        $post = ['fulfilment_method' => 'shipping', 'postcode' => 'M1 1AA'];
        $expectSession = true;
    } elseif ($case === 'collect') {
        ['host' => $host, 'sessionId' => $sessionId] = fulfilmentCheckoutSite('co-amt-collect.example', FulfilmentFixtures::camino());
        $query = '?fulfilment_method=collect';
        $post = ['fulfilment_method' => 'collect', 'postcode' => 'SW1A 1AA'];
        $expectSession = true;
    } elseif ($case === 'free-over') {
        ['host' => $host, 'sessionId' => $sessionId] = fulfilmentCheckoutSite(
            'co-amt-free.example',
            FulfilmentFixtures::camino(),
            priceCents: 595,
            qty: 7,
        );
        test()->withCookie(CartController::COOKIE_NAME, $sessionId)
            ->from('http://'.$host.'/shop/checkout')
            ->get('http://'.$host.'/shop/fulfilment/check?postcode=SW1A1AA');
        $query = '?fulfilment_method=delivery';
        $post = ['fulfilment_method' => 'delivery', 'postcode' => 'SW1A 1AA'];
        $expectSession = true;
    } elseif ($case === 'min-order') {
        ['host' => $host, 'sessionId' => $sessionId] = fulfilmentCheckoutSite('co-amt-min.example', FulfilmentFixtures::camino());
        $query = '?fulfilment_method=delivery&postcode='.urlencode('SW2 1AA');
        $post = ['fulfilment_method' => 'delivery', 'postcode' => 'SW2 1AA'];
        $expectSession = false;
    } else {
        ['host' => $host, 'sessionId' => $sessionId] = fulfilmentCheckoutSite('co-amt-hit.example', FulfilmentFixtures::camino());
        test()->withCookie(CartController::COOKIE_NAME, $sessionId)
            ->from('http://'.$host.'/shop/checkout')
            ->get('http://'.$host.'/shop/fulfilment/check?postcode=SW1A1AA');
        $query = '?fulfilment_method=delivery';
        $post = ['fulfilment_method' => 'delivery', 'postcode' => 'SW1A 1AA'];
        $expectSession = true;
    }

    $html = fulfilmentCheckoutGet($host, $sessionId, $query)->assertOk()->getContent();
    $renderedTotal = fulfilmentCheckoutTotalCents($html);

    $response = fulfilmentCheckoutStart($host, $sessionId, $post);

    if ($expectSession) {
        $response->assertRedirect('https://stripe.test/pay');
        expect(fulfilmentCheckoutStripeAmount($sessions->params))->toBe($renderedTotal)
            ->and(Order::query()->first()->total_cents)->toBe($renderedTotal);
    } else {
        $response->assertSessionHasErrors('fulfilment_method');
        expect($sessions->params)->toBeNull()
            ->and(Order::query()->exists())->toBeFalse();
    }
})->with(['zone-hit', 'free-over', 'min-order', 'collect', 'shipping']);

test('a delivery submit with an unmatched postcode is 422 and creates no stripe session', function () {
    ['host' => $host, 'sessionId' => $sessionId] = fulfilmentCheckoutSite('co-unmatched.example', FulfilmentFixtures::camino());
    $sessions = fulfilmentCheckoutFakeStripeCapture();

    fulfilmentCheckoutStart($host, $sessionId, [
        'fulfilment_method' => 'delivery',
        'postcode' => 'M1 1AA',
    ])->assertUnprocessable()->assertSessionHasErrors([
        'postcode' => 'This postcode is not in our delivery area.',
    ]);

    expect($sessions->params)->toBeNull()
        ->and(Order::query()->exists())->toBeFalse();
});

test('checkout truncates a persisted overlong zone name and does not 500', function () {
    $fulfilment = FulfilmentFixtures::camino();
    $fulfilment['delivery']['zones'][0]['name'] = str_repeat('Z', 120);
    ['host' => $host, 'sessionId' => $sessionId] = fulfilmentCheckoutSite('co-long-name.example', $fulfilment);
    $sessions = fulfilmentCheckoutFakeStripeCapture();

    fulfilmentCheckoutStart($host, $sessionId, [
        'fulfilment_method' => 'delivery',
        'postcode' => 'SW1A 1AA',
    ])->assertRedirect('https://stripe.test/pay');

    $order = Order::query()->first();
    expect($order)->not->toBeNull()
        ->and($order->fulfilment_zone_name)->toBe(str_repeat('Z', 80))
        ->and(strlen($order->fulfilment_zone_name))->toBe(80)
        ->and($sessions->params)->not->toBeNull();
});

test('checkout summary prices the widget postcode with a confirm-address message when the address field is empty', function () {
    ['host' => $host, 'sessionId' => $sessionId] = fulfilmentCheckoutSite('co-widget-pc.example', FulfilmentFixtures::camino());

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->from('http://'.$host.'/shop/checkout')
        ->get('http://'.$host.'/shop/fulfilment/check?postcode=SW1A1AA');

    $html = html_entity_decode(fulfilmentCheckoutGet($host, $sessionId)->assertOk()->getContent(), ENT_QUOTES | ENT_HTML5);

    expect($html)->toContain('£4.00')
        ->and($html)->toContain('Based on SW1A 1AA — confirm your address')
        ->and($html)->toMatch('/data-testid="checkout-postcode"[^>]*value=""/');
});

test('checkout summary prices the address postcode over the widget and re-renders on GET', function () {
    ['host' => $host, 'sessionId' => $sessionId] = fulfilmentCheckoutSite('co-addr-pc.example', FulfilmentFixtures::camino());

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->from('http://'.$host.'/shop/checkout')
        ->get('http://'.$host.'/shop/fulfilment/check?postcode=SW1A1AA');

    $html = html_entity_decode(
        fulfilmentCheckoutGet($host, $sessionId, '?postcode='.urlencode('SW2 1AA').'&fulfilment_method=delivery')->assertOk()->getContent(),
        ENT_QUOTES | ENT_HTML5,
    );

    expect($html)->toContain('£6.00')
        ->and($html)->not->toContain('Based on SW1A 1AA — confirm your address')
        ->and($html)->toMatch('/data-testid="checkout-postcode"[^>]*value="SW2 1AA"|value="SW2 1AA"[^>]*data-testid="checkout-postcode"/');
});

test('an address postcode GET re-price keeps the displayed total equal to Stripe', function () {
    ['host' => $host, 'sessionId' => $sessionId] = fulfilmentCheckoutSite(
        'co-address-stripe.example',
        FulfilmentFixtures::camino(),
        priceCents: 1595,
    );

    $inner = fulfilmentCheckoutGet($host, $sessionId, '?postcode=SW1A%201AA&fulfilment_method=delivery')
        ->assertOk()
        ->getContent();
    $outer = fulfilmentCheckoutGet($host, $sessionId, '?postcode=SW2%201AA&fulfilment_method=delivery')
        ->assertOk()
        ->getContent();
    $outerTotal = fulfilmentCheckoutTotalCents($outer);
    $sessions = fulfilmentCheckoutFakeStripeCapture();

    fulfilmentCheckoutStart($host, $sessionId, [
        'fulfilment_method' => 'delivery',
        'postcode' => 'SW2 1AA',
    ])->assertRedirect('https://stripe.test/pay');

    expect($outer)->toContain('£6.00')
        ->and($outerTotal)->not->toBe(fulfilmentCheckoutTotalCents($inner))
        ->and(fulfilmentCheckoutStripeAmount($sessions->params))->toBe($outerTotal)
        ->and(Order::query()->sole()->total_cents)->toBe($outerTotal);
});

test('the checkout postcode field GETs a summary re-render on change', function () {
    ['host' => $host, 'sessionId' => $sessionId] = fulfilmentCheckoutSite('co-pc-change.example', FulfilmentFixtures::camino());

    $html = html_entity_decode(fulfilmentCheckoutGet($host, $sessionId)->assertOk()->getContent(), ENT_QUOTES | ENT_HTML5);

    expect($html)->toContain('data-testid="checkout-postcode"')
        ->and($html)->toMatch('/data-testid="checkout-postcode"[^>]*@change|@change[^>]*data-testid="checkout-postcode"/')
        ->and($html)->toContain('/shop/checkout');
});
