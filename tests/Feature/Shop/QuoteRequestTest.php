<?php

use App\Http\Controllers\Shop\CartController;
use App\Mail\SiteEnquiryReceived;
use App\Models\Shop\CartItem;
use App\Models\Shop\Customer;
use App\Models\Shop\CustomerAddress;
use App\Models\Shop\Product;
use App\Models\Shop\ProductImage;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Models\SiteEnquiry;
use App\Services\Shop\CartService;
use App\Support\ShopMoney;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;

/**
 * @return array{site: Site, host: string, product: Product, variant: ProductVariant, sessionId: string}
 */
function quoteRequestFilledCart(string $host = 'quote.example', int $qty = 2): array
{
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'shop_mode' => 'quote',
        'shop_currency' => 'GBP',
        'business_name' => 'Quote Bakery',
        'enquiry_notification_email' => 'owner@quote.example',
    ]);

    $product = Product::factory()->published()->for($site)->create([
        'name' => 'Victoria Sponge',
        'slug' => 'victoria',
    ]);
    $small = ProductVariant::factory()->for($product)->create([
        'label' => 'Small',
        'price_cents' => 1850,
        'sku' => 'VS-S',
    ]);
    ProductVariant::factory()->for($product)->create([
        'label' => 'Large',
        'price_cents' => 2450,
        'sku' => 'VS-L',
    ]);
    VariantStock::create(['variant_id' => $small->id, 'on_hand' => 10]);
    ProductImage::create([
        'product_id' => $product->id,
        'path' => 'products/victoria-thumb.jpg',
        'sort_order' => 1,
        'alt' => 'Victoria Sponge',
    ]);

    $sessionId = 'quote-session-'.$host;
    $cart = app(CartService::class)->getOrCreate($site->id, $sessionId);
    app(CartService::class)->addItem($cart, $small->id, $qty);

    return [
        'site' => $site,
        'host' => $host,
        'product' => $product,
        'variant' => $small,
        'sessionId' => $sessionId,
    ];
}

function quoteRequestGet(string $host, string $sessionId, string $path = '/shop/quote')
{
    return test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->get('http://'.$host.$path);
}

function quoteRequestPost(string $host, string $sessionId, array $payload = [])
{
    $site = Site::query()->where('custom_domain', $host)->first();
    $honeypot = $site?->enquiryHoneypotFieldName() ?? 'website';

    return test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->post('http://'.$host.'/shop/quote', array_merge([
            'name' => 'Ava O\'Neil',
            'email' => 'ava@quote.example',
            'phone' => '01234 567890',
            'needed_by' => now()->addDay()->toDateString(),
            'message' => 'Three custom sponges for Saturday.',
            $honeypot => '',
        ], $payload));
}

function quoteRequestTokenFrom(string $html): string
{
    preg_match('/name="quote_token"\s+value="([^"]+)"/', $html, $match);
    expect($match[1] ?? '')->not->toBe('');

    return $match[1];
}

/**
 * @param  list<array{name: string, slug: string, price: int, qty: int, label: string, image?: string|null, extra_variant_label?: string}>  $lines
 * @param  array<string, mixed>  $siteAttrs
 * @return array{site: Site, host: string, sessionId: string}
 */
