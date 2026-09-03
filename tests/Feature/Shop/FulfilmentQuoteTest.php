<?php

use App\Http\Controllers\Shop\CartController;
use App\Mail\SiteEnquiryReceived;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Models\SiteEnquiry;
use App\Models\User;
use App\Services\Shop\CartService;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\FulfilmentFixtures;

/**
 * @return array{site: Site, host: string, sessionId: string}
 */
function fulfilmentQuoteSite(string $host, ?array $fulfilment): array
{
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'shop_mode' => 'quote',
        'shop_currency' => 'GBP',
        'business_name' => 'Quote Bakery',
        'enquiry_notification_email' => 'owner@'.$host,
        'fulfilment' => $fulfilment,
        'created_by_user_id' => User::factory()->staff()->create()->id,
    ]);

    $product = Product::factory()->published()->for($site)->create([
        'name' => 'Victoria Sponge',
        'slug' => 'victoria',
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'label' => 'Small',
        'price_cents' => 1850,
    ]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 10]);

    $sessionId = 'quote-fulfilment-'.$host;
    $cart = app(CartService::class)->getOrCreate($site->id, $sessionId);
    app(CartService::class)->addItem($cart, $variant->id, 1);

    return compact('site', 'host', 'sessionId');
}

test('quote form omits fulfilment fields when the site has no config', function () {
    ['host' => $host, 'sessionId' => $sessionId] = fulfilmentQuoteSite('quote-none.example', null);

    $html = test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->get('http://'.$host.'/shop/quote')
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('data-testid="quote-fulfilment-fields"')
        ->and($html)->not->toContain('name="fulfilment_postcode"');
});

test('quote form shows enabled methods, stores the snapshot, and surfaces it on sent, inbox and mail', function () {
    Mail::fake();
    ['host' => $host, 'sessionId' => $sessionId, 'site' => $site] = fulfilmentQuoteSite('quote-ful.example', FulfilmentFixtures::camino());

    $html = html_entity_decode(
        test()->withCookie(CartController::COOKIE_NAME, $sessionId)
            ->get('http://'.$host.'/shop/quote')
            ->assertOk()
            ->getContent(),
        ENT_QUOTES | ENT_HTML5,
    );

    expect($html)->toContain('data-testid="quote-fulfilment-fields"')
        ->and($html)->toContain('name="fulfilment_postcode"')
        ->and($html)->toContain('Local delivery')
        ->and($html)->toContain('Click & collect')
        ->and($html)->not->toContain('value="shipping"');

    $honeypot = $site->enquiryHoneypotFieldName();
    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->post('http://'.$host.'/shop/quote', [
            'name' => 'Ava O\'Neil',
            'email' => 'ava@quote.example',
            'phone' => '01234 567890',
            'needed_by' => now()->addDay()->toDateString(),
            'message' => 'Saturday please.',
            'fulfilment_method' => 'delivery',
            'fulfilment_postcode' => 'SW1A 1AA',
            $honeypot => '',
        ])
        ->assertRedirect('http://'.$host.'/shop/quote/sent');

    $enquiry = SiteEnquiry::query()->sole();
    expect($enquiry->payload['fulfilment'])->toMatchArray([
        'method' => 'delivery',
        'label' => 'Local delivery',
        'postcode' => 'SW1A1AA',
        'zone_name' => 'Inner',
    ]);

    $sent = test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->get('http://'.$host.'/shop/quote/sent')
        ->assertOk()
        ->getContent();
    expect($sent)->toContain('data-testid="quote-fulfilment"')
        ->and($sent)->toContain('SW1A1AA')
        ->and($sent)->toContain('Inner');

    $inbox = Livewire::actingAs(User::query()->find($site->created_by_user_id))
        ->test('enquiries-inbox', ['siteId' => $site->id])
        ->html();
    expect($inbox)->toContain('SW1A1AA')
        ->and($inbox)->toContain('Inner');

    Mail::assertQueued(SiteEnquiryReceived::class, function (SiteEnquiryReceived $mail) use ($enquiry): bool {
        $html = $mail->render();

        return $mail->enquiry->is($enquiry)
            && str_contains($html, 'SW1A1AA')
            && str_contains($html, 'Inner')
            && ! str_contains($html, 'fee_cents');
    });
});
