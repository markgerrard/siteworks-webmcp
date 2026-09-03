<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  list<array{label: string, price: int, stock: int}>  $variantSpecs
 * @return array{0: Site, 1: Product, 2: list<ProductVariant>}
 */
function productPageSite(array $variantSpecs, ?string $categorySlug = 'preserves', bool $withImage = true, bool $inStockAny = true): array
{
    $site = Site::factory()->create([
        'custom_domain' => 'flowers.example',
        'custom_domain_status' => 'active',
    ]);

    $product = Product::factory()->for($site)->create(['slug' => 'conserve', 'name' => 'Strawberry Conserve']);

    $variants = [];
    foreach ($variantSpecs as $i => $spec) {
        $variant = ProductVariant::factory()->for($product)->create([
            'sku' => 'SC-'.$i,
            'label' => $spec['label'],
            'price_cents' => $spec['price'],
        ]);
        VariantStock::create(['variant_id' => $variant->id, 'on_hand' => $spec['stock']]);
        $variants[] = $variant;
    }

    $categories = [];
    if (is_string($categorySlug) && $categorySlug !== '') {
        $categories[$categorySlug] = [
            'id' => 1,
            'slug' => $categorySlug,
            'name' => 'Preserves',
            'product_slugs' => ['conserve'],
        ];
    }

    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => [
            'meta' => ['site_id' => $site->id, 'product_count' => 1],
            'categories' => $categories,
            'products' => [
                'conserve' => [
                    'id' => $product->id,
                    'slug' => 'conserve',
                    'status' => 'published',
                    'primary_category_slug' => $categorySlug,
                    'price_cents' => $variantSpecs[0]['price'],
                    'price_display' => '£'.number_format($variantSpecs[0]['price'] / 100, 2),
                    'in_stock_any' => $inStockAny,
                    'variant_in_stock' => collect($variants)->mapWithKeys(
                        fn ($v, $i) => [$v->id => ($variantSpecs[$i]['stock'] > 0) && $inStockAny]
                    )->all(),
                    'image_urls' => $withImage
                        ? ['thumb' => '/conserve-thumb.jpg', 'card' => '/conserve-card.jpg', 'full' => '/conserve-full.jpg']
                        : null,
                    'product_card' => ['slug' => 'conserve', 'name' => 'Strawberry Conserve', 'price_display' => '£5.95'],
                    'product_detail' => ['slug' => 'conserve', 'name' => 'Strawberry Conserve', 'description' => 'Hand-set jam'],
                    'variants' => collect($variants)->map(fn ($v) => [
                        'id' => $v->id, 'sku' => $v->sku, 'label' => $v->label,
                        'price_cents' => $v->price_cents, 'image_urls' => null,
                    ])->all(),
                    'is_ai_seeded' => false,
                    'is_ai_reviewed' => false,
                ],
            ],
            'featured_slugs' => [],
        ],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snap->id, 'updated_at' => now()]);

    return [$site, $product, $variants];
}

test('the product page has one h1, a token-coloured gallery fallback, price, stock pill and category backlink', function () {
    productPageSite([['label' => 'Jar', 'price' => 595, 'stock' => 8]], withImage: false);

    $html = $this->get('http://flowers.example/products/conserve')->assertOk()->getContent();

    preg_match_all('/<h1\b/i', $html, $headings);
    expect($headings[0])->toHaveCount(1);
    expect($html)->toContain('Strawberry Conserve');

    expect($html)->toContain('inc. VAT')
        ->and($html)->toContain('tabular-nums')
        ->and($html)->toContain('In stock')
        ->and($html)->not->toContain('bg-gray-100')
        ->and($html)->toMatch('/aspect-ratio:\s*1\s*\/\s*1/')
        ->and($html)->toMatch('/background-color:\s*var\(--color-surface-alt\)/')
        ->and($html)->toMatch('/href="[^"]*\/collections\/preserves"/')
        ->and($html)->toContain('Preserves');
});

test('the product gallery uses the full image when one exists', function () {
    productPageSite([['label' => 'Jar', 'price' => 595, 'stock' => 8]]);

    $html = $this->get('http://flowers.example/products/conserve')->assertOk()->getContent();

    expect($html)->toContain('/conserve-full.jpg')
        ->and($html)->toMatch('/<img\b[^>]*alt="Strawberry Conserve"/i');
});

test('out of stock products render the stock pill and no add-to-cart form', function () {
    productPageSite([['label' => 'Jar', 'price' => 595, 'stock' => 0]], inStockAny: false);

    $html = $this->get('http://flowers.example/products/conserve')->assertOk()->getContent();

    expect($html)->toContain('Out of stock')
        ->and($html)->not->toContain('text-red-600')
        ->and($html)->not->toMatch('/<form\b[^>]*action="[^"]*\/shop\/cart\/add"/i')
        ->and($html)->not->toContain('bg-blue-600');
});

test('the add-to-cart form uses the qty stepper and announces Added to cart', function () {
    productPageSite([['label' => 'Jar', 'price' => 595, 'stock' => 8]]);

    $html = $this->get('http://flowers.example/products/conserve')->assertOk()->getContent();

    preg_match('#<form\b[^>]*action="[^"]*/shop/cart/add"[^>]*>(.*?)</form>#s', $html, $form);
    expect($form)->not->toBeEmpty();

    expect($form[1])->toMatch('/<input\b[^>]*\bname="qty"/i')
        ->and($form[1])->toMatch('/inputmode="numeric"/i')
        ->and($form[1])->toContain('min-width: 44px')
        ->and($form[1])->toContain('min-height: 44px')
        ->and($form[1])->not->toMatch('/type="number"[^>]*class="mt-1 w-24/');

    expect($html)->toContain('Added to cart')
        ->and($html)->toMatch('/role="status"/i')
        ->and($html)->not->toContain('@submit="added = true"')
        ->and($html)->toContain('@shop-cart-add')
        ->and($form[1])->toMatch('/role="alert"/')
        ->and($form[1])->toMatch('/background-color:\s*var\(--color-primary\)/')
        ->and($form[1])->not->toContain('bg-blue-600')
        ->and($form[1])->not->toContain('text-white');
});