function quoteRequestMultiItemCart(array $lines, array $siteAttrs = []): array
{
    $host = $siteAttrs['custom_domain'] ?? 'quote-layout.example';
    $site = Site::factory()->create(array_merge([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'shop_mode' => 'quote',
        'shop_currency' => 'GBP',
        'business_name' => 'Quote Bakery',
    ], $siteAttrs));

    $sessionId = 'quote-layout-'.$host;
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
            ProductVariant::factory()->for($product)->create([
                'price_cents' => $line['price'],
                'label' => $line['extra_variant_label'],
            ]);
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

/**
 * @return list<array{name: string, slug: string, price: int, qty: int, label: string, image: string|null, extra_variant_label?: string}>
 */
function quoteRequestLayoutLines(): array
{
    return [
        [
            'name' => 'Confetti Vanilla Cake',
            'slug' => 'confetti',
            'price' => 7000,
            'qty' => 1,
            'label' => 'Vanilla',
            'image' => 'products/confetti-thumb.jpg',
            'extra_variant_label' => 'Chocolate',
        ],
        [
            'name' => 'Fig Walnut Tart',
            'slug' => 'fig-tart',
            'price' => 4200,
            'qty' => 2,
            'label' => 'Default',
            'image' => null,
        ],
    ];
}

test('the quote page is 200 with cart lines and 302 to the cart when empty', function () {
    ['host' => $host, 'sessionId' => $sessionId, 'variant' => $variant, 'site' => $site] = quoteRequestFilledCart();

    $html = quoteRequestGet($host, $sessionId)->assertOk()->getContent();

    expect($html)->toContain('Victoria Sponge')
        ->and($html)->toContain('Small')
        ->and($html)->toContain('× 2')
        ->and($html)->toContain(ShopMoney::format(3700, 'GBP'))
        ->and($html)->toContain('name="name"')
        ->and($html)->toContain('name="email"')
        ->and($html)->toContain('name="phone"')
        ->and($html)->toContain('name="needed_by"')
        ->and($html)->toContain('name="message"')
        ->and($html)->toContain('name="'.$site->enquiryHoneypotFieldName().'"')
        ->and($html)->not->toContain('name="website"')
        ->and($html)->toContain('name="quote_token"')
        ->and($html)->toContain('When do you need it?');

    $emptyHost = 'quote-empty.example';
    Site::factory()->create([
        'custom_domain' => $emptyHost,
        'custom_domain_status' => 'active',
        'shop_mode' => 'quote',
    ]);
    Product::factory()->published()->for(Site::query()->where('custom_domain', $emptyHost)->first())->create();

    quoteRequestGet($emptyHost, 'empty-session')
        ->assertRedirect('http://'.$emptyHost.'/shop/cart')
        ->assertSessionHas('status');

    expect($variant->shopperFacingLabel())->toBe('Small');
});

test('submitting a quote creates an enquiry snapshot, clears the cart, mails the owner, and lands on sent', function () {
    Mail::fake();
    ['host' => $host, 'sessionId' => $sessionId, 'site' => $site, 'product' => $product, 'variant' => $variant] = quoteRequestFilledCart('quote-submit.example');

    $neededBy = now()->addDays(3)->toDateString();
    quoteRequestPost($host, $sessionId, [
        'needed_by' => $neededBy,
        'message' => 'Saturday pickup please.',
    ])->assertRedirect('http://'.$host.'/shop/quote/sent');

    $enquiry = SiteEnquiry::query()->sole();
    expect($enquiry->site_id)->toBe($site->id)
        ->and($enquiry->name)->toBe('Ava O\'Neil')
        ->and($enquiry->email)->toBe('ava@quote.example')
        ->and($enquiry->customer_id)->toBeNull()
        ->and($enquiry->payload['kind'])->toBe('quote')
        ->and($enquiry->payload['phone'])->toBe('01234 567890')
        ->and($enquiry->payload['needed_by'])->toBe($neededBy)
        ->and($enquiry->payload['message'])->toBe('Saturday pickup please.')
        ->and($enquiry->payload['lines'])->toHaveCount(1)
        ->and($enquiry->payload['lines'][0])->toMatchArray([
            'product_id' => $product->id,
            'product_slug' => 'victoria',
            'name' => 'Victoria Sponge',
            'variant_id' => $variant->id,
            'variant_label' => 'Small',
            'qty' => 2,
            'unit_price_cents' => 1850,
            'currency' => 'GBP',
        ])
        ->and($enquiry->field_labels['needed_by'])->toBe('When do you need it?')
        ->and($enquiry->field_labels['lines'])->toBe('Items');

    expect(CartItem::query()->count())->toBe(0);

    Mail::assertQueued(SiteEnquiryReceived::class, function (SiteEnquiryReceived $mail) use ($enquiry) {
        return $mail->hasTo('owner@quote.example')
            && $mail->enquiry->is($enquiry);
    });

    $sent = quoteRequestGet($host, $sessionId, '/shop/quote/sent')->assertOk()->getContent();
    expect($sent)->toContain((string) $enquiry->id)
        ->and($sent)->toContain('We\'ll reply to ava@quote.example')
        ->and($sent)->toContain('Request sent')
        ->and($sent)->toContain('Victoria Sponge')
        ->and($sent)->toContain(ShopMoney::format(3700, 'GBP'));
});

test('an empty-cart quote submit redirects to the cart with a flash', function () {
    Mail::fake();
    $host = 'quote-empty-post.example';
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'shop_mode' => 'quote',
        'enquiry_notification_email' => 'owner@quote.example',
    ]);
    Product::factory()->published()->for($site)->create();

    quoteRequestPost($host, 'no-items')
        ->assertRedirect('http://'.$host.'/shop/cart')
        ->assertSessionHas('status');

    expect(SiteEnquiry::query()->count())->toBe(0);
    Mail::assertNothingOutgoing();
});

