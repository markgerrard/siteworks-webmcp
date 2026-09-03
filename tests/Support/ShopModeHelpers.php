<?php

use App\Enums\Shop\OrderStatus;
use App\Enums\Shop\ProductStatus;
use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use Illuminate\Support\Facades\URL;

/**
 * @param  list<array{label: string, price: int, stock: int}>  $variantSpecs
 * @return array{0: Site, 1: Product, 2: list<ProductVariant>}
 */
function shopModeMatrixSite(
    string $host,
    string $shopMode,
    array $variantSpecs = [['label' => 'Cake', 'price' => 8500, 'stock' => 4]],
    string $slug = 'conserve',
    string $name = 'Strawberry Conserve',
): array {
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'shop_mode' => $shopMode,
        'shop_currency' => 'GBP',
        'business_name' => 'Matrix Bakery',
    ]);

    $product = Product::factory()->for($site)->create([
        'slug' => $slug,
        'name' => $name,
        'status' => ProductStatus::Published,
    ]);

    $variants = [];
    foreach ($variantSpecs as $i => $spec) {
        $variant = ProductVariant::factory()->for($product)->create([
            'sku' => 'MX-'.$i,
            'label' => $spec['label'],
            'price_cents' => $spec['price'],
        ]);
        VariantStock::create(['variant_id' => $variant->id, 'on_hand' => $spec['stock']]);
        $variants[] = $variant;
    }

    $inStockAny = collect($variantSpecs)->contains(fn ($spec) => $spec['stock'] > 0);
    $priceDisplay = '£'.number_format($variantSpecs[0]['price'] / 100, 2);

    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'product_count' => 1,
        'json' => [
            'meta' => ['site_id' => $site->id, 'product_count' => 1, 'currency' => 'GBP'],
            'categories' => [
                'cakes' => [
                    'id' => 1,
                    'slug' => 'cakes',
                    'name' => 'Cakes',
                    'product_slugs' => [$slug],
                ],
            ],
            'products' => [
                $slug => [
                    'id' => $product->id,
                    'slug' => $slug,
                    'status' => 'published',
                    'primary_category_slug' => 'cakes',
                    'price_cents' => $variantSpecs[0]['price'],
                    'price_display' => $priceDisplay,
                    'in_stock_any' => $inStockAny,
                    'variant_in_stock' => collect($variants)->mapWithKeys(
                        fn ($v, $i) => [$v->id => $variantSpecs[$i]['stock'] > 0]
                    )->all(),
                    'image_urls' => ['thumb' => '/cake-thumb.jpg', 'card' => '/cake-card.jpg', 'full' => '/cake-full.jpg'],
                    'product_card' => [
                        'slug' => $slug,
                        'name' => $name,
                        'price_display' => $priceDisplay,
                    ],
                    'product_detail' => ['slug' => $slug, 'name' => $name, 'description' => 'A cake'],
                    'variants' => collect($variants)->map(fn ($v) => [
                        'id' => $v->id, 'sku' => $v->sku, 'label' => $v->label,
                        'price_cents' => $v->price_cents, 'image_urls' => null,
                    ])->all(),
                    'is_ai_seeded' => false,
                    'is_ai_reviewed' => false,
                ],
            ],
            'featured_slugs' => [$slug],
        ],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snap->id, 'updated_at' => now()]);

    return [$site, $product, $variants];
}

function shopModeMatrixGet(string $host, string $path): string
{
    return test()->get('http://'.$host.$path)->assertOk()->getContent();
}

/**
 * @param  array{status?: OrderStatus, expires_at?: DateTimeInterface|null, number?: string, customer_id?: int|null}  $overrides
 */
function shopModeMatrixOrder(Site $site, array $overrides = []): Order
{
    return Order::create([
        'site_id' => $site->id,
        'number' => $overrides['number'] ?? 'MX-'.uniqid(),
        'email' => 'buyer@example.com',
        'name' => 'Buyer',
        'customer_id' => $overrides['customer_id'] ?? null,
        'status' => ($overrides['status'] ?? OrderStatus::Pending)->value,
        'refund_status' => 'none',
        'subtotal_cents' => 900,
        'shipping_cents' => 0,
        'tax_cents' => 0,
        'shipping_tax_cents' => 0,
        'total_cents' => 900,
        'tax_country_code' => 'GB',
        'shipping_address_json' => [],
        'shipping_method_label' => 'Std',
        'placed_at' => now(),
        'expires_at' => array_key_exists('expires_at', $overrides) ? $overrides['expires_at'] : now()->addHour(),
    ]);
}

function shopModeByteFixturePath(string $name): string
{
    return base_path('tests/Fixtures/shop-mode/'.$name);
}

function shopModeByteNormalise(string $html): string
{
    $html = preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value="__CSRF__"', $html) ?? $html;
    $html = preg_replace('/data-csrf="[^"]*"/', 'data-csrf="__CSRF__"', $html) ?? $html;
    $html = preg_replace('/name="quote_token" value="[^"]*"/', 'name="quote_token" value="__QUOTE_TOKEN__"', $html) ?? $html;
    $html = preg_replace('/<input type="text" name="[a-f0-9]{8}" tabindex="-1"/', '<input type="text" name="__HONEYPOT__" tabindex="-1"', $html) ?? $html;
    $html = preg_replace('/name="variant_id" value="\d+"/', 'name="variant_id" value="__VID__"', $html) ?? $html;
    $html = preg_replace('/value="\d+"(\s+name="variant_id")/', 'value="__VID__"$1', $html) ?? $html;
    $html = preg_replace('/<option\s+value="\d+"/', '<option value="__VID__"', $html) ?? $html;
    $html = preg_replace('#action="/shop/cart/\d+"#', 'action="/shop/cart/__ID__"', $html) ?? $html;
    $html = preg_replace('/site-[A-Za-z0-9_-]+\.css/', 'site-__HASH__.css', $html) ?? $html;

    return $html;
}

function shopModeByteForceHost(string $host): void
{
    URL::forceRootUrl('http://'.$host);
    URL::forceScheme('http');
}

function shopModeByteSnapshotProduct(Site $site, string $slug = 'conserve'): array
{
    $json = ShopSnapshot::query()->where('site_id', $site->id)->value('json');

    return is_array($json) ? ($json['products'][$slug] ?? []) : [];
}

function shopModeByteAssert(string $name, string $html): void
{
    $html = shopModeByteNormalise($html);
    $path = shopModeByteFixturePath($name);

    if (getenv('BYTE_IDENTITY_SEED') === '1') {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, $html);
        expect(file_exists($path))->toBeTrue();

        return;
    }

    expect(file_exists($path))->toBeTrue("Missing fixture {$path}");
    expect($html)->toBe(shopModeByteNormalise((string) file_get_contents($path)), "{$name} drifted from the cart/enquire baseline");
}

function shopModeBytePdpAddRegion(string $html): string
{
    if (preg_match('#<form\b[^>]*action="[^"]*/shop/cart/add"[^>]*>.*?</form>#s', $html, $match) === 1) {
        return $match[0];
    }
    if (preg_match('#<a\b[^>]*href="/enquire\?product=[^"]+"[^>]*>.*?</a>#s', $html, $match) === 1) {
        return $match[0];
    }

    return '';
}
