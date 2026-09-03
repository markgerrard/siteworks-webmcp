<?php

use App\Http\Controllers\Shop\CartController;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Mail\SiteEnquiryReceived;
use App\Models\Shop\OrderItem;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShippingRate;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Models\SiteEnquiry;
use App\Services\Shop\CartService;
use App\Services\Shop\CheckoutService;
use App\Services\Shop\LinePersonalisation;
use App\Services\Shop\SnapshotBuilder;
use Database\Seeders\Shop\TaxClassSeeder;
use Database\Seeders\Shop\TaxRateSeeder;
use Illuminate\Support\Facades\Mail;
use Tests\Support\LinePersonalisationFixtures;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('quote snapshot and sent page carry personalisation', function () {
    Mail::fake();
    $host = 'q-p.example';
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'shop_mode' => 'quote',
        'enquiry_notification_email' => 'owner@example.test',
        'business_name' => 'Quote Shop',
    ]);
    $product = Product::factory()->published()->for($site)->create([
        'name' => 'Item',
        'slug' => 'item',
        'customer_inputs' => LinePersonalisationFixtures::generic(),
    ]);
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 1850, 'label' => 'Std']);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 10]);
    $sessionId = 'quote-p';
    $cart = app(CartService::class)->getOrCreate($site->id, $sessionId);
    app(CartService::class)->addItem($cart, $variant->id, 1, LinePersonalisation::freeze(LinePersonalisationFixtures::generic(), [
        'engraving' => 'Ada',
        'colour' => 'Gold',
    ]));

    $html = test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->get('http://'.$host.'/shop/quote')
        ->assertOk()
        ->getContent();
    expect($html)->toContain('Engraving')->toContain('Ada');

    $honeypot = $site->enquiryHoneypotFieldName();
    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->post('http://'.$host.'/shop/quote', [
            'name' => 'Sam',
            'email' => 'sam@example.test',
            $honeypot => '',
            'message' => 'Please quote',
        ])
        ->assertRedirect();

    $enquiry = SiteEnquiry::first();
    expect($enquiry->payload['lines'][0]['personalisation']['engraving']['value'])->toBe('Ada');

    $sent = test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->get('http://'.$host.'/shop/quote/sent')
        ->assertOk()
        ->getContent();
    expect($sent)->toContain('Ada');

    Mail::assertQueued(SiteEnquiryReceived::class, function (SiteEnquiryReceived $mail) {
        $html = $mail->render();

        return str_contains($html, 'Ada') && str_contains($html, 'Gold');
    });
});

test('checkout order lines copy personalisation', function () {
    $this->seed(TaxClassSeeder::class);
    $this->seed(TaxRateSeeder::class);
    $site = Site::factory()->create();
    ShippingRate::create([
        'site_id' => $site->id,
        'strategy' => 'flat_with_free_threshold',
        'flat_amount_cents' => 500,
        'free_threshold_cents' => 10000,
        'method_label' => 'Post',
    ]);
    $product = Product::factory()->for($site)->create([
        'name' => 'Item',
        'customer_inputs' => LinePersonalisationFixtures::generic(),
    ]);
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 2500, 'sku' => 'I1', 'label' => 'Std']);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 10]);
    $cart = app(CartService::class)->getOrCreate($site->id, 'ord-p');
    app(CartService::class)->addItem($cart, $variant->id, 1, LinePersonalisation::freeze(LinePersonalisationFixtures::generic(), [
        'engraving' => 'Ada',
        'colour' => 'Silver',
    ]));

    $order = app(CheckoutService::class)->start($cart, [
        'email' => 'ada@example.test',
        'name' => 'Ada',
        'phone' => '000',
        'country_code' => 'GB',
        'line1' => '1 High St',
        'city' => 'London',
        'postcode' => 'N1 1AA',
    ]);

    $line = OrderItem::where('order_id', $order->id)->first();
    expect($line->personalisation['engraving']['value'])->toBe('Ada')
        ->and($line->personalisation['colour']['value'])->toBe('Silver');
});

test('rebuild snapshot includes customer_inputs', function () {
    $site = Site::factory()->create();
    Product::factory()->published()->for($site)->create([
        'slug' => 'item',
        'customer_inputs' => LinePersonalisationFixtures::bakery(),
    ]);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));
    $json = app(SnapshotBuilder::class)->build($site->id);

    expect($json['products']['item']['customer_inputs'][0]['slug'])->toBe('message');
});
