<?php

use App\Enums\Shop\ProductStatus;
use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\Product;
use App\Models\Shop\ProductImage;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Support\ShopMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;

uses(RefreshDatabase::class);

/**
 * @param  list<array{name: string, slug: string, status?: ProductStatus, price?: int, priceFrom?: bool, image?: string|null}>  $catalogue
 * @return array{site: Site, products: list<Product>}
 */
function shopSearchJsonSite(string $host, array $catalogue, string $shopMode = 'cart', string $currency = 'GBP', bool $previewHost = false): array
{
    $attrs = [
        'custom_domain_status' => 'active',
        'shop_mode' => $shopMode,
        'shop_currency' => $currency,
    ];
    if ($previewHost) {
        $attrs['preview_domain'] = $host;
        $attrs['custom_domain'] = 'public-'.$host;
    } else {
        $attrs['custom_domain'] = $host;
    }

    $site = Site::factory()->create($attrs);
    $products = [];
    $snapshotProducts = [];

    foreach ($catalogue as $row) {
        $product = Product::factory()->for($site)->create([
            'name' => $row['name'],
            'slug' => $row['slug'],
            'status' => $row['status'] ?? ProductStatus::Published,
            'price_from' => $row['priceFrom'] ?? false,
        ]);
        $variant = ProductVariant::factory()->for($product)->create([
            'sku' => strtoupper($row['slug']).'-1',
            'label' => 'Each',
            'price_cents' => $row['price'] ?? 450,
        ]);
        VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 4]);

        $imageUrl = null;
        if (array_key_exists('image', $row) && is_string($row['image'])) {
            ProductImage::create([
                'product_id' => $product->id,
                'path' => $row['image'],
                'sort_order' => 0,
                'alt' => $row['name'],
            ]);
            $imageUrl = $product->fresh()->images->first()?->url('thumb');
        }

        $priceDisplay = ShopMoney::display(
            $variant->price_cents,
            $currency,
            (bool) $product->price_from,
        );

        $snapshotProducts[$product->slug] = [
            'id' => $product->id,
            'slug' => $product->slug,
            'status' => $product->status->value,
            'primary_category_slug' => null,
            'price_cents' => $variant->price_cents,
            'price_display' => $priceDisplay,
            'in_stock_any' => true,
            'variant_in_stock' => [$variant->id => true],
            'image_urls' => $imageUrl ? ['thumb' => $imageUrl, 'card' => $imageUrl, 'full' => $imageUrl] : null,
            'product_card' => ['slug' => $product->slug, 'name' => $product->name, 'price_display' => $priceDisplay, 'price_from' => (bool) $product->price_from],
            'product_detail' => ['slug' => $product->slug, 'name' => $product->name, 'description' => ''],
            'variants' => [['id' => $variant->id, 'sku' => $variant->sku, 'label' => $variant->label, 'price_cents' => $variant->price_cents, 'image_urls' => null]],
            'is_ai_seeded' => false,
            'is_ai_reviewed' => false,
        ];
        $products[] = $product->fresh(['variants', 'images']);
    }

    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'product_count' => count($snapshotProducts),
        'json' => [
            'meta' => ['site_id' => $site->id, 'product_count' => count($snapshotProducts), 'currency' => $currency],
            'categories' => [],
            'products' => $snapshotProducts,
            'featured_slugs' => [],
        ],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create([
        'site_id' => $site->id,
        'snapshot_id' => $snap->id,
        'updated_at' => now(),
    ]);

    return ['site' => $site->fresh(), 'products' => $products];
}

test('json search returns the live-result shape for a matching query', function () {
    shopSearchJsonSite('json-search.example', [
        ['name' => 'Damson Conserve', 'slug' => 'damson', 'price' => 595, 'image' => 'shop/damson.jpg'],
        ['name' => 'White Lily', 'slug' => 'lily', 'price' => 1200],
    ]);

    $response = $this->getJson('http://json-search.example/shop/search?q=Damson')->assertSuccessful();
    $payload = $response->json();

    expect($payload['query'])->toBe('Damson')
        ->and($payload['count'])->toBe(1)
        ->and($payload['see_all_url'])->toContain('/shop/search')
        ->and($payload['see_all_url'])->toContain('q=Damson')
        ->and($payload['results'])->toHaveCount(1);

    $row = $payload['results'][0];
    expect($row)->toHaveKeys(['name', 'slug', 'url', 'price_display', 'image_url'])
        ->and($row['name'])->toBe('Damson Conserve')
        ->and($row['slug'])->toBe('damson')
        ->and($row['url'])->toContain('/products/damson')
        ->and($row['price_display'])->toBe(ShopMoney::display(595, 'GBP'))
        ->and($row['image_url'])->not->toBeNull();
});

