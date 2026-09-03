<?php

use App\Http\Controllers\Shop\CartController;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Shop\ProductImage;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShippingRate;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Support\ShopMoney;
use App\Services\Shop\CartService;
use App\Services\Shop\CheckoutService;
use App\Services\Shop\StripeService;
use Database\Seeders\Shop\TaxClassSeeder;
use Database\Seeders\Shop\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * @return array{site: Site, sessionId: string}
 */
function checkoutPageCart(): array
{
    test()->seed(TaxClassSeeder::class);
    test()->seed(TaxRateSeeder::class);

    $site = Site::factory()->create([
        'custom_domain' => 'flowers.example',
        'custom_domain_status' => 'active',
    ]);

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

    $sessionId = 'checkout-page-session';
    $cart = app(CartService::class)->getOrCreate($site->id, $sessionId);
    app(CartService::class)->addItem($cart, $variant->id, 1);

    return compact('site', 'sessionId');
}

function checkoutPageGet(string $sessionId): string
{
    return test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->get('http://flowers.example/shop/checkout')
        ->assertOk()
        ->getContent();
}

/**
 * @return array{action: string, method: string, fields: array<string, string>, inner: string, tag: string}
 */
function checkoutPageForm(string $html): array
{
    preg_match('#<form\b([^>]*action="[^"]*\/shop\/checkout\/start"[^>]*)>(.*?)</form>#s', $html, $form);
    expect($form)->not->toBeEmpty();

    preg_match('#\baction="([^"]+)"#', $form[1], $action);
    preg_match('#\bmethod="([^"]+)"#i', $form[1], $method);

    $fields = [];
    preg_match_all('#<input\b[^>]*>#i', $form[2], $inputs);
    foreach ($inputs[0] as $input) {
        if (! preg_match('#\bname="([^"]+)"#', $input, $name)) {
            continue;
        }
        preg_match('#\bvalue="([^"]*)"#', $input, $value);
        $fields[$name[1]] = $value[1] ?? '';
    }

    return [
        'action' => html_entity_decode($action[1] ?? '', ENT_QUOTES | ENT_HTML5),
        'method' => strtoupper($method[1] ?? 'GET'),
        'fields' => $fields,
        'inner' => $form[2],
        'tag' => $form[1],
    ];
}

function checkoutPagePounds(int $cents): string
{
    return '£'.number_format($cents / 100, 2);
}

function checkoutPageAmount(string $html, string $testId, string $symbol = '£'): string
{
    preg_match('/data-testid="'.$testId.'"[^>]*>(.*?)<\/div>/s', $html, $block);
    expect($block)->not->toBeEmpty();
    preg_match('/'.preg_quote($symbol, '/').'\d+(?:\.\d{2})?/', $block[1], $amount);
    expect($amount)->not->toBeEmpty();

    return $amount[0];
}

function checkoutPageTaxLabel(string $html, string $testId = 'checkout-vat'): string
{
    preg_match('/data-testid="'.$testId.'"[^>]*>(.*?)<\/div>/s', $html, $block);
    expect($block)->not->toBeEmpty();
    preg_match('/<(span)[^>]*>\s*([^<]+)/', $block[1], $label);
    expect($label)->not->toBeEmpty();

    return trim(html_entity_decode($label[2], ENT_QUOTES | ENT_HTML5));
}

function checkoutPageFakeStripeCapture(): object
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

test('checkout marks the Details step and has one h1', function () {
    ['sessionId' => $sessionId] = checkoutPageCart();

    $html = checkoutPageGet($sessionId);

    preg_match_all('/<h1\b/i', $html, $headings);
    expect($headings[0])->toHaveCount(1);

    preg_match('/<nav\b[^>]*aria-label="Checkout steps"[^>]*>(.*?)<\/nav>/is', $html, $nav);
    expect($nav)->not->toBeEmpty();

    preg_match_all('/<li\b([^>]*)>(.*?)<\/li>/is', $nav[1], $items, PREG_SET_ORDER);
    expect($items)->toHaveCount(3);

    $labels = array_map(
        fn (array $item): string => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($item[2]), ENT_QUOTES | ENT_HTML5))),
        $items,
    );
    expect($labels[0])->toMatch('/1\s*Cart/')
        ->and($labels[1])->toMatch('/2\s*Details/')
        ->and($labels[2])->toMatch('/3\s*Payment/');

    expect($items[1][1].$items[1][2])->toContain('aria-current="step"');
    expect($items[0][2])->toMatch('/<a\b[^>]*href="[^"]*\/shop\/cart"/i');
    expect($items[1][2])->not->toMatch('/<a\b/i');
});

