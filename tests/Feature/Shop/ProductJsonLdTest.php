<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;

/**
 * @param  array<string, mixed>  $product
 */
function productJsonLdSite(string $host, string $shopMode, array $product): Site
{
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'business_name' => 'Bloom & Stem',
        'shop_mode' => $shopMode,
        'shop_currency' => 'GBP',
    ]);

    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => [
            'meta' => ['site_id' => $site->id, 'product_count' => 1, 'currency' => 'GBP'],
            'categories' => [
                'cakes' => [
                    'id' => 1,
                    'slug' => 'cakes',
                    'name' => 'Cakes',
                    'path' => 'cakes',
                    'breadcrumb' => [['name' => 'Cakes', 'path' => 'cakes']],
                    'product_slugs' => [$product['slug']],
                ],
            ],
            'products' => [$product['slug'] => $product],
            'featured_slugs' => [],
        ],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create([
        'site_id' => $site->id,
        'snapshot_id' => $snap->id,
        'updated_at' => now(),
    ]);

    return $site;
}

function productJsonLdBlocks(string $html): array
{
    preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches);
    expect($matches[1])->not->toBeEmpty();

    return array_map(fn (string $raw) => json_decode($raw, true), $matches[1]);
}

function productJsonLdProduct(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 1,
        'slug' => 'victoria',
        'status' => 'published',
        'primary_category_slug' => 'cakes',
        'price_cents' => 4500,
        'price_display' => '£45.00',
        'in_stock_any' => true,
        'variant_in_stock' => [1 => true],
        'image_urls' => [
            'thumb' => 'http://images.example/victoria-thumb.jpg',
            'card' => 'http://images.example/victoria-card.jpg',
            'full' => 'http://images.example/victoria-full.jpg',
        ],
        'product_card' => ['slug' => 'victoria', 'name' => 'Victoria Sponge', 'price_display' => '£45.00'],
        'product_detail' => ['slug' => 'victoria', 'name' => 'Victoria Sponge', 'description' => 'A classic sponge.'],
        'variants' => [['id' => 1, 'sku' => 'VS-1', 'label' => 'Std', 'price_cents' => 4500, 'image_urls' => null]],
        'is_ai_seeded' => false,
        'is_ai_reviewed' => false,
    ], $overrides);
}

test('cart-mode product pages emit BreadcrumbList and Product offers JSON-LD', function () {
    productJsonLdSite('jsonld-cart.example', 'cart', productJsonLdProduct());

    $html = $this->get('http://jsonld-cart.example/products/victoria')->assertOk()->getContent();

    expect($html)->toContain('<title>Victoria Sponge — Bloom &amp; Stem</title>')
        ->and($html)->toContain('<link rel="canonical" href="http://jsonld-cart.example/products/victoria">');

    $blocks = productJsonLdBlocks($html);
    $byType = collect($blocks)->keyBy('@type');

    expect($byType->keys()->all())->toEqualCanonicalizing(['BreadcrumbList', 'Product']);

    $product = $byType['Product'];
    expect($product['name'])->toBe('Victoria Sponge')
        ->and($product['description'])->toBe('A classic sponge.')
        ->and($product['sku'])->toBe('VS-1')
        ->and($product['brand'])->toBe(['@type' => 'Brand', 'name' => 'Bloom & Stem'])
        ->and($product['image'])->toBe([
            'http://images.example/victoria-full.jpg',
            'http://images.example/victoria-card.jpg',
            'http://images.example/victoria-thumb.jpg',
        ])
        ->and($product['offers'])->toMatchArray([
            '@type' => 'Offer',
            'price' => '45.00',
            'priceCurrency' => 'GBP',
            'availability' => 'https://schema.org/InStock',
            'url' => 'http://jsonld-cart.example/products/victoria',
        ]);

    $crumbs = collect($byType['BreadcrumbList']['itemListElement'])->pluck('name')->all();
    expect($crumbs)->toBe(['Home', 'Shop', 'Cakes', 'Victoria Sponge']);
});

test('enquire-mode product pages emit Product JSON-LD without offers', function () {
    productJsonLdSite('jsonld-enquire.example', 'enquire', productJsonLdProduct([
        'in_stock_any' => false,
        'variant_in_stock' => [1 => false],
    ]));

    $html = $this->get('http://jsonld-enquire.example/products/victoria')->assertOk()->getContent();
    $blocks = productJsonLdBlocks($html);
    $product = collect($blocks)->firstWhere('@type', 'Product');

    expect($product)->toBeArray()
        ->and($product)->not->toHaveKey('offers')
        ->and($product['name'])->toBe('Victoria Sponge');
});