test('the quote honeypot pretends success and stores nothing', function () {
    Mail::fake();
    ['host' => $host, 'sessionId' => $sessionId, 'site' => $site] = quoteRequestFilledCart('quote-honey.example');

    quoteRequestPost($host, $sessionId, [$site->enquiryHoneypotFieldName() => 'https://spam.example'])
        ->assertRedirect();

    Mail::assertNothingOutgoing();
    expect(SiteEnquiry::query()->count())->toBe(0);
});

test('a fourth quote submit from the same IP in ten minutes is 429', function () {
    Mail::fake();
    $host = 'quote-throttle.example';
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'shop_mode' => 'quote',
    ]);
    Product::factory()->published()->for($site)->create();

    for ($i = 0; $i < 3; $i++) {
        test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->post('http://'.$host.'/shop/quote', [
                'name' => 'Ava',
                'email' => 'ava@quote.example',
                $site->enquiryHoneypotFieldName() => '',
            ])->assertRedirect();
    }

    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
        ->post('http://'.$host.'/shop/quote', [
            'name' => 'Ava',
            'email' => 'ava@quote.example',
            $site->enquiryHoneypotFieldName() => '',
        ])->assertStatus(429);
});

test('a signed-in customer is prefilled and stamped onto the enquiry', function () {
    Mail::fake();
    ['host' => $host, 'sessionId' => $sessionId, 'site' => $site] = quoteRequestFilledCart('quote-signed.example');
    $customer = Customer::create([
        'site_id' => $site->id,
        'email' => 'signed@quote.example',
        'email_verified_at' => now(),
        'name' => 'Signed Ava',
    ]);
    CustomerAddress::create([
        'site_id' => $site->id,
        'customer_id' => $customer->id,
        'label' => 'Home',
        'name' => 'Signed Ava',
        'phone' => '07700 900123',
        'line1' => '1 Cake Lane',
        'city' => 'Lancaster',
        'postcode' => 'LA1 1AA',
        'country_code' => 'GB',
        'is_default_shipping' => true,
        'is_default_billing' => false,
    ]);
    auth('customer')->login($customer);

    $html = quoteRequestGet($host, $sessionId)->assertOk()->getContent();
    expect($html)->toContain('Signed Ava')
        ->and($html)->toContain('signed@quote.example')
        ->and($html)->toContain('07700 900123');

    quoteRequestPost($host, $sessionId, [
        'name' => 'Signed Ava',
        'email' => 'signed@quote.example',
        'phone' => '07700 900123',
    ])->assertRedirect('http://'.$host.'/shop/quote/sent');

    expect(SiteEnquiry::query()->sole()->customer_id)->toBe($customer->id);
});

test('a past needed_by date is rejected', function () {
    Mail::fake();
    ['host' => $host, 'sessionId' => $sessionId] = quoteRequestFilledCart('quote-past.example');

    quoteRequestPost($host, $sessionId, [
        'needed_by' => now()->subDay()->toDateString(),
    ])->assertSessionHasErrors('needed_by');

    expect(SiteEnquiry::query()->count())->toBe(0);
});