test('the checkout form carries csrf, labeled address fields, and the expected action', function () {
    ['sessionId' => $sessionId] = checkoutPageCart();

    $html = checkoutPageGet($sessionId);
    $form = checkoutPageForm($html);

    expect($form['method'])->toBe('POST')
        ->and($form['action'])->toContain('/shop/checkout/start')
        ->and($form['fields']['_token'] ?? '')->not->toBeEmpty();

    foreach (['name', 'email', 'phone', 'line1', 'line2', 'city', 'postcode', 'country_code'] as $field) {
        expect($form['fields'])->toHaveKey($field);
        expect($form['inner'])->toMatch('/<label\b[^>]*>[^<]*<span[^>]*>[^<]*<\/span>[\s\S]*name="'.$field.'"/i');
    }

    expect($form['fields']['country_code'])->toBe('GB')
        ->and($form['inner'])->not->toContain('bg-blue-600')
        ->and($form['inner'])->not->toContain('bg-gray-50')
        ->and($form['inner'])->not->toContain('text-white');
});

test('the checkout summary matches CheckoutService::start including a VAT line', function () {
    ['sessionId' => $sessionId] = checkoutPageCart();

    $html = checkoutPageGet($sessionId);

    $siteId = Site::where('custom_domain', 'flowers.example')->value('id');
    $cart = app(CartService::class)->getOrCreate($siteId, $sessionId);
    $cart->load('items.variant.product');

    $order = app(CheckoutService::class)->start($cart, [
        'name' => 'Jane',
        'email' => 'jane@example.com',
        'line1' => '1 High St',
        'city' => 'Lancaster',
        'postcode' => 'LA1 1AA',
        'country_code' => 'GB',
    ]);

    $vatCents = $order->tax_cents + $order->shipping_tax_cents;

    expect($html)->toContain('Subtotal')
        ->and($html)->toContain('Shipping')
        ->and($html)->toContain('VAT')
        ->and($html)->toContain('Royal Mail 48');

    expect(checkoutPageAmount($html, 'checkout-subtotal'))->toBe(checkoutPagePounds($order->subtotal_cents))
        ->and(checkoutPageAmount($html, 'checkout-shipping'))->toBe(checkoutPagePounds($order->shipping_cents))
        ->and(checkoutPageAmount($html, 'checkout-vat'))->toBe(checkoutPagePounds($vatCents))
        ->and(checkoutPageAmount($html, 'checkout-total'))->toBe(checkoutPagePounds($order->total_cents));

    expect($order->total_cents)->toBe($order->subtotal_cents + $order->shipping_cents)
        ->and($vatCents)->toBeGreaterThan(0);
});

test('checkout shows trust copy before the Stripe hand-off', function () {
    ['sessionId' => $sessionId] = checkoutPageCart();

    $html = checkoutPageGet($sessionId);

    expect($html)->toMatch('/Pay with Stripe/i')
        ->and(strtolower($html))->toContain('stripe')
        ->and($html)->toMatch('/card details|we never see|securely/i');

    preg_match('#<form\b[^>]*action="[^"]*/shop/checkout/start".*?</form>#s', $html, $form);
    expect($form)->not->toBeEmpty();

    $buttonPos = strpos($html, 'Pay with Stripe');
    $trustPos = stripos($html, 'stripe');
    expect($buttonPos)->toBeInt()
        ->and($trustPos)->toBeInt()
        ->and($trustPos)->toBeLessThan($buttonPos);
});

test('a guest checkout shows a sign-in panel with a shop return path', function () {
    ['sessionId' => $sessionId] = checkoutPageCart();

    $html = checkoutPageGet($sessionId);

    expect($html)->toMatch('/Have an account\?.*Sign in.*for a faster checkout/s')
        ->and($html)->toMatch('/href="[^"]*\/shop\/account\/login\?return='.preg_quote('/shop/checkout', '/').'"/');
});

