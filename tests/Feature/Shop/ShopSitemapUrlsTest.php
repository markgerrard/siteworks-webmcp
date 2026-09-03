<?php

use App\Enums\PageStatus;
use App\Enums\PreviewLayout;
use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\GeneratedPage;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;

test('a shop sitemap lists /products and /collections and omits the legacy /shop/p and /shop/c paths', function () {
    config(['site.use_versioned_renderer' => true]);

    $site = Site::factory()->create([
        'custom_domain' => 'shop-urls-map.example',
        'custom_domain_status' => 'active',
        'preview_layout' => PreviewLayout::MultiPage,
        'business_name' => 'Bloom & Stem',
    ]);
    $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'status' => PageStatus::Published]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Home']]],
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

    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => [
            'meta' => ['site_id' => $site->id, 'product_count' => 1],
            'categories' => [
                'cakes' => ['slug' => 'cakes', 'path' => 'cakes', 'visibility' => 'visible'],
            ],
            'products' => [
                'victoria' => ['slug' => 'victoria', 'status' => 'published'],
            ],
        ],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snap->id, 'updated_at' => now()]);

    $xml = (string) $this->get('http://shop-urls-map.example/sitemap.xml')->assertSuccessful()->getContent();

    expect($xml)->toContain('http://shop-urls-map.example/shop')
        ->and($xml)->toContain('http://shop-urls-map.example/collections/cakes')
        ->and($xml)->toContain('http://shop-urls-map.example/products/victoria')
        ->and($xml)->not->toContain('/shop/c/')
        ->and($xml)->not->toContain('/shop/p/');

    $robots = (string) $this->get('http://shop-urls-map.example/robots.txt')->assertSuccessful()->getContent();
    expect($robots)->not->toContain('/shop/p/')
        ->and($robots)->not->toContain('/shop/c/');
});
