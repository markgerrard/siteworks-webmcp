<?php

use App\Enums\Shop\ProductStatus;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\GeneratedPage;
use App\Models\Shop\Product;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Shop\SnapshotBuilder;
use App\Services\Shop\SnapshotReader;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    config([
        'site.use_versioned_renderer' => true,
        'site.public_cache_enabled' => true,
    ]);
});

test('a home page with featured_products changes after a shop snapshot rebuild', function () {
    $host = 'featured-cache.example';
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'business_name' => 'Camino',
        'theme' => 'trades-bold',
        'shop_mode' => 'cart',
    ]);

    $product = Product::factory()->for($site)->create([
        'slug' => 'alpha',
        'name' => 'Alpha item',
        'status' => ProductStatus::Published,
    ]);
    Product::factory()->for($site)->create([
        'slug' => 'bravo',
        'name' => 'Bravo item',
        'status' => ProductStatus::Published,
    ]);

    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Welcome'],
            [
                'type' => 'featured_products',
                'title' => 'Featured products',
                'source' => 'newest',
                'count' => 4,
                'cta_label' => 'Browse the shop',
                'cta_url' => '/shop',
            ],
        ]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'homepage_page_id' => $page->id,
        ],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));
    app(SnapshotReader::class)->invalidate($site->id);

    $url = 'http://'.$host.'/';
    $before = $this->get($url)->assertSuccessful()->getContent();
    expect($before)->toContain('Alpha item')
        ->and($before)->not->toContain('Renamed item');

    $product->update(['name' => 'Renamed item']);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));

    $after = $this->get($url)->assertSuccessful()->getContent();
    expect($after)->toContain('Renamed item')
        ->and($after)->not->toContain('Alpha item');
});

function purchasableSiteWithHome(string $host, bool $withFeaturedSection): array
{
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'business_name' => 'Camino',
        'theme' => 'trades-bold',
        'shop_mode' => 'cart',
        'shop_first_purchasable_at' => now()->subDay(),
    ]);
    $product = Product::factory()->for($site)->create(['slug' => 'alpha', 'name' => 'Alpha item', 'status' => ProductStatus::Published]);
    Product::factory()->for($site)->create(['slug' => 'bravo', 'name' => 'Bravo item', 'status' => ProductStatus::Published]);
    $sections = [['type' => 'hero', 'title' => 'Welcome']];
    if ($withFeaturedSection) {
        $sections[] = ['type' => 'featured_products', 'title' => 'Featured products', 'source' => 'newest', 'count' => 4, 'cta_label' => 'Browse the shop', 'cta_url' => '/shop'];
    }
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'content_data' => ['sections' => $sections]]);
    $rev = PageRevision::factory()->for($page, 'page')->create(['content_data' => ['sections' => $sections]]);
    $page->update(['published_revision_id' => $rev->id]);

    return [$site, $product];
}

test('a snapshot rebuild bumps the public page cache exactly once when a page consumes the snapshot', function () {
    [$site, $product] = purchasableSiteWithHome('featured-bump.example', true);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));
    $cache = app(\App\Services\Site\PublicPageCache::class);
    $before = $cache->generation($site);

    $product->update(['name' => 'Cherry bakewell']);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));

    expect($cache->generation($site))->toBe($before + 1);
});

test('a snapshot rebuild leaves the public page cache alone when no page consumes the snapshot', function () {
    [$site, $product] = purchasableSiteWithHome('featured-nobump.example', false);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));
    $cache = app(\App\Services\Site\PublicPageCache::class);
    $before = $cache->generation($site);

    $product->update(['name' => 'Cherry bakewell']);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));

    expect($cache->generation($site))->toBe($before);
});

test('a snapshot rebuild bumps the public page cache when shop_nav_style expands the header', function (string $style) {
    [$site, $product] = purchasableSiteWithHome('shop-nav-bump-'.$style.'.example', false);
    $site->update(['shop_nav_style' => $style]);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));
    $cache = app(\App\Services\Site\PublicPageCache::class);
    $before = $cache->generation($site);

    $product->update(['name' => 'Cherry bakewell']);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));

    expect($cache->generation($site))->toBe($before + 1);
})->with(['dropdown', 'mega']);

test('shop_nav_style=link still does not bump the public page cache without a consuming section', function () {
    [$site, $product] = purchasableSiteWithHome('shop-nav-link-nobump.example', false);
    $site->update(['shop_nav_style' => 'link']);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));
    $cache = app(\App\Services\Site\PublicPageCache::class);
    $before = $cache->generation($site);

    $product->update(['name' => 'Cherry bakewell']);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));

    expect($cache->generation($site))->toBe($before);
});

test('a draft-only featured_products section on a published page does not trigger a cache bump', function () {
    [$site, $product] = purchasableSiteWithHome('featured-draftonly.example', false);
    // Draft mirror carries the section; the published revision does not.
    $page = GeneratedPage::where('site_id', $site->id)->where('page_type', 'home')->firstOrFail();
    $page->update(['content_data' => ['sections' => [['type' => 'hero', 'title' => 'Welcome'], ['type' => 'featured_products', 'title' => 'Draft only']]]]);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));
    $cache = app(\App\Services\Site\PublicPageCache::class);
    $before = $cache->generation($site);

    $product->update(['name' => 'Cherry bakewell']);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));

    expect($cache->generation($site))->toBe($before);
});