/**
 * @param  list<array{name: string, slug: string, price: int, qty: int, label: string, image: ?string, extra_variant_label?: string}>  $lines
 * @return array{site: Site, sessionId: string, host: string}
 */
function checkoutPageMultiItemCart(array $lines, array $siteAttrs = [], array $shipping = []): array
{
    test()->seed(TaxClassSeeder::class);
    test()->seed(TaxRateSeeder::class);

    $host = $siteAttrs['custom_domain'] ?? 'checkout-layout.example';
    $site = Site::factory()->create(array_merge([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
    ], $siteAttrs));

    ShippingRate::create(array_merge([
        'site_id' => $site->id,
        'strategy' => 'flat_with_free_threshold',
        'flat_amount_cents' => 350,
        'free_threshold_cents' => null,
        'method_label' => 'Royal Mail 48',
    ], $shipping));

    $sessionId = 'checkout-layout-session';
    $cart = app(CartService::class)->getOrCreate($site->id, $sessionId);

    foreach ($lines as $line) {
        $product = Product::factory()->published()->for($site)->create([
            'name' => $line['name'],
            'slug' => $line['slug'],
        ]);
        $variant = ProductVariant::factory()->for($product)->create([
            'price_cents' => $line['price'],
            'label' => $line['label'],
        ]);
        VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 20]);

        if (! empty($line['extra_variant_label'])) {
            $extra = ProductVariant::factory()->for($product)->create([
                'price_cents' => $line['price'],
                'label' => $line['extra_variant_label'],
            ]);
            VariantStock::create(['variant_id' => $extra->id, 'on_hand' => 20]);
        }

        if (! empty($line['image'])) {
            ProductImage::create([
                'product_id' => $product->id,
                'path' => $line['image'],
                'sort_order' => 1,
                'alt' => $line['name'],
            ]);
        }

        app(CartService::class)->addItem($cart, $variant->id, $line['qty']);
    }

    return compact('site', 'sessionId', 'host');
}

function checkoutPageGetHost(string $host, string $sessionId): string
{
    return test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->get("http://{$host}/shop/checkout")
        ->assertOk()
        ->getContent();
}

test('checkout lays out a two-column form and sticky summary with line items', function () {
    ['sessionId' => $sessionId, 'host' => $host] = checkoutPageMultiItemCart([
        [
            'name' => 'Sourdough Loaf',
            'slug' => 'sourdough',
            'price' => 800,
            'qty' => 2,
            'label' => 'Large',
            'image' => 'products/sourdough-thumb.jpg',
            'extra_variant_label' => 'Small',
        ],
        [
            'name' => 'Almond Croissant',
            'slug' => 'croissant',
            'price' => 450,
            'qty' => 1,
            'label' => 'Default',
            'image' => null,
        ],
    ]);

    $html = checkoutPageGetHost($host, $sessionId);

    expect($html)->toContain('lg:grid-cols-2')
        ->and($html)->toContain('lg:sticky')
        ->and($html)->toContain('lg:top-6')
        ->and($html)->toMatch('/<details\b[^>]*>[\s\S]*Order summary ·/')
        ->and($html)->toContain('Sourdough Loaf')
        ->and($html)->toContain('Almond Croissant')
        ->and($html)->toContain('× 2')
        ->and($html)->toContain('× 1')
        ->and($html)->toContain('Large')
        ->and($html)->not->toContain('>Default<')
        ->and($html)->toContain(ShopMoney::formatWithVat(1600, 'GBP'))
        ->and($html)->toContain(ShopMoney::formatWithVat(450, 'GBP'))
        ->and($html)->toMatch('/href="[^"]*\/shop\/cart"[^>]*>\s*Edit cart\s*</')
        ->and($html)->toContain('Secure checkout with Stripe')
        ->and($html)->toContain('We never see your card details')
        ->and($html)->toContain('data-lucide="lock"')
        ->and($html)->toContain('data-testid="checkout-summary"')
        ->and($html)->toContain('data-testid="checkout-subtotal"')
        ->and($html)->toContain('data-testid="checkout-shipping"')
        ->and($html)->toContain('data-testid="checkout-vat"')
        ->and($html)->toContain('data-testid="checkout-total"')
        ->and($html)->toMatch('/sourdough-thumb/')
        ->and($html)->toMatch('/<label\b[^>]*>[\s\S]*name="city"[\s\S]*name="postcode"|name="city"[\s\S]*name="postcode"/i');

    preg_match('#<form\b[^>]*action="[^"]*/shop/checkout/start".*?</form>#s', $html, $form);
    expect($form)->not->toBeEmpty()
        ->and($form[0])->not->toContain('We never see your card details')
        ->and($form[0])->toMatch('/sm:grid-cols-2/');

    expect($html)->toMatch('/Have an account\?.*Sign in.*for a faster checkout/s');
});

