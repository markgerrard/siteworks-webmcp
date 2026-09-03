<?php

use App\Enums\Shop\ProductStatus;
use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Shop\ProductImage;
use App\Models\Shop\ShopDraft;
use App\Models\Site;
use App\Services\Shop\Fulfilment\FulfilmentConfig;
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
    Storage::fake('public');
    Storage::fake('local');
});

/**
 * @return array<string, int>
 */
function demoPublishedPrices(): array
{
    return [
        'Cultured Butter Croissant' => 350,
        'Almond Croissant' => 425,
        'Cardamom Orange Morning Bun' => 400,
        'Chocolate Hazelnut Babka (loaf)' => 950,
        'Fig & Walnut Tart' => 550,
        'Meyer Lemon Tart' => 500,
        'Chocolate Caramel Tart' => 525,
        'Chocolate Espresso Birthday Cake (whole, serves 10)' => 3800,
        'Dark Chocolate Orange Macarons (box of 6)' => 1200,
        'Meyer Lemon Macarons (box of 6)' => 1200,
    ];
}

it('seeds site 64 with the photographed catalogue, empty fall category, quote form, and a catalogue revision', function () {
    $this->artisan('demo:seed')->assertSuccessful();

    $site = Site::query()->find(64);
    expect($site)->not->toBeNull()
        ->and($site->business_name)->toBe('Camino Bakehouse')
        ->and($site->shop_currency)->toBe('USD');

    $names = Category::query()->where('site_id', 64)->orderBy('sort_order')->orderBy('id')->pluck('name')->all();
    expect($names)->toBe(['Viennoiserie', 'Tarts & Cakes', 'Macarons', 'Seasonal — Fall']);

    $published = Product::query()
        ->where('site_id', 64)
        ->where('status', ProductStatus::Published)
        ->with(['variants', 'images'])
        ->get();
    expect($published)->toHaveCount(10)
        ->and(Product::query()->where('site_id', 64)->count())->toBe(10);

    $prices = demoPublishedPrices();
    foreach ($prices as $name => $cents) {
        $product = $published->firstWhere('name', $name);
        expect($product)->not->toBeNull($name)
            ->and((int) $product->variants->first()?->price_cents)->toBe($cents, $name);
    }

    $media = MediaStorage::disk();
    expect(MediaStorage::diskName())->toBe('public');
    foreach ($published as $product) {
        $image = $product->images->first();
        expect($image)->not->toBeNull($product->slug)
            ->and($image->path)->toBe('site-media/64/products/'.$product->slug.'.webp')
            ->and($media->exists($image->path))->toBeTrue($image->path)
            ->and($media->size($image->path))->toBeGreaterThan(50_000)
            ->and(MediaStorage::privateDisk()->exists($image->path))->toBeFalse($image->path);
    }

    $fig = $published->firstWhere('name', 'Fig & Walnut Tart');
    expect($fig?->slug)->toBe('fig-walnut-tart');

    $fall = Category::query()->where('site_id', 64)->where('slug', 'seasonal-fall')->first();
    expect($fall)->not->toBeNull()
        ->and($fall->name)->toBe('Seasonal — Fall')
        ->and((int) $fall->sort_order)->toBe(4)
        ->and($fall->products()->count())->toBe(0);

    $config = FulfilmentConfig::fromSite($site);
    expect($config)->not->toBeNull()
        ->and($config->enabledMethods())->not->toBeEmpty();

    $revision = ShopDraft::query()->where('site_id', 64)->value('catalogue_revision');
    expect($revision)->not->toBeNull()
        ->and((int) $revision)->toBeGreaterThan(0);
});

it('is a no-op when demo:seed runs a second time', function () {
    $this->artisan('demo:seed')->assertSuccessful();

    $snapshot = fn (): array => [
        'categories' => Category::query()->where('site_id', 64)->count(),
        'products' => Product::query()->where('site_id', 64)->count(),
        'images' => ProductImage::query()->whereIn('product_id', Product::query()->where('site_id', 64)->select('id'))->count(),
        'revision' => (int) ShopDraft::query()->where('site_id', 64)->value('catalogue_revision'),
        'slugs' => Product::query()->where('site_id', 64)->orderBy('slug')->pluck('slug')->all(),
        'fulfilment' => Site::query()->find(64)?->fulfilment,
    ];
    $before = $snapshot();
    expect($before['images'])->toBe(10);

    $this->artisan('demo:seed')->assertSuccessful();

    expect($snapshot())->toBe($before);
});