test('out of stock cart products mark Offer availability as OutOfStock', function () {
    productJsonLdSite('jsonld-oos.example', 'cart', productJsonLdProduct([
        'in_stock_any' => false,
        'variant_in_stock' => [1 => false],
    ]));

    $html = $this->get('http://jsonld-oos.example/products/victoria')->assertOk()->getContent();
    $product = collect(productJsonLdBlocks($html))->firstWhere('@type', 'Product');

    expect($product['offers']['availability'])->toBe('https://schema.org/OutOfStock');
});

test('multi-variant products emit an AggregateOffer spanning variant prices', function () {
    productJsonLdSite('jsonld-multi.example', 'cart', productJsonLdProduct([
        'variant_in_stock' => [1 => true, 2 => true],
        'variants' => [
            ['id' => 1, 'sku' => 'VS-1', 'label' => 'Small', 'price_cents' => 3500, 'image_urls' => null],
            ['id' => 2, 'sku' => 'VS-2', 'label' => 'Large', 'price_cents' => 6000, 'image_urls' => null],
        ],
    ]));

    $html = $this->get('http://jsonld-multi.example/products/victoria')->assertOk()->getContent();
    $product = collect(productJsonLdBlocks($html))->firstWhere('@type', 'Product');

    expect($product['offers'])->toMatchArray([
        '@type' => 'AggregateOffer',
        'priceCurrency' => 'GBP',
        'lowPrice' => '35.00',
        'highPrice' => '60.00',
        'availability' => 'https://schema.org/InStock',
        'url' => 'http://jsonld-multi.example/products/victoria',
    ])->and($product['sku'])->toBe('VS-1');
});

test('quote-mode product pages emit offers with availability and no invented price', function () {
    productJsonLdSite('jsonld-quote.example', 'quote', productJsonLdProduct());

    $html = $this->get('http://jsonld-quote.example/products/victoria')->assertOk()->getContent();
    $product = collect(productJsonLdBlocks($html))->firstWhere('@type', 'Product');

    expect($product)->toHaveKey('offers')
        ->and($product['offers'])->toMatchArray([
            '@type' => 'Offer',
            'price' => '45.00',
            'priceCurrency' => 'GBP',
            'availability' => 'https://schema.org/InStock',
            'url' => 'http://jsonld-quote.example/products/victoria',
        ]);
});

test('a price-from (guide price) product is marked PreOrder rather than InStock', function () {
    productJsonLdSite('jsonld-priceform.example', 'cart', productJsonLdProduct([
        'price_from' => true,
    ]));

    $html = $this->get('http://jsonld-priceform.example/products/victoria')->assertOk()->getContent();
    $product = collect(productJsonLdBlocks($html))->firstWhere('@type', 'Product');

    expect($product['offers']['availability'])->toBe('https://schema.org/PreOrder')
        ->and($product['offers']['price'])->toBe('45.00');
});

test('a product with no image and no variants renders JSON-LD without throwing', function () {
    $product = productJsonLdProduct();
    $product['image_urls'] = null;
    $product['variants'] = [];
    $product['variant_in_stock'] = [];
    productJsonLdSite('jsonld-bare.example', 'cart', $product);

    $html = $this->get('http://jsonld-bare.example/products/victoria')->assertOk()->getContent();
    $product = collect(productJsonLdBlocks($html))->firstWhere('@type', 'Product');

    expect($product['image'])->toBe([])
        ->and($product)->not->toHaveKey('sku')
        ->and($product['offers']['@type'])->toBe('Offer');
});

test('a long HTML description is stripped to plain text and clamped to 500 characters', function () {
    $longDescription = '<p>'.str_repeat('Rich chocolate sponge. ', 40).'</p>';
    productJsonLdSite('jsonld-long.example', 'cart', productJsonLdProduct([
        'product_detail' => ['slug' => 'victoria', 'name' => 'Victoria Sponge', 'description' => $longDescription],
    ]));

    $html = $this->get('http://jsonld-long.example/products/victoria')->assertOk()->getContent();
    $product = collect(productJsonLdBlocks($html))->firstWhere('@type', 'Product');

    expect($product['description'])->not->toContain('<p>')
        ->and(mb_strlen($product['description']))->toBeLessThanOrEqual(500);
});