test('a double submit of the same cart and quote token creates one enquiry and clears the cart', function () {
    Mail::fake();
    ['host' => $host, 'sessionId' => $sessionId] = quoteRequestFilledCart('quote-double.example');

    $token = quoteRequestTokenFrom(quoteRequestGet($host, $sessionId)->assertOk()->getContent());

    quoteRequestPost($host, $sessionId, ['quote_token' => $token])
        ->assertRedirect('http://'.$host.'/shop/quote/sent');
    quoteRequestPost($host, $sessionId, ['quote_token' => $token])
        ->assertRedirect('http://'.$host.'/shop/quote/sent');

    expect(SiteEnquiry::query()->count())->toBe(1)
        ->and(CartItem::query()->count())->toBe(0);

    Mail::assertQueued(SiteEnquiryReceived::class, 1);
});

test('a second quote on the same cart after re-adding items creates a second enquiry', function () {
    Mail::fake();
    ['host' => $host, 'sessionId' => $sessionId, 'site' => $site, 'variant' => $variant] = quoteRequestFilledCart('quote-again.example');

    $first = quoteRequestTokenFrom(quoteRequestGet($host, $sessionId)->assertOk()->getContent());
    quoteRequestPost($host, $sessionId, ['quote_token' => $first])
        ->assertRedirect('http://'.$host.'/shop/quote/sent');

    $cart = app(CartService::class)->getOrCreate($site->id, $sessionId);
    app(CartService::class)->addItem($cart, $variant->id, 1);

    $second = quoteRequestTokenFrom(quoteRequestGet($host, $sessionId)->assertOk()->getContent());
    expect($second)->not->toBe($first);

    quoteRequestPost($host, $sessionId, ['quote_token' => $second, 'email' => 'again@quote.example'])
        ->assertRedirect('http://'.$host.'/shop/quote/sent');

    expect(SiteEnquiry::query()->count())->toBe(2)
        ->and(CartItem::query()->count())->toBe(0)
        ->and(SiteEnquiry::query()->latest('id')->first()->email)->toBe('again@quote.example');

    Mail::assertQueued(SiteEnquiryReceived::class, 2);
});

test('quote mail is not queued when the submit transaction rolls back', function () {
    Mail::fake();
    ['host' => $host, 'sessionId' => $sessionId] = quoteRequestFilledCart('quote-rollback.example');

    $real = app(CartService::class);
    test()->mock(CartService::class, function (MockInterface $mock) use ($real): void {
        $mock->shouldReceive('getOrCreate')->andReturnUsing(
            fn (int $siteId, string $sessionCookieId) => $real->getOrCreate($siteId, $sessionCookieId)
        );
        $mock->shouldReceive('clear')->once()->andThrow(new RuntimeException('clear failed'));
    });

    test()->withoutExceptionHandling();

    expect(fn () => quoteRequestPost($host, $sessionId))->toThrow(RuntimeException::class);

    expect(SiteEnquiry::query()->count())->toBe(0)
        ->and(CartItem::query()->sum('qty'))->toBe(2);
    Mail::assertNothingOutgoing();
});

it('renders a status flash on the quote page (lock-timeout retry message)', function () {
    ['host' => $host, 'sessionId' => $sessionId] = quoteRequestFilledCart();

    $html = $this->withSession(['status' => 'Your request is still being processed — please try again in a moment.'])
        ->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->get('http://'.$host.'/shop/quote')
        ->assertOk()
        ->assertSee('please try again in a moment')
        ->getContent();

    expect($html)->toMatch('/data-testid="quote-status"[\s\S]*<form\b[^>]*action="[^"]*\/shop\/quote"/');
});

