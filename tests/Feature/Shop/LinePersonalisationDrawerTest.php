<?php

use App\Http\Controllers\Shop\CartController;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\CartItem;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Services\Shop\CartService;
use App\Services\Shop\LinePersonalisation;
use App\Services\Shop\SnapshotBuilder;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LinePersonalisationFixtures;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(config('filesystems.default'));
});

test('drawer json includes personalisation on the item id not the variant id', function () {
    $site = Site::factory()->create(['custom_domain' => 'drawer.example', 'custom_domain_status' => 'active']);
    $product = Product::factory()->published()->for($site)->create([
        'slug' => 'item',
        'customer_inputs' => LinePersonalisationFixtures::generic(),
    ]);
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 1500]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 10]);
    $sessionId = 'drawer-p';
    $cart = app(CartService::class)->getOrCreate($site->id, $sessionId);
    $frozen = LinePersonalisation::freeze(LinePersonalisationFixtures::generic(), [
        'engraving' => 'Ada',
        'colour' => 'Gold',
    ]);
    $item = app(CartService::class)->addItem($cart, $variant->id, 1, $frozen);

    $payload = test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->withHeaders(['Accept' => 'application/json'])
        ->get('http://drawer.example/shop/cart')
        ->assertSuccessful()
        ->json();

    $rows = collect($payload['items'][0]['personalisation']);
    expect($payload['items'][0]['id'])->toBe($item->id)
        ->and($payload['items'][0])->not->toHaveKey('variant_id')
        ->and($rows->pluck('label')->all())->toContain('Engraving')
        ->and($rows->firstWhere('slug', 'engraving')['display'])->toBe('Ada');
});

test('editing a line updates frozen values', function () {
    $site = Site::factory()->create(['custom_domain' => 'edit.example', 'custom_domain_status' => 'active']);
    $product = Product::factory()->published()->for($site)->create([
        'slug' => 'item',
        'customer_inputs' => LinePersonalisationFixtures::generic(),
    ]);
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 1500]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 10]);
    $sessionId = 'edit-p';
    $cart = app(CartService::class)->getOrCreate($site->id, $sessionId);
    $item = app(CartService::class)->addItem($cart, $variant->id, 1, LinePersonalisation::freeze(LinePersonalisationFixtures::generic(), [
        'engraving' => 'Ada',
        'colour' => 'Gold',
    ]));

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->patch('http://edit.example/shop/cart/'.$item->id.'/personalisation', [
            'personalisation' => [
                'engraving' => 'Bob',
                'colour' => 'Silver',
            ],
        ])
        ->assertRedirect('http://edit.example/shop/cart');

    $item->refresh();
    expect($item->personalisation['engraving']['value'])->toBe('Bob')
        ->and($item->personalisation['colour']['value'])->toBe('Silver');
});

test('editing uses the complete frozen definition and keeps omitted optional inputs', function () {
    $site = Site::factory()->create(['custom_domain' => 'frozen-edit.example', 'custom_domain_status' => 'active']);
    $product = Product::factory()->published()->for($site)->create([
        'slug' => 'item',
        'customer_inputs' => LinePersonalisationFixtures::bakery(),
    ]);
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 1500]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 10]);
    $sessionId = 'frozen-edit-p';
    $cart = app(CartService::class)->getOrCreate($site->id, $sessionId);
    $item = app(CartService::class)->addItem($cart, $variant->id, 1, LinePersonalisation::freeze(LinePersonalisationFixtures::bakery(), [
        'message' => 'Happy birthday',
    ]));

    $product->update(['customer_inputs' => [[
        'slug' => 'message',
        'label' => 'Changed message',
        'kind' => 'text',
        'required' => true,
        'max_chars' => 1,
        'pattern' => 'letters-digits-spaces',
        'help' => '',
    ]]]);

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->patch('http://frozen-edit.example/shop/cart/'.$item->id.'/personalisation', [])
        ->assertRedirect('http://frozen-edit.example/shop/cart');

    $item->refresh();
    expect($item->personalisation['message']['label'])->toBe('Message on the cake')
        ->and($item->personalisation['message']['max_chars'])->toBe(80)
        ->and($item->personalisation['message']['pattern'])->toBe('no-emoji')
        ->and($item->personalisation['photo']['value'])->toBeNull();
});

test('the cart edit form is prefilled from the stored line values', function () {
    $site = Site::factory()->create(['custom_domain' => 'cart-prefill.example', 'custom_domain_status' => 'active']);
    $product = Product::factory()->published()->for($site)->create([
        'slug' => 'item',
        'customer_inputs' => LinePersonalisationFixtures::bakery(),
    ]);
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 1500]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 10]);
    $sessionId = 'cart-prefill-p';
    $cart = app(CartService::class)->getOrCreate($site->id, $sessionId);
    app(CartService::class)->addItem($cart, $variant->id, 1, LinePersonalisation::freeze(LinePersonalisationFixtures::bakery(), [
        'message' => 'Happy birthday',
    ]));

    $html = test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->get('http://cart-prefill.example/shop/cart')
        ->assertSuccessful()
        ->getContent();

    expect($html)->toContain('value="Happy birthday"');
});

test('deleting a line deletes its images', function () {
    $site = Site::factory()->create(['custom_domain' => 'del.example', 'custom_domain_status' => 'active']);
    $product = Product::factory()->published()->for($site)->create([
        'slug' => 'item',
        'customer_inputs' => LinePersonalisationFixtures::bakery(),
    ]);
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 1500]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 10]);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));
    $sessionId = 'del-p';

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->post('http://del.example/shop/cart/add', [
            'product_slug' => 'item',
            'variant_id' => $variant->id,
            'qty' => 1,
            'personalisation' => [
                'message' => 'Happy birthday',
                'photo' => [LinePersonalisationFixtures::jpeg(20, 20)],
            ],
        ])
        ->assertRedirect();

    $item = CartItem::first();
    $path = $item->personalisation['photo']['value'][0]['path'];
    expect(Storage::disk(config('filesystems.default'))->exists($path))->toBeTrue();

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->delete('http://del.example/shop/cart/'.$item->id)
        ->assertRedirect();

    expect(CartItem::find($item->id))->toBeNull()
        ->and(Storage::disk(config('filesystems.default'))->exists($path))->toBeFalse();
});
