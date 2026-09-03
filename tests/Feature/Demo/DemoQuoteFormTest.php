<?php

use App\Http\Controllers\Shop\CartController;
use App\Models\Shop\Product;
use App\Models\Site;
use App\Models\SiteEnquiry;
use App\Models\User;
use App\Services\Shop\CartService;
use App\Services\Shop\Fulfilment\FulfilmentConfig;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config()->set('demo.enabled', true);
    config()->set('demo.site_host', 'localhost');
    config()->set('demo.user_email', 'demo@camino.example');
    config()->set('demo.user_password', 'webmcp-demo');
    config()->set('app.url', 'http://app.localhost:8090');
    Storage::fake('s3');
    Storage::fake(config('filesystems.default'));
    $this->artisan('demo:seed')->assertSuccessful();
});

function demoQuoteFilledCart(): string
{
    $product = Product::query()->where('site_id', 64)->where('slug', 'fig-walnut-tart')->firstOrFail();
    $variant = $product->variants()->orderBy('id')->firstOrFail();
    $sessionId = 'demo-quote-session';
    $cart = app(CartService::class)->getOrCreate(64, $sessionId);
    app(CartService::class)->addItem($cart, $variant->id, 1);

    return $sessionId;
}

it('renders /shop/quote with the bakery quote fields and an enabled fulfilment method', function () {
    $sessionId = demoQuoteFilledCart();
    $site = Site::query()->findOrFail(64);
    $config = FulfilmentConfig::fromSite($site);

    expect($config)->not->toBeNull()
        ->and($config->enabledMethods())->not->toBe([]);

    $html = $this->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->get('http://localhost/shop/quote')
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Name')
        ->and($html)->toContain('Email')
        ->and($html)->toContain('Phone')
        ->and($html)->toContain('Occasion')
        ->and($html)->toContain('what it')
        ->and($html)->toContain('Number of people')
        ->and($html)->toContain('Flavour')
        ->and($html)->toContain('Pickup date')
        ->and($html)->toContain('Budget')
        ->and($html)->toContain('Message on top')
        ->and($html)->toContain('Notes');
});

it('mounts the storefront WebMCP quote tools on /shop/quote', function () {
    $sessionId = demoQuoteFilledCart();

    $html = $this->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->get('http://localhost/shop/quote')
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data-webmcp-quote-form')
        ->and($html)->toContain('data-business-name="Camino Bakehouse"')
        ->and($html)->toContain('data-webmcp-quote-hint')
        ->and($html)->toContain('siteworks.get_quote_form')
        ->and($html)->toContain('siteworks.prefill_quote')
        ->and($html)->toContain('bootQuoteFormTools();')
        ->and($html)->not->toContain("\nexport ");
});

it('stores a quote enquiry that the portal enquiries inbox can show', function () {
    $sessionId = demoQuoteFilledCart();
    $site = Site::query()->findOrFail(64);
    $user = User::query()->where('email', 'demo@camino.example')->firstOrFail();
    $honeypot = $site->enquiryHoneypotFieldName();

    $response = $this->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->post('http://localhost/shop/quote', [
            'name' => 'Ava Baker',
            'email' => 'ava@quote.example',
            'phone' => '650-555-0142',
            'occasion' => 'Birthday',
            'people_count' => '12',
            'flavour' => 'Fig & walnut',
            'pickup_date' => now()->addDays(4)->toDateString(),
            'budget' => '$80',
            'message_on_top' => 'Happy birthday Maya',
            'notes' => 'Nut-free if possible.',
            'needed_by' => now()->addDays(4)->toDateString(),
            'message' => 'Please quote for Saturday pickup.',
            $honeypot => '',
        ]);

    expect($response->isRedirect())->toBeTrue()
        ->and($response->headers->get('Location'))->toContain('/shop/quote/sent');

    $enquiry = SiteEnquiry::query()->where('site_id', 64)->latest('id')->first();
    expect($enquiry)->not->toBeNull()
        ->and($enquiry->name)->toBe('Ava Baker')
        ->and($enquiry->email)->toBe('ava@quote.example')
        ->and($enquiry->payload['kind'] ?? null)->toBe('quote')
        ->and($enquiry->payload['occasion'] ?? null)->toBe('Birthday')
        ->and($enquiry->payload['notes'] ?? null)->toBe('Nut-free if possible.');

    $this->actingAs($user)
        ->get(route('client.portal.enquiries', ['site' => $site]))
        ->assertOk()
        ->assertSee('Ava Baker')
        ->assertSee('ava@quote.example')
        ->assertSee('Please quote for Saturday pickup.')
        ->assertSee('Birthday');
});