test('checkout shipping reads Free when the quoted rate is zero', function () {
    ['sessionId' => $sessionId, 'host' => $host] = checkoutPageMultiItemCart(
        [[
            'name' => 'Sourdough Loaf',
            'slug' => 'sourdough',
            'price' => 8000,
            'qty' => 2,
            'label' => 'Loaf',
            'image' => null,
        ]],
        shipping: [
            'flat_amount_cents' => 350,
            'free_threshold_cents' => 1000,
            'method_label' => 'Royal Mail 48',
        ],
    );

    $html = checkoutPageGetHost($host, $sessionId);

    preg_match('/data-testid="checkout-shipping"[^>]*>(.*?)<\/div>/s', $html, $block);
    expect($block)->not->toBeEmpty();
    expect($html)->toContain('Royal Mail 48')
        ->and($html)->not->toContain('Calculated at next step')
        ->and($block[1])->toContain('Free')
        ->and($block[1])->not->toContain('£0.00');
});

test('checkout quote uses the weight_tiers rate', function () {
    ['sessionId' => $sessionId, 'host' => $host] = checkoutPageMultiItemCart(
        [[
            'name' => 'Heavy Pan',
            'slug' => 'pan',
            'price' => 2000,
            'qty' => 1,
            'label' => 'Std',
            'image' => null,
        ]],
        shipping: [
            'strategy' => 'weight_tiers',
            'flat_amount_cents' => 9999,
            'free_threshold_cents' => null,
            'method_label' => 'Weight',
            'default_weight_grams' => 500,
            'tiers' => [
                ['up_to_grams' => 1000, 'amount_cents' => 495],
                ['up_to_grams' => null, 'amount_cents' => 995],
            ],
        ],
    );

    ProductVariant::query()->update(['weight_grams' => 1500]);

    $html = checkoutPageGetHost($host, $sessionId);

    expect($html)->toContain('Weight')
        ->and(checkoutPageAmount($html, 'checkout-shipping'))->toBe('£9.95');
});

test('a two-item checkout does not N+1 product images or sibling variants', function () {
    test()->seed(TaxClassSeeder::class);
    test()->seed(TaxRateSeeder::class);

    $host = 'checkout-nplus.example';
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
    ]);
    ShippingRate::create([
        'site_id' => $site->id,
        'strategy' => 'flat_with_free_threshold',
        'flat_amount_cents' => 350,
        'free_threshold_cents' => null,
        'method_label' => 'Royal Mail 48',
    ]);

    $addLine = function (string $name, string $slug, string $session) use ($site): void {
        $product = Product::factory()->published()->for($site)->create(['name' => $name, 'slug' => $slug]);
        $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 595, 'label' => 'Std']);
        VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 10]);
        ProductImage::create([
            'product_id' => $product->id,
            'path' => 'products/'.$slug.'-thumb.jpg',
            'sort_order' => 1,
            'alt' => $name,
        ]);
        $cart = app(CartService::class)->getOrCreate($site->id, $session);
        app(CartService::class)->addItem($cart, $variant->id, 1);
    };

    $addLine('Sourdough', 'sourdough', 'nplus-two');
    $addLine('Croissant', 'croissant', 'nplus-two');
    $addLine('Sourdough', 'sourdough-3', 'nplus-three');
    $addLine('Croissant', 'croissant-3', 'nplus-three');
    $addLine('Rye', 'rye-3', 'nplus-three');

    $countQueries = function (string $session) use ($host): \Illuminate\Support\Collection {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->withCookie(CartController::COOKIE_NAME, $session)
            ->get("http://{$host}/shop/checkout")
            ->assertOk();

        return collect(DB::getQueryLog());
    };

    $countQueries('nplus-two');
    $two = $countQueries('nplus-two');
    $three = $countQueries('nplus-three');

    $imageSelects = fn (\Illuminate\Support\Collection $log): int => $log
        ->filter(fn (array $query): bool => str_contains($query['query'], 'shop_product_images'))
        ->count();
    $variantSelects = fn (\Illuminate\Support\Collection $log): int => $log
        ->filter(fn (array $query): bool => str_contains($query['query'], 'shop_product_variants'))
        ->count();

    expect($imageSelects($two))->toBe(1)
        ->and($imageSelects($three))->toBe(1)
        ->and($variantSelects($three))->toBe($variantSelects($two))
        ->and($three->count())->toBe($two->count());
});

