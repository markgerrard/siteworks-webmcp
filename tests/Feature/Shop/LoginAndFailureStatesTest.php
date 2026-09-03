<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Http\Controllers\Shop\CartController;
use App\Models\Shop\Customer;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShippingRate;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Shop\StockReservation;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Services\Shop\CartService;
use Database\Seeders\Shop\TaxClassSeeder;
use Database\Seeders\Shop\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * @return array{site: Site, product: Product, variant: ProductVariant}
 */
function failureStateCatalogue(int $stock = 0): array
{
    $site = Site::factory()->create([
        'custom_domain' => 'failures.example',
        'custom_domain_status' => 'active',
    ]);
    $product = Product::factory()->published()->for($site)->create([
        'slug' => 'scarlet-rose',
        'name' => 'Scarlet Rose',
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'label' => 'Standard',
        'price_cents' => 2500,
    ]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => $stock]);

    $snapshot = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => [
            'meta' => ['site_id' => $site->id, 'product_count' => 1],
            'categories' => [],
            'products' => [
                'scarlet-rose' => [
                    'id' => $product->id,
                    'slug' => 'scarlet-rose',
                    'status' => 'published',
                    'primary_category_slug' => null,
                    'price_cents' => 2500,
                    'price_display' => '£25.00',
                    'in_stock_any' => $stock > 0,
                    'variant_in_stock' => [$variant->id => $stock > 0],
                    'image_urls' => ['thumb' => '/rose.jpg', 'card' => '/rose.jpg', 'full' => '/rose.jpg'],
                    'product_card' => ['slug' => 'scarlet-rose', 'name' => 'Scarlet Rose', 'price_display' => '£25.00'],
                    'product_detail' => ['slug' => 'scarlet-rose', 'name' => 'Scarlet Rose', 'description' => 'A rose'],
                    'variants' => [[
                        'id' => $variant->id,
                        'sku' => $variant->sku,
                        'label' => $variant->label,
                        'price_cents' => 2500,
                        'image_urls' => null,
                    ]],
                    'is_ai_seeded' => false,
                    'is_ai_reviewed' => false,
                ],
            ],
            'featured_slugs' => [],
        ],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create([
        'site_id' => $site->id,
        'snapshot_id' => $snapshot->id,
        'updated_at' => now(),
    ]);

    return compact('site', 'product', 'variant');
}

/**
 * @return array{postStatus: int, postLocation: ?string, postCacheTag: ?string, postCookies: list<array{name: string, path: string, secure: bool, httpOnly: bool, sameSite: ?string}>, getStatus: int, getCacheTag: ?string, body: string}
 */
function loginOracleOutcome(string $sessionId, string $email): array
{
    $sessionCookie = (string) config('session.cookie');
    $loginUrl = 'http://failures.example/shop/account/login';

    $post = test()
        ->withCookie($sessionCookie, $sessionId)
        ->from($loginUrl)
        ->post($loginUrl, ['email' => $email]);

    $get = test()
        ->withCookie($sessionCookie, $sessionId)
        ->get((string) $post->headers->get('Location'));

    return [
        'postStatus' => $post->getStatusCode(),
        'postLocation' => $post->headers->get('Location'),
        'postCacheTag' => $post->headers->get('Cache-Tag'),
        'postCookies' => array_map(
            fn ($cookie): array => [
                'name' => $cookie->getName(),
                'path' => $cookie->getPath(),
                'secure' => $cookie->isSecure(),
                'httpOnly' => $cookie->isHttpOnly(),
                'sameSite' => $cookie->getSameSite(),
            ],
            $post->headers->getCookies(),
        ),
        'getStatus' => $get->getStatusCode(),
        'getCacheTag' => $get->headers->get('Cache-Tag'),
        'body' => preg_replace(
            '/\n\s*/',
            "\n",
            (string) preg_replace(
                [
                    '/(<input type="hidden" name="_token" value=")[^"]+(" autocomplete="off">)/',
                    '/<!-- Livewire Styles -->.*?<\\/style>/s',
                    '/<script src="[^"]*livewire[^"]*"[^>]*><\\/script>/i',
                ],
                ['$1[csrf-token]$2', '', ''],
                $get->getContent(),
            ),
        ),
    ];
}

/**
 * @return array<string, string>
 */
function checkoutInputValues(string $html): array
{
    preg_match('#<form\b[^>]*action="[^"]*/shop/checkout/start"[^>]*>(.*?)</form>#s', $html, $form);
    expect($form)->not->toBeEmpty();

    preg_match_all('#<input\b[^>]*>#i', $form[1], $inputs);
    $values = [];

    foreach ($inputs[0] as $input) {
        if (! preg_match('#\bname="([^"]+)"#', $input, $name)) {
            continue;
        }

        preg_match('#\bvalue="([^"]*)"#', $input, $value);
        $values[$name[1]] = html_entity_decode($value[1] ?? '', ENT_QUOTES | ENT_HTML5);
    }

    return $values;
}