test('the quote page lays out two columns with a sticky your-list card', function (string $currency, string $host) {
    ['sessionId' => $sessionId] = quoteRequestMultiItemCart(quoteRequestLayoutLines(), [
        'custom_domain' => $host,
        'shop_currency' => $currency,
    ]);

    $html = quoteRequestGet($host, $sessionId)->assertOk()->getContent();

    $cakeLine = ShopMoney::formatWithVat(7000, $currency);
    $tartLine = ShopMoney::formatWithVat(8400, $currency);
    $guideTotal = ShopMoney::formatWithVat(15400, $currency);

    expect($html)->toContain('lg:grid-cols-2')
        ->and($html)->toContain('gap-8 items-start')
        ->and($html)->toContain('lg:sticky')
        ->and($html)->toContain('lg:top-6')
        ->and($html)->toContain('--site-header-height')
        ->and($html)->toContain('Request a quote')
        ->and($html)->toContain("Tell us who it's for and when you need it — we'll come back with a price and availability.")
        ->and($html)->toContain('Your details')
        ->and($html)->toContain('Your list')
        ->and($html)->toMatch('/<details\b[^>]*>[\s\S]*Your list ·/')
        ->and($html)->toContain('Your list · '.$guideTotal)
        ->and($html)->toContain('Confetti Vanilla Cake')
        ->and($html)->toContain('Fig Walnut Tart')
        ->and($html)->toContain('Vanilla')
        ->and($html)->not->toContain('>Default<')
        ->and($html)->toContain('× 1')
        ->and($html)->toContain('× 2')
        ->and($html)->toContain($cakeLine)
        ->and($html)->toContain($tartLine)
        ->and($html)->toContain($guideTotal)
        ->and($html)->toContain('Guide total')
        ->and($html)->toContain('Prices are a guide until we confirm.')
        ->and($html)->toContain('No payment is taken now.')
        ->and($html)->toContain('data-lucide="lock"')
        ->and($html)->toContain('We reply within one business day.')
        ->and($html)->toMatch('/href="[^"]*\/shop\/cart"[^>]*>\s*Edit list →\s*</')
        ->and($html)->toMatch('/confetti-thumb/')
        ->and($html)->toContain('width: 64px');

    expect(preg_match_all('/data-testid="quote-summary"/', $html))->toBe(1)
        ->and(preg_match_all('/data-testid="quote-summary-sm"/', $html))->toBe(1)
        ->and(preg_match_all('/data-testid="quote-summary-md"/', $html))->toBe(1);

    preg_match('#<aside\b[^>]*>.*?</aside>#s', $html, $aside);
    expect($aside)->not->toBeEmpty()
        ->and($aside[0])->toContain('data-testid="quote-summary"')
        ->and($aside[0])->toContain('Guide total')
        ->and($aside[0])->toContain($guideTotal)
        ->and(str_contains($aside[0], 'inc. VAT'))->toBe($currency === 'GBP');

    preg_match('#<form\b[^>]*action="[^"]*/shop/quote".*?</form>#s', $html, $form);
    expect($form)->not->toBeEmpty()
        ->and($form[0])->not->toContain('No payment is taken now.')
        ->and($form[0])->toMatch('/sm:grid-cols-2/')
        ->and($form[0])->toContain('name="name"')
        ->and($form[0])->toContain('name="email"')
        ->and($form[0])->toContain('name="phone"')
        ->and($form[0])->toContain('name="needed_by"')
        ->and($form[0])->toContain('name="message"')
        ->and($form[0])->toContain('rows="4"')
        ->and($form[0])->toContain('maxlength="1000"')
        ->and($form[0])->toContain('data-testid="quote-message-count"')
        ->and($form[0])->toContain('When do you need it?')
        ->and($form[0])->toContain('Request a quote');
})->with([
    'GBP' => ['GBP', 'quote-layout-gbp.example'],
    'USD' => ['USD', 'quote-layout-usd.example'],
]);

test('quote guide total is the sum of line prices', function () {
    ['host' => $host, 'sessionId' => $sessionId] = quoteRequestMultiItemCart(quoteRequestLayoutLines(), [
        'custom_domain' => 'quote-guide-total.example',
        'shop_currency' => 'USD',
    ]);

    $html = quoteRequestGet($host, $sessionId)->assertOk()->getContent();

    preg_match('/data-testid="quote-guide-total"[^>]*>(.*?)<\/div>/s', $html, $block);
    expect($block)->not->toBeEmpty()
        ->and($block[1])->toContain('Guide total')
        ->and($block[1])->toContain(ShopMoney::format(15400, 'USD'))
        ->and($block[1])->not->toContain(ShopMoney::format(7000, 'USD').ShopMoney::format(8400, 'USD'));
});

