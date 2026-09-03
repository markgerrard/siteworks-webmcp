<?php

use App\Models\Shop\Product;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Support\MediaStorage;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config()->set('demo.enabled', true);
    config()->set('demo.site_host', 'localhost');
    config()->set('demo.user_email', 'demo@camino.example');
    config()->set('demo.user_password', 'webmcp-demo');
    config()->set('app.url', 'http://app.localhost:8090');
    // The demo serves media from the public disk and keeps private media on local.
    config()->set('filesystems.media', 'public');
    config()->set('filesystems.media_private', 'local');
    Storage::fake('s3');
    Storage::fake('public');
    Storage::fake('local');
    $this->artisan('demo:seed')->assertSuccessful();
});

/**
 * @return list<string>
 */
function demoSeededSlugs(): array
{
    return [
        'cultured-butter-croissant',
        'almond-croissant',
        'cardamom-orange-morning-bun',
        'chocolate-hazelnut-babka',
        'fig-walnut-tart',
        'meyer-lemon-tart',
        'chocolate-caramel-tart',
        'chocolate-espresso-birthday-cake',
        'dark-chocolate-orange-macarons',
        'meyer-lemon-macarons',
    ];
}

it('renders photographed product cards on the demo shop index', function () {
    expect(ShopSnapshotCurrent::query()->where('site_id', 64)->exists())->toBeTrue();

    $html = $this->get('http://localhost/shop')->assertOk()->getContent();

    expect($html)->toContain('Cultured Butter Croissant')
        ->and($html)->toContain('Fig &amp; Walnut Tart')
        ->and($html)->toContain('Meyer Lemon Macarons (box of 6)')
        ->and($html)->toContain('$3.50')
        ->and($html)->toContain('$5.50')
        ->and($html)->toContain('$38')
        ->and($html)->toContain('$12')
        ->and($html)->toContain('Viennoiserie')
        ->and($html)->toContain('Tarts &amp; Cakes')
        ->and($html)->toContain('Macarons')
        ->and($html)->not->toContain('Our shop is being stocked');

    foreach (demoSeededSlugs() as $slug) {
        $src = MediaStorage::disk()->url('site-media/64/products/'.$slug.'.webp');
        expect($src)->toBe(Storage::disk('public')->url('site-media/64/products/'.$slug.'.webp'))
            ->and($html)->toMatch('#<img[^>]+src="'.preg_quote($src, '#').'"#', $slug);
    }
});

it('renders the product photo on a seeded product page', function () {
    $html = $this->get('http://localhost/products/fig-walnut-tart')->assertOk()->getContent();
    $src = MediaStorage::disk()->url('site-media/64/products/fig-walnut-tart.webp');

    expect($html)->toContain('Fig &amp; Walnut Tart')
        ->and($html)->toContain('$5.50')
        ->and($html)->toMatch('#<img[^>]+src="'.preg_quote($src, '#').'"#');
});

/**
 * Commits one draft into the seeded fall category the way the agent import does.
 *
 * @return array{0: \App\Models\Site, 1: \App\Models\Shop\Product}
 */
function demoImportOneDraft(): array
{
    $site = \App\Models\Site::query()->findOrFail(64);
    $user = \App\Models\User::query()->where('email', 'demo@camino.example')->firstOrFail();
    $revision = (int) \App\Models\Shop\ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision');

    $receipt = app(\App\Services\Shop\ProductImporter::class)->run($site, [
        'schema_version' => \App\Services\Shop\ProductImportContract::SCHEMA_VERSION,
        'format' => 'json',
        'data' => json_encode([[
            'name' => 'Pumpkin Spice Loaf',
            'primary_category_slug' => 'seasonal-fall',
            'variants' => [['sku' => 'PSL-1', 'price_pence' => 650]],
        ]], JSON_THROW_ON_ERROR),
        'catalogue_revision' => $revision,
        'dry_run' => false,
        'idempotency_key' => 'storefront-draft-visibility',
    ], $user->id, true);

    expect($receipt['created'])->toBe(1);
    $product = \App\Models\Shop\Product::query()->where('site_id', $site->id)->where('slug', $receipt['results'][0]['slug'])->firstOrFail();
    expect($product->status)->toBe(\App\Enums\Shop\ProductStatus::Draft);

    return [$site, $product];
}

it('keeps an imported draft off the storefront until a human publishes it', function () {
    [$site, $product] = demoImportOneDraft();

    $collection = $this->get('http://localhost/collections/seasonal-fall')->assertOk()->getContent();
    expect($collection)->not->toContain('Pumpkin Spice Loaf');
    $this->get('http://localhost/products/'.$product->slug)->assertNotFound();
    expect($this->get('http://localhost/shop')->assertOk()->getContent())->not->toContain('Pumpkin Spice Loaf');

    $written = app(\App\Services\Shop\ShopDraftWriter::class)->setStatusFromEditor($site, $product, \App\Enums\Shop\ProductStatus::Published);
    ($written['deferred'])();

    expect($this->get('http://localhost/collections/seasonal-fall')->assertOk()->getContent())->toContain('Pumpkin Spice Loaf');
    expect($this->get('http://localhost/products/'.$product->slug)->assertOk()->getContent())->toContain('Pumpkin Spice Loaf');
});

/**
 * The demo pins URL generation to APP_URL at boot; tests flip the demo flag
 * after boot, so the pin is applied here to render exactly what the live
 * container renders.
 */
function demoPinUrlRootToPortal(): void
{
    \Illuminate\Support\Facades\URL::forceRootUrl((string) config('app.url'));
}

it('keeps every storefront action on the storefront host, never the portal host', function () {
    demoPinUrlRootToPortal();
    $pages = [
        'http://localhost/shop',
        'http://localhost/collections/tarts-cakes',
        'http://localhost/products/fig-walnut-tart',
        'http://localhost/shop/cart',
    ];

    foreach ($pages as $url) {
        $html = $this->get($url)->assertOk()->getContent();

        expect($html)->not->toContain('app.localhost', $url);
    }

    $product = $this->get('http://localhost/products/fig-walnut-tart')->getContent();

    expect($product)->toContain('data-add-url="http://localhost:8090/shop/cart/add"')
        ->and($product)->toContain('data-cart-url="http://localhost:8090/shop/cart"')
        ->and($product)->toContain('data-checkout-url="http://localhost:8090/shop/quote"');
});

it('adds to the list and reaches the quote form on the storefront host', function () {
    demoPinUrlRootToPortal();
    $variant = Product::query()->where('site_id', 64)->where('slug', 'fig-walnut-tart')->firstOrFail()
        ->variants()->orderBy('id')->firstOrFail();

    $sessionId = 'storefront-host-list';
    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
        ->withCookie(\App\Http\Controllers\Shop\CartController::COOKIE_NAME, $sessionId)
        ->withHeader('Accept', 'application/json')
        ->post('http://localhost/shop/cart/add', [
            'product_slug' => 'fig-walnut-tart',
            'variant_id' => $variant->id,
            'qty' => 1,
        ])
        ->assertOk()
        ->assertJsonPath('count', 1);

    $this->withCookie(\App\Http\Controllers\Shop\CartController::COOKIE_NAME, $sessionId)
        ->get('http://localhost/shop/quote')
        ->assertOk();
});