test('json search caps results at five but reports the full count', function () {
    $catalogue = [];
    foreach (range(1, 7) as $i) {
        $catalogue[] = ['name' => "Damson Jar {$i}", 'slug' => "damson-{$i}", 'price' => 400 + $i];
    }
    shopSearchJsonSite('json-cap.example', $catalogue);

    $payload = $this->getJson('http://json-cap.example/shop/search?q=Damson')->assertSuccessful()->json();

    expect($payload['count'])->toBe(7)
        ->and($payload['results'])->toHaveCount(5);
});

test('json search hides drafts on the public host and shows them on the preview host', function () {
    shopSearchJsonSite('json-draft-public.example', [
        ['name' => 'Damson Live', 'slug' => 'damson-live', 'status' => ProductStatus::Published],
        ['name' => 'Damson Draft', 'slug' => 'damson-draft', 'status' => ProductStatus::Draft],
    ]);

    $public = $this->getJson('http://json-draft-public.example/shop/search?q=Damson')->assertSuccessful()->json();
    expect(collect($public['results'])->pluck('slug')->all())->toBe(['damson-live'])
        ->and($public['count'])->toBe(1);

    shopSearchJsonSite('json-draft-preview.example', [
        ['name' => 'Damson Live', 'slug' => 'damson-live', 'status' => ProductStatus::Published],
        ['name' => 'Damson Draft', 'slug' => 'damson-draft', 'status' => ProductStatus::Draft],
    ], previewHost: true);

    $preview = $this->getJson('http://json-draft-preview.example/shop/search?q=Damson')->assertSuccessful()->json();
    expect(collect($preview['results'])->pluck('slug')->sort()->values()->all())->toBe(['damson-draft', 'damson-live'])
        ->and($preview['count'])->toBe(2);
});

test('json price_display matches the shop price component for cart and enquire-from modes', function (string $mode, string $currency, bool $priceFrom, int $cents) {
    $host = 'json-price-'.$mode.'-'.strtolower($currency).'.example';
    shopSearchJsonSite($host, [
        ['name' => 'Damson Cake', 'slug' => 'damson-cake', 'price' => $cents, 'priceFrom' => $priceFrom],
    ], shopMode: $mode, currency: $currency);

    $payload = $this->getJson('http://'.$host.'/shop/search?q=Damson')->assertSuccessful()->json();
    $expected = ShopMoney::display($cents, $currency, $priceFrom);
    $vat = $currency === 'GBP';
    $rendered = Blade::render('<x-shop.price :amount="$amount" :vat="$vat" />', [
        'amount' => $expected,
        'vat' => $vat,
    ]);

    expect($payload['results'][0]['price_display'])->toBe($expected)
        ->and($rendered)->toContain($expected);
})->with([
    'cart gbp' => ['cart', 'GBP', false, 595],
    'enquire from usd' => ['enquire', 'USD', true, 8500],
]);

test('json search trims the query to 100 characters', function () {
    shopSearchJsonSite('json-trim.example', [
        ['name' => 'Damson Conserve', 'slug' => 'damson'],
    ]);

    $long = str_repeat('a', 150);
    $payload = $this->getJson('http://json-trim.example/shop/search?q='.$long)->assertSuccessful()->json();

    expect($payload['query'])->toHaveLength(100)
        ->and($payload['see_all_url'])->toContain(urlencode(str_repeat('a', 100)));
});

test('an empty json query returns count 0 and no results', function () {
    shopSearchJsonSite('json-empty.example', [
        ['name' => 'Damson Conserve', 'slug' => 'damson'],
    ]);

    $payload = $this->getJson('http://json-empty.example/shop/search')->assertSuccessful()->json();

    expect($payload['query'])->toBe('')
        ->and($payload['count'])->toBe(0)
        ->and($payload['results'])->toBe([]);
});