test('the quote form shows inline field errors', function () {
    ['host' => $host, 'sessionId' => $sessionId, 'site' => $site] = quoteRequestFilledCart('quote-inline-errors.example');
    $honeypot = $site->enquiryHoneypotFieldName();

    $html = $this->from('http://'.$host.'/shop/quote')
        ->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->followingRedirects()
        ->post('http://'.$host.'/shop/quote', [
            'name' => '',
            'email' => 'not-an-email',
            'needed_by' => now()->subDay()->toDateString(),
            'phone' => '',
            'message' => '',
            $honeypot => '',
        ])
        ->assertOk()
        ->getContent();

    preg_match_all('/<label class="block">[\s\S]*?<\/label>/', $html, $labels);
    $nameLabel = collect($labels[0])->first(fn (string $label): bool => str_contains($label, 'name="name"'));
    $emailLabel = collect($labels[0])->first(fn (string $label): bool => str_contains($label, 'name="email"'));
    $neededByLabel = collect($labels[0])->first(fn (string $label): bool => str_contains($label, 'name="needed_by"'));

    expect($nameLabel)->toBeString()->and($nameLabel)->toContain('role="alert"')
        ->and($emailLabel)->toBeString()->and($emailLabel)->toContain('role="alert"')
        ->and($neededByLabel)->toBeString()->and($neededByLabel)->toContain('role="alert"');
});

test('the quote sent page shows snapshot lines in a two-column layout', function (string $currency, string $host) {
    Mail::fake();
    ['sessionId' => $sessionId] = quoteRequestMultiItemCart(quoteRequestLayoutLines(), [
        'custom_domain' => $host,
        'shop_currency' => $currency,
        'enquiry_notification_email' => 'owner@'.$host,
    ]);

    quoteRequestPost($host, $sessionId)->assertRedirect('http://'.$host.'/shop/quote/sent');

    $html = quoteRequestGet($host, $sessionId, '/shop/quote/sent')->assertOk()->getContent();
    $enquiry = SiteEnquiry::query()->sole();
    $guideTotal = ShopMoney::formatWithVat(15400, $currency);

    expect($html)->toContain('lg:grid-cols-2')
        ->and($html)->toContain('gap-8 items-start')
        ->and($html)->toContain('lg:sticky')
        ->and($html)->toContain('lg:top-6')
        ->and($html)->toContain('--site-header-height')
        ->and($html)->toContain('Request sent')
        ->and($html)->toContain((string) $enquiry->id)
        ->and($html)->toContain("We'll reply to ava@quote.example")
        ->and($html)->toContain('Back to shop')
        ->and($html)->toContain('Confetti Vanilla Cake')
        ->and($html)->toContain('Fig Walnut Tart')
        ->and($html)->toContain('Vanilla')
        ->and($html)->toContain('× 1')
        ->and($html)->toContain('× 2')
        ->and($html)->toContain(ShopMoney::format(7000, $currency))
        ->and($html)->toContain(ShopMoney::format(8400, $currency))
        ->and($html)->toContain($guideTotal)
        ->and($html)->toContain('Guide total')
        ->and($html)->toMatch('/<details\b[^>]*>[\s\S]*Your list ·/')
        ->and($html)->toContain('Your list · '.$guideTotal)
        ->and($html)->not->toContain('Edit list');

    expect(preg_match_all('/data-testid="quote-summary"/', $html))->toBe(1)
        ->and(preg_match_all('/data-testid="quote-summary-sm"/', $html))->toBe(1)
        ->and(preg_match_all('/data-testid="quote-summary-md"/', $html))->toBe(1);

    preg_match('#<aside\b[^>]*>.*?</aside>#s', $html, $aside);
    expect($aside)->not->toBeEmpty()
        ->and($aside[0])->toContain('Confetti Vanilla Cake')
        ->and($aside[0])->toContain($guideTotal)
        ->and(str_contains($aside[0], 'inc. VAT'))->toBe($currency === 'GBP');
})->with([
    'GBP' => ['GBP', 'quote-sent-gbp.example'],
    'USD' => ['USD', 'quote-sent-usd.example'],
]);
