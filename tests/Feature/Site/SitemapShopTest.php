<?php

use App\Enums\PageStatus;
use App\Enums\PreviewLayout;
use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\GeneratedPage;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;

/**
 * @return array{0: Site, 1: string}
 */
function sitemapShopPages(string $host): array
{
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'preview_layout' => PreviewLayout::MultiPage,
        'business_name' => 'Bloom & Stem',
    ]);

    $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'status' => PageStatus::Published]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Home']]],
        'created_at' => now()->subDay()->startOfSecond(),
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'],
            'homepage_page_id' => $home->id,
        ],
        'page_revisions' => [
            ['page_id' => $home->id, 'revision_id' => $homeRev->id],
        ],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::query()->updateOrCreate(
        ['site_id' => $site->id],
        ['version_id' => $version->id, 'updated_at' => now()],
    );

    return [$site, $homeRev->updated_at?->toAtomString() ?? $homeRev->created_at->toAtomString()];
}

function sitemapShopLocs(\Illuminate\Testing\TestResponse $response): array
{
    $xml = simplexml_load_string((string) $response->getContent());
    expect($xml)->not->toBeFalse();

    $locs = [];
    foreach ($xml->url as $url) {
        $locs[] = (string) $url->loc;
    }

    return $locs;
}

test('a site without a shop keeps a page-only sitemap', function () {
    config(['site.use_versioned_renderer' => true]);
    sitemapShopPages('no-shop.example');

    $response = $this->get('http://no-shop.example/sitemap.xml')->assertSuccessful();
    $xml = (string) $response->getContent();

    expect(sitemapShopLocs($response))->toBe(['http://no-shop.example/'])
        ->and($xml)->not->toContain('/shop')
        ->and($xml)->not->toContain('/collections/')
        ->and($xml)->not->toContain('/products/');
});

test('an established shop sitemap appends shop, visible categories and published products', function () {
    config(['site.use_versioned_renderer' => true]);
    [$site] = sitemapShopPages('shop-map.example');

    $product = Product::factory()->for($site)->published()->create(['slug' => 'victoria']);
    ProductVariant::factory()->for($product)->create(['price_cents' => 4500]);
    VariantStock::create(['variant_id' => $product->variants()->first()->id, 'on_hand' => 2]);

    $publishedAt = now()->startOfSecond();
    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => [
            'meta' => [
                'site_id' => $site->id,
                'product_count' => 1,
                'published_at' => $publishedAt->toAtomString(),
            ],
            'category_paths' => [
                'cakes' => 'cakes',
                'cakes/wedding-cakes' => 'wedding-cakes',
                'secret' => 'secret',
            ],
            'categories' => [
                'cakes' => [
                    'slug' => 'cakes',
                    'path' => 'cakes',
                    'visibility' => 'visible',
                    'product_slugs' => ['victoria'],
                ],
                'wedding-cakes' => [
                    'slug' => 'wedding-cakes',
                    'path' => 'cakes/wedding-cakes',
                    'visibility' => 'visible',
                    'product_slugs' => ['victoria'],
                ],
                'secret' => [
                    'slug' => 'secret',
                    'path' => 'secret',
                    'visibility' => 'hidden',
                    'product_slugs' => [],
                ],
            ],
            'products' => [
                'victoria' => ['slug' => 'victoria', 'status' => 'published'],
                'draft-cake' => ['slug' => 'draft-cake', 'status' => 'draft'],
            ],
        ],
        'built_at' => $publishedAt,
    ]);
    ShopSnapshotCurrent::create([
        'site_id' => $site->id,
        'snapshot_id' => $snap->id,
        'updated_at' => $publishedAt,
    ]);

    $response = $this->get('http://shop-map.example/sitemap.xml')->assertSuccessful();
    $locs = sitemapShopLocs($response);

    expect($locs)->toContain('http://shop-map.example/')
        ->and($locs)->toContain('http://shop-map.example/shop')
        ->and($locs)->toContain('http://shop-map.example/collections/cakes')
        ->and($locs)->toContain('http://shop-map.example/collections/cakes/wedding-cakes')
        ->and($locs)->toContain('http://shop-map.example/products/victoria')
        ->and($locs)->not->toContain('http://shop-map.example/collections/secret')
        ->and($locs)->not->toContain('http://shop-map.example/products/draft-cake')
        ->and($locs)->not->toContain('http://shop-map.example/shop/c/cakes')
        ->and($locs)->not->toContain('http://shop-map.example/shop/p/victoria');

    expect((string) $response->getContent())
        ->toContain('<lastmod>'.$publishedAt->toAtomString().'</lastmod>')
        ->and((string) $response->getContent())->not->toContain('/shop/c/')
        ->and((string) $response->getContent())->not->toContain('/shop/p/');
});