test('a GBP checkout tax line reads VAT (included)', function () {
    ['sessionId' => $sessionId] = checkoutPageCart();

    expect(checkoutPageTaxLabel(checkoutPageGet($sessionId)))->toBe('VAT (included)');
});

test('a USD checkout omits the tax line and notes that no sales tax applies', function () {
    ['sessionId' => $sessionId, 'host' => $host] = checkoutPageMultiItemCart(
        [
            [
                'name' => 'Sourdough Loaf',
                'slug' => 'sourdough',
                'price' => 800,
                'qty' => 2,
                'label' => 'Loaf',
                'image' => 'products/sourdough-thumb.jpg',
            ],
            [
                'name' => 'Almond Croissant',
                'slug' => 'croissant',
                'price' => 450,
                'qty' => 1,
                'label' => 'Pastry',
                'image' => null,
            ],
        ],
        siteAttrs: [
            'custom_domain' => 'camino-checkout.example',
            'shop_currency' => 'USD',
        ],
    );

    $html = checkoutPageGetHost($host, $sessionId);

    expect($html)->toContain('data-testid="checkout-tax-note"')
        ->and($html)->toContain('No sales tax applied.')
        ->and($html)->not->toContain('data-testid="checkout-vat"')
        ->and($html)->not->toContain('VAT (included)')
        ->and($html)->not->toContain('>Tax<')
        ->and($html)->toContain('Sourdough Loaf')
        ->and($html)->toContain('× 2');
});

test('checkout country and postcode label follow the site', function (string $currency, ?string $country, string $expectedCode, string $postcodeLabel, ?string $taxLabel) {
    ['sessionId' => $sessionId, 'host' => $host] = checkoutPageMultiItemCart(
        [
            [
                'name' => 'Sourdough Loaf',
                'slug' => 'sourdough',
                'price' => 800,
                'qty' => 2,
                'label' => 'Loaf',
                'image' => 'products/sourdough-thumb.jpg',
            ],
            [
                'name' => 'Almond Croissant',
                'slug' => 'croissant',
                'price' => 450,
                'qty' => 1,
                'label' => 'Pastry',
                'image' => null,
            ],
        ],
        siteAttrs: [
            'custom_domain' => $currency === 'USD' ? 'camino-us.example' : 'bloom-gb.example',
            'shop_currency' => $currency,
            'country' => $country,
        ],
    );

    $html = checkoutPageGetHost($host, $sessionId);
    $form = checkoutPageForm($html);

    expect($form['fields']['country_code'])->toBe($expectedCode)
        ->and($html)->toContain($postcodeLabel)
        ->and($html)->toMatch('/autocomplete="postal-code"/')
        ->and($html)->toContain('lg:grid-cols-2')
        ->and($html)->toContain('lg:sticky')
        ->and($html)->toContain('Sourdough Loaf')
        ->and($html)->toContain('Almond Croissant')
        ->and($html)->toContain('× 2')
        ->and($html)->toContain('data-testid="checkout-summary"')
        ->and($html)->toContain('data-testid="checkout-subtotal"')
        ->and($html)->toContain('data-testid="checkout-shipping"')
        ->and($html)->toContain('data-testid="checkout-total"');

    if ($taxLabel === null) {
        expect($html)->toContain('data-testid="checkout-tax-note"')
            ->and($html)->toContain('No sales tax applied.')
            ->and($html)->not->toContain('data-testid="checkout-vat"')
            ->and($html)->not->toContain('>Tax<');
    } else {
        expect(checkoutPageTaxLabel($html))->toBe($taxLabel)
            ->and($html)->toContain('data-testid="checkout-vat"')
            ->and($html)->not->toContain('data-testid="checkout-tax-note"');
    }

    if ($postcodeLabel === 'ZIP code') {
        expect($html)->not->toContain('>Postcode<');
    } else {
        expect($html)->not->toContain('ZIP code');
    }
})->with([
    'USD site in the US' => ['USD', 'US', 'US', 'ZIP code', null],
    'GBP site defaults to GB' => ['GBP', null, 'GB', 'Postcode', 'VAT (included)'],
]);

