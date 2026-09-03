<?php

use App\Http\Controllers\Shop\CartController;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\CartItem;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Services\Shop\SnapshotBuilder;
use Tests\Support\LinePersonalisationFixtures;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * @param  list<array<string, mixed>>  $inputs
 * @return array{site: Site, product: Product, variant: ProductVariant, host: string}
 */
function personalisationPdpSite(array $inputs, string $host = 'pdp.example', string $mode = 'cart'): array
{
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'shop_mode' => $mode,
    ]);
    $product = Product::factory()->published()->for($site)->create([
        'slug' => 'item',
        'name' => 'Item',
        'customer_inputs' => $inputs,
    ]);
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 1500, 'label' => 'Default']);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 10]);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));

    return compact('site', 'product', 'variant', 'host');
}

test('a product with no inputs does not render customer input fields', function () {
    personalisationPdpSite([]);

    $html = test()->get('http://pdp.example/products/item')->assertOk()->getContent();

    expect($html)->not->toContain('data-customer-input')
        ->and($html)->not->toContain('name="personalisation');
});

test('each fixture renders its fields between the variant picker and the add button', function (string $name, array $inputs) {
    personalisationPdpSite($inputs, $name.'.example');

    $html = test()->get('http://'.$name.'.example/products/item')->assertOk()->getContent();

    preg_match('#<form\b([^>]*action="[^"]*/shop/cart/add"[^>]*)>(.*?)</form>#s', $html, $m);
    expect($m)->not->toBeEmpty();
    $form = $m[2];
    $variantPos = strpos($form, 'name="variant_id"');
    $buttonPos = strpos($form, 'Add to cart');
    expect($variantPos)->not->toBeFalse()
        ->and($buttonPos)->not->toBeFalse();

    foreach ($inputs as $input) {
        $marker = 'data-customer-input="'.$input['slug'].'"';
        expect($form)->toContain($marker);
        $fieldPos = strpos($form, $marker);
        expect($fieldPos)->toBeGreaterThan($variantPos)
            ->and($fieldPos)->toBeLessThan($buttonPos)
            ->and($form)->toContain(e($input['label']));
        if ($input['required'] ?? false) {
            expect($form)->toMatch('/data-customer-input="'.$input['slug'].'"[\s\S]*?\brequired\b/');
        }
    }
})->with([
    'bakery' => ['bakery', LinePersonalisationFixtures::bakery()],
    'florist' => ['florist', LinePersonalisationFixtures::florist()],
    'generic' => ['generic', LinePersonalisationFixtures::generic()],
]);

test('required text blocks add-to-cart on the server', function () {
    ['variant' => $variant] = personalisationPdpSite(LinePersonalisationFixtures::bakery());

    test()->from('http://pdp.example/products/item')
        ->post('http://pdp.example/shop/cart/add', [
            'product_slug' => 'item',
            'variant_id' => $variant->id,
            'qty' => 1,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors();

    expect(CartItem::count())->toBe(0);
});

test('add-to-cart stores frozen text and choice values', function () {
    ['variant' => $variant] = personalisationPdpSite(LinePersonalisationFixtures::generic(), 'choice.example');

    test()->post('http://choice.example/shop/cart/add', [
        'product_slug' => 'item',
        'variant_id' => $variant->id,
        'qty' => 1,
        'personalisation' => [
            'engraving' => 'Ada Lovelace',
            'colour' => 'Gold',
        ],
    ])->assertRedirect('http://choice.example/shop/cart');

    $item = CartItem::first();
    expect($item)->not->toBeNull()
        ->and($item->personalisation['engraving']['value'])->toBe('Ada Lovelace')
        ->and($item->personalisation['engraving']['label'])->toBe('Engraving')
        ->and($item->personalisation['colour']['value'])->toBe('Gold')
        ->and($item->personalisation_hash)->not->toBe('');
});

test('quote mode accepts the same inputs on add-to-list', function () {
    ['variant' => $variant] = personalisationPdpSite(LinePersonalisationFixtures::florist(), 'quote-pdp.example', 'quote');

    test()->post('http://quote-pdp.example/shop/cart/add', [
        'product_slug' => 'item',
        'variant_id' => $variant->id,
        'qty' => 1,
        'personalisation' => [
            'card-message' => 'With love',
        ],
    ])->assertRedirect('http://quote-pdp.example/shop/cart');

    expect(CartItem::first()->personalisation['card-message']['value'])->toBe('With love');
});

test('html in text is stored as plain text and escaped on the cart page', function () {
    ['variant' => $variant] = personalisationPdpSite(LinePersonalisationFixtures::bakery(), 'escape.example');
    $sessionId = 'escape-session';

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->post('http://escape.example/shop/cart/add', [
            'product_slug' => 'item',
            'variant_id' => $variant->id,
            'qty' => 1,
            'personalisation' => [
                'message' => '<script>alert(1)</script>',
            ],
        ])->assertRedirect();

    $html = test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->get('http://escape.example/shop/cart')
        ->assertOk()
        ->getContent();

    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->not->toContain('<script>alert(1)</script>');
});

test('a slug that is not on the product is refused', function () {
    ['variant' => $variant] = personalisationPdpSite(LinePersonalisationFixtures::bakery(), 'cross.example');

    test()->from('http://cross.example/products/item')
        ->post('http://cross.example/shop/cart/add', [
            'product_slug' => 'item',
            'variant_id' => $variant->id,
            'qty' => 1,
            'personalisation' => [
                'message' => 'Happy birthday',
                'engraving' => 'nope',
            ],
        ])
        ->assertSessionHasErrors();

    expect(CartItem::count())->toBe(0);
});
