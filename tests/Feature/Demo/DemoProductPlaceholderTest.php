<?php

use App\Enums\Shop\ProductStatus;
use App\Models\Shop\Category;
use App\Models\Site;
use App\Services\Shop\ShopDraftWriter;
use Illuminate\Support\Facades\Storage;

/**
 * A product published without a photo renders a designed tile on the storefront,
 * from the product name alone, in place of an empty image box.
 */
beforeEach(function () {
    config()->set('demo.enabled', true);
    config()->set('demo.site_host', 'localhost');
    config()->set('demo.user_email', 'demo@camino.example');
    config()->set('demo.user_password', 'webmcp-demo');
    config()->set('app.url', 'http://app.localhost:8090');
    config()->set('filesystems.media', 'public');
    config()->set('filesystems.media_private', 'local');
    Storage::fake('s3');
    Storage::fake('public');
    Storage::fake('local');
    $this->artisan('demo:seed')->assertSuccessful();
});

function demoPublishImagelessProduct(string $name, string $sku): string
{
    $site = Site::query()->findOrFail(64);
    $category = Category::query()->where('site_id', 64)->where('slug', 'seasonal-fall')->firstOrFail();
    $writer = app(ShopDraftWriter::class);

    $created = $writer->createDraft($site, [
        'name' => $name,
        'category_id' => $category->id,
        'variants' => [['sku' => $sku, 'price_cents' => 650]],
    ]);
    ($created['deferred'])();
    ($writer->setStatusFromEditor($site, $created['product']->fresh(), ProductStatus::Published)['deferred'])();

    return (string) $created['product']->fresh()->slug;
}

it('renders a designed placeholder tile on the card and the product page when a product has no photo', function () {
    $slug = demoPublishImagelessProduct('Pumpkin Spice Loaf', 'FALL-1');

    $collection = $this->get('http://localhost/collections/seasonal-fall')->assertOk()->getContent();
    $page = $this->get('http://localhost/products/'.$slug)->assertOk()->getContent();

    foreach ([$collection, $page] as $html) {
        expect($html)->toContain('data-shop-image-placeholder')
            ->and($html)->toContain('Photo coming soon')
            ->and($html)->toContain('var(--color-band)')
            ->and($html)->toContain('var(--font-display)')
            ->and($html)->not->toContain('src=""')
            ->and($html)->not->toMatch('/<img[^>]*src="\s*"/');
    }

    preg_match('/<div[^>]*data-shop-image-placeholder[^>]*>.*?<\/div>/s', $collection, $tile);
    expect($tile[0] ?? '')->toContain('Pumpkin Spice Loaf');
});

it('leaves a photographed product alone: no placeholder tile beside its image', function () {
    $page = $this->get('http://localhost/products/fig-walnut-tart')->assertOk()->getContent();

    expect($page)->toContain('/storage/site-media/64/products/')
        ->and($page)->not->toContain('data-shop-image-placeholder')
        ->and($page)->not->toContain('Photo coming soon');
});
