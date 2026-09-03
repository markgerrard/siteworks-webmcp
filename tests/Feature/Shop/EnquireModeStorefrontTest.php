<?php

use App\Enums\Shop\ProductStatus;
use App\Enums\Shop\ShopSnapshotStatus;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\Category;
use App\Models\Shop\FeaturedProduct;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Services\Shop\SnapshotBuilder;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  list<array{label: string, price: int, stock: int}>  $variantSpecs
 * @return array{0: Site, 1: Product, 2: list<ProductVariant>}
 */
function enquireStorefrontSite(
    string $host,
    string $shopMode = 'cart',
    string $shopCurrency = 'GBP',
    bool $priceFrom = false,
    bool $rebuildSnapshot = false,
    array $variantSpecs = [['label' => 'Cake', 'price' => 8500, 'stock' => 4]],
    string $slug = 'conserve',
    string $name = 'Strawberry Conserve',
): array {
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'shop_mode' => $shopMode,
        'shop_currency' => $shopCurrency,
    ]);

    $product = Product::factory()->for($site)->create([
        'slug' => $slug,
        'name' => $name,
        'status' => ProductStatus::Published,
        'price_from' => $priceFrom,
    ]);

    $variants = [];
    foreach ($variantSpecs as $i => $spec) {
        $variant = ProductVariant::factory()->for($product)->create([
            'sku' => 'ENQ-'.$i,
            'label' => $spec['label'],
            'price_cents' => $spec['price'],
        ]);
        VariantStock::create(['variant_id' => $variant->id, 'on_hand' => $spec['stock']]);
        $variants[] = $variant;
    }

    if ($rebuildSnapshot) {
        $category = Category::factory()->create([
            'site_id' => $site->id,
            'slug' => 'cakes',
            'name' => 'Cakes',
        ]);
        $product->categories()->attach($category, ['is_primary' => true]);
        FeaturedProduct::create([
            'site_id' => $site->id,
            'product_id' => $product->id,
            'sort_order' => 0,
        ]);
        (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));

        return [$site->fresh(), $product, $variants];
    }

    $inStockAny = collect($variantSpecs)->contains(fn ($spec) => $spec['stock'] > 0);
    $priceDisplay = '£'.number_format($variantSpecs[0]['price'] / 100, 2);

    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'product_count' => 1,
        'json' => [
            'meta' => ['site_id' => $site->id, 'product_count' => 1, 'currency' => $shopCurrency],
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
                        'price_from' => $priceFrom,
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

test('an enquire-mode product page shows Enquire about this cake and no cart form', function () {
    enquireStorefrontSite('enquire-pdp.example', shopMode: 'enquire');

    $html = $this->get('http://enquire-pdp.example/products/conserve')->assertOk()->getContent();

    expect($html)->toContain('Enquire about this cake')
        ->and($html)->toMatch('/href="\/enquire\?product=conserve"/')
        ->and($html)->not->toContain('Add to cart')
        ->and($html)->not->toMatch('/<form\b[^>]*action="[^"]*\/shop\/cart\/add"/i')
        ->and($html)->not->toContain('name="qty"');
});

test('an enquire-mode product page still offers the enquire button when the item is out of stock', function () {
    enquireStorefrontSite(
        'enquire-oos.example',
        shopMode: 'enquire',
        variantSpecs: [['label' => 'Cake', 'price' => 8500, 'stock' => 0]],
    );

    $html = $this->get('http://enquire-oos.example/products/conserve')->assertOk()->getContent();

    expect($html)->toContain('Enquire about this cake')
        ->and($html)->not->toContain('Add to cart')
        ->and($html)->not->toMatch('/<form\b[^>]*action="[^"]*\/shop\/cart\/add"/i');
});

test('a cart-mode product page is unchanged: add-to-cart form, no enquire button', function () {
    enquireStorefrontSite('enquire-cart-pdp.example', shopMode: 'cart');

    $html = $this->get('http://enquire-cart-pdp.example/products/conserve')->assertOk()->getContent();

    expect($html)->toContain('Add to cart')
        ->and($html)->toMatch('/<form\b[^>]*action="[^"]*\/shop\/cart\/add"/i')
        ->and($html)->not->toContain('Enquire about this cake')
        ->and($html)->not->toContain('/enquire?product=');
});

test('enquire-mode nav hides the bag icon', function () {
    enquireStorefrontSite('enquire-nav.example', shopMode: 'enquire');

    $html = $this->get('http://enquire-nav.example/shop')->assertOk()->getContent();

    expect($html)->not->toContain('data-shop-cart-control')
        ->and($html)->not->toContain('href="/shop/cart"');
});

test('cart-mode nav still shows the bag icon', function () {
    enquireStorefrontSite('enquire-cart-nav.example', shopMode: 'cart');

    $html = $this->get('http://enquire-cart-nav.example/shop')->assertOk()->getContent();

    expect($html)->toContain('data-shop-cart-control')
        ->and($html)->toContain('href="/shop/cart"');
});

test('layoutContext disables shopCartEnabled only for enquire-mode shops', function () {
    [$enquireSite] = enquireStorefrontSite('enquire-ctx.example', shopMode: 'enquire');
    [$cartSite] = enquireStorefrontSite('enquire-ctx-cart.example', shopMode: 'cart');

    expect(app(PageRenderer::class)->layoutContext($enquireSite)['shopCartEnabled'])->toBeFalse()
        ->and(app(PageRenderer::class)->layoutContext($cartSite)['shopCartEnabled'])->toBeTrue();
});

test('enquire-mode cart and checkout routes 404 while the catalogue still serves', function () {
    enquireStorefrontSite('enquire-gate.example', shopMode: 'enquire');

    $this->get('http://enquire-gate.example/shop')->assertOk();
    $this->get('http://enquire-gate.example/products/conserve')->assertOk();
    $this->get('http://enquire-gate.example/collections/cakes')->assertOk();

    foreach (['/shop/cart', '/shop/checkout', '/shop/checkout/success', '/shop/checkout/cancel'] as $path) {
        $this->get('http://enquire-gate.example'.$path)
            ->assertNotFound("{$path} must 404 on an enquire-mode shop");
    }

    $this->post('http://enquire-gate.example/shop/cart/add', [
        'product_slug' => 'conserve',
        'variant_id' => 1,
        'qty' => 1,
    ])->assertNotFound();
});

test('cart-mode cart remains reachable', function () {
    enquireStorefrontSite('enquire-cart-gate.example', shopMode: 'cart');

    $this->get('http://enquire-cart-gate.example/shop/cart')->assertOk();
});

test('the enquire form arrives with the product name pre-filled in its message field', function () {
    enquireStorefrontSite('enquire-form.example', shopMode: 'enquire');

    $html = $this->get('http://enquire-form.example/enquire?product=conserve')->assertOk()->getContent();

    expect($html)->toContain('name="message"')
        ->and($html)->toContain('Strawberry Conserve')
        ->and($html)->toMatch('/<textarea\b[^>]*\bname="message"[^>]*>[^<]*Strawberry Conserve/s');
});

test('category and index cards in enquire mode show the price and no add affordance', function () {
    enquireStorefrontSite('enquire-cards.example', shopMode: 'enquire');

    $index = $this->get('http://enquire-cards.example/shop')->assertOk()->getContent();
    $category = $this->get('http://enquire-cards.example/collections/cakes')->assertOk()->getContent();

    foreach ([$index, $category] as $html) {
        expect($html)->toContain('Strawberry Conserve')
            ->and($html)->toContain('£85.00')
            ->and($html)->not->toContain('Add to cart')
            ->and($html)->not->toContain('/shop/cart/add');
    }
});

test('a rebuilt USD snapshot with price_from renders from-prices on the card and PDP', function () {
    enquireStorefrontSite(
        'enquire-from.example',
        shopMode: 'enquire',
        shopCurrency: 'USD',
        priceFrom: true,
        rebuildSnapshot: true,
        variantSpecs: [['label' => 'Whole cake', 'price' => 8500, 'stock' => 2]],
        slug: 'wedding-cake',
        name: 'Wedding Cake',
    );

    $pdp = $this->get('http://enquire-from.example/products/wedding-cake')->assertOk()->getContent();
    $index = $this->get('http://enquire-from.example/shop')->assertOk()->getContent();

    expect($pdp)->toContain('from $85')
        ->and($index)->toContain('from $85')
        ->and($pdp)->not->toContain('from £')
        ->and($index)->not->toContain('£85.00');
});

test('a USD enquire-mode product page shows no VAT suffix and no stock pill', function () {
    enquireStorefrontSite('enquire-usd.example', shopMode: 'enquire', shopCurrency: 'USD');

    $html = $this->get('http://enquire-usd.example/products/conserve')->assertOk()->getContent();

    expect($html)->not->toContain('inc. VAT')
        ->and($html)->not->toContain('In stock')
        ->and($html)->not->toContain('Out of stock');
});

test('a GBP cart-mode product page keeps the VAT suffix and the stock pill', function () {
    enquireStorefrontSite('cart-gbp.example', shopMode: 'cart', shopCurrency: 'GBP');

    $html = $this->get('http://cart-gbp.example/products/conserve')->assertOk()->getContent();

    expect($html)->toContain('inc. VAT')->and($html)->toContain('In stock');
});