test('checkout start accepts the site-derived country default', function () {
    ['sessionId' => $sessionId, 'host' => $host] = checkoutPageMultiItemCart(
        [[
            'name' => 'Sourdough Loaf',
            'slug' => 'sourdough',
            'price' => 800,
            'qty' => 1,
            'label' => 'Loaf',
            'image' => null,
        ]],
        siteAttrs: [
            'custom_domain' => 'camino-start.example',
            'shop_currency' => 'USD',
            'country' => 'US',
        ],
    );

    $html = checkoutPageGetHost($host, $sessionId);
    $form = checkoutPageForm($html);
    expect($form['fields']['country_code'])->toBe('US');

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
    app()->instance(\App\Services\Shop\StripeService::class, new \App\Services\Shop\StripeService($client));

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->post("http://{$host}/shop/checkout/start", [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'line1' => '1 University Ave',
            'city' => 'Palo Alto',
            'postcode' => '94301',
            'country_code' => 'US',
        ])
        ->assertRedirect('https://stripe.test/pay');
});

test('a USD site Stripe checkout session uses usd without rescaling amounts', function () {
    ['sessionId' => $sessionId, 'host' => $host] = checkoutPageMultiItemCart(
        [[
            'name' => 'Sourdough Loaf',
            'slug' => 'sourdough',
            'price' => 800,
            'qty' => 1,
            'label' => 'Loaf',
            'image' => null,
        ]],
        siteAttrs: [
            'custom_domain' => 'camino-stripe-usd.example',
            'shop_currency' => 'USD',
            'country' => 'US',
        ],
    );

    $sessions = checkoutPageFakeStripeCapture();

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->post("http://{$host}/shop/checkout/start", [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'line1' => '1 University Ave',
            'city' => 'Palo Alto',
            'postcode' => '94301',
            'country_code' => 'US',
        ])
        ->assertRedirect('https://stripe.test/pay');

    $order = Order::query()->latest('id')->first();
    expect($sessions->params)->not->toBeNull()
        ->and($sessions->params['line_items'][0]['price_data']['currency'])->toBe('usd')
        ->and($sessions->params['line_items'][0]['price_data']['unit_amount'])->toBe($order->total_cents);
});

test('checkout start ignores a posted country and quotes with the site country', function () {
    ['sessionId' => $sessionId, 'host' => $host] = checkoutPageMultiItemCart(
        [[
            'name' => 'Sourdough Loaf',
            'slug' => 'sourdough',
            'price' => 800,
            'qty' => 1,
            'label' => 'Loaf',
            'image' => null,
        ]],
        siteAttrs: [
            'custom_domain' => 'camino-country-enforce.example',
            'shop_currency' => 'USD',
            'country' => 'US',
        ],
    );

    checkoutPageFakeStripeCapture();

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->post("http://{$host}/shop/checkout/start", [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'line1' => '1 University Ave',
            'city' => 'Palo Alto',
            'postcode' => '94301',
            'country_code' => 'FR',
        ])
        ->assertRedirect('https://stripe.test/pay');

    $order = Order::query()->latest('id')->first();
    expect($order->tax_country_code)->toBe('US')
        ->and($order->shipping_address_json['country_code'])->toBe('US')
        ->and($order->tax_cents)->toBe(0)
        ->and($order->shipping_tax_cents)->toBe(0);
});

test('a GBP site Stripe checkout session still uses gbp', function () {
    ['sessionId' => $sessionId] = checkoutPageCart();
    $sessions = checkoutPageFakeStripeCapture();

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->post('http://flowers.example/shop/checkout/start', [
            'name' => 'Jane',
            'email' => 'jane@example.com',
            'line1' => '1 High St',
            'city' => 'Lancaster',
            'postcode' => 'LA1 1AA',
            'country_code' => 'GB',
        ])
        ->assertRedirect('https://stripe.test/pay');

    expect($sessions->params)->not->toBeNull()
        ->and($sessions->params['line_items'][0]['price_data']['currency'])->toBe('gbp');
});
