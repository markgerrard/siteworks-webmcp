<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\ShopSlugRedirect;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function slugRedirectStorefront(string $host, string $currentSlug, string $oldSlug): Site
{
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
    ]);
    $json = [
        'meta' => ['site_id' => $site->id, 'product_count' => 1],
        'category_paths' => ['cakes' => 'cakes'],
        'categories' => [
            'cakes' => [
                'id' => 1,
                'slug' => 'cakes',
                'name' => 'Cakes',
                'path' => 'cakes',
                'visibility' => 'visible',
                'product_slugs' => [$currentSlug],
            ],
        ],
        'products' => [
            $currentSlug => [
                'id' => 1,
                'slug' => $currentSlug,
                'status' => 'published',
                'primary_category_slug' => 'cakes',
                'price_cents' => 4500,
                'price_display' => '£45.00',
                'in_stock_any' => true,
                'variant_in_stock' => [1 => true],
                'image_urls' => null,
                'product_card' => ['slug' => $currentSlug, 'name' => 'Victoria Sponge', 'price_display' => '£45.00'],
                'product_detail' => ['slug' => $currentSlug, 'name' => 'Victoria Sponge', 'description' => 'A sponge'],
                'variants' => [['id' => 1, 'sku' => 'VS-1', 'label' => 'Std', 'price_cents' => 4500, 'image_urls' => null]],
                'is_ai_seeded' => true,
                'is_ai_reviewed' => false,
            ],
        ],
        'featured_slugs' => [],
    ];
    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => $json,
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snap->id, 'updated_at' => now()]);
    ShopSlugRedirect::create([
        'site_id' => $site->id,
        'kind' => 'product',
        'old_slug' => $oldSlug,
        'slug' => $currentSlug,
    ]);

    return $site;
}

test('an old seeded product slug 301s to the cleaned canonical URL', function () {
    slugRedirectStorefront('redirects.example', 'victoria-sponge', 'victoria-sponge-rcax2r');

    $response = $this->get('http://redirects.example/products/victoria-sponge-rcax2r?utm=old');
    $response->assertStatus(301);
    expect($response->headers->get('Location'))
        ->toEndWith('/products/victoria-sponge?utm=old');
});

test('legacy /shop/p old slugs 301 in one hop to the cleaned URL', function () {
    slugRedirectStorefront('legacy-redirects.example', 'victoria-sponge', 'victoria-sponge-rcax2r');

    $response = $this->get('http://legacy-redirects.example/shop/p/victoria-sponge-rcax2r');
    $response->assertStatus(301);
    expect($response->headers->get('Location'))
        ->toEndWith('/products/victoria-sponge');
});