test('login link requests have the same visible response for deleted and unknown customers', function () {
    Mail::fake();
    ['site' => $site] = failureStateCatalogue();

    $deleted = Customer::create(['site_id' => $site->id, 'email' => 'deleted@example.com']);
    $deleted->delete();

    $deletedOutcome = loginOracleOutcome(str_repeat('a', 40), 'deleted@example.com');
    $unknownOutcome = loginOracleOutcome(str_repeat('b', 40), 'unknown@example.com');

    expect($deletedOutcome)->toBe($unknownOutcome)
        ->and($deletedOutcome['postStatus'])->toBe(302)
        ->and($deletedOutcome['postLocation'])->toBe('http://failures.example/shop/account/login')
        ->and($deletedOutcome['getStatus'])->toBe(200)
        ->and($deletedOutcome['body'])->toContain('Check your inbox');
});

test('login link requests have the same visible response for known and unknown emails', function () {
    Mail::fake();
    ['site' => $site] = failureStateCatalogue();

    Customer::create([
        'site_id' => $site->id,
        'email' => 'known@example.com',
        'email_verified_at' => now(),
    ]);

    $knownOutcome = loginOracleOutcome(str_repeat('c', 40), 'known@example.com');
    $unknownOutcome = loginOracleOutcome(str_repeat('d', 40), 'unknown@example.com');

    expect($knownOutcome)->toBe($unknownOutcome)
        ->and($knownOutcome['postStatus'])->toBe(302)
        ->and($knownOutcome['postLocation'])->toBe('http://failures.example/shop/account/login')
        ->and($knownOutcome['getStatus'])->toBe(200)
        ->and($knownOutcome['body'])->toContain('Check your inbox');
});

test('an out of stock add shows a message the shopper can read', function () {
    ['product' => $product, 'variant' => $variant] = failureStateCatalogue();
    $sessionCookie = (string) config('session.cookie');
    $productUrl = 'http://failures.example/products/scarlet-rose';

    $post = $this
        ->withCookie($sessionCookie, str_repeat('c', 40))
        ->from($productUrl)
        ->post('http://failures.example/shop/cart/add', [
            'product_slug' => $product->slug,
            'variant_id' => $variant->id,
            'qty' => 1,
        ])
        ->assertRedirect($productUrl);

    $this
        ->withCookie($sessionCookie, str_repeat('c', 40))
        ->get((string) $post->headers->get('Location'))
        ->assertOk()
        ->assertSee('Not enough stock available.')
        ->assertSee('role="alert"', false);
});

test('out of stock checkout renders a styled recovery page instead of a server error', function () {
    $this->seed([TaxClassSeeder::class, TaxRateSeeder::class]);
    ['site' => $site, 'variant' => $variant] = failureStateCatalogue(stock: 1);
    ShippingRate::create([
        'site_id' => $site->id,
        'strategy' => 'flat_with_free_threshold',
        'flat_amount_cents' => 350,
        'method_label' => 'Royal Mail 48',
    ]);

    $cartSession = 'out-of-stock-checkout';
    $cart = app(CartService::class)->getOrCreate($site->id, $cartSession);
    $item = app(CartService::class)->addItem($cart, $variant->id, 1);
    StockReservation::whereKey($item->reservation_id)->update(['expires_at' => now()->subMinute()]);
    VariantStock::where('variant_id', $variant->id)->update(['on_hand' => 0]);

    $this
        ->withCookie(CartController::COOKIE_NAME, $cartSession)
        ->post('http://failures.example/shop/checkout/start', [
            'name' => 'Ava Smith',
            'email' => 'ava@example.com',
            'phone' => '01234 567890',
            'line1' => '14 Rose Lane',
            'line2' => 'Flat 2',
            'city' => 'Lancaster',
            'postcode' => 'LA1 1AA',
            'country_code' => 'GB',
        ])
        ->assertStatus(409)
        ->assertSee('We couldn’t start checkout')
        ->assertSee('Return to your cart')
        ->assertSee('var(--color-primary)', false)
        ->assertSee('href="/shop/cart"', false);
});

test('failed checkout validation round trips every address field', function () {
    $this->seed([TaxClassSeeder::class, TaxRateSeeder::class]);
    ['site' => $site, 'variant' => $variant] = failureStateCatalogue(stock: 2);
    ShippingRate::create([
        'site_id' => $site->id,
        'strategy' => 'flat_with_free_threshold',
        'flat_amount_cents' => 350,
        'method_label' => 'Royal Mail 48',
    ]);

    $cartSession = 'validation-round-trip';
    $cart = app(CartService::class)->getOrCreate($site->id, $cartSession);
    app(CartService::class)->addItem($cart, $variant->id, 1);

    $submitted = [
        'name' => "Ava O'Neil",
        'email' => 'not-an-email',
        'phone' => '01234 567890',
        'line1' => '14 Rose Lane',
        'line2' => 'Flat 2',
        'city' => 'Lancaster',
        'postcode' => 'LA1 1AA',
        'country_code' => 'US',
    ];
    $sessionCookie = (string) config('session.cookie');
    $checkoutUrl = 'http://failures.example/shop/checkout';

    $this
        ->withCookies([
            $sessionCookie => str_repeat('d', 40),
            CartController::COOKIE_NAME => $cartSession,
        ])
        ->from($checkoutUrl)
        ->post('http://failures.example/shop/checkout/start', $submitted)
        ->assertRedirect($checkoutUrl);

    $html = $this
        ->withCookies([
            $sessionCookie => str_repeat('d', 40),
            CartController::COOKIE_NAME => $cartSession,
        ])
        ->get($checkoutUrl)
        ->assertOk()
        ->getContent();

    expect(checkoutInputValues($html))->toMatchArray($submitted);
});
