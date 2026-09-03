<?php

use App\Enums\Shop\ProductStatus;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\GeneratedPage;
use App\Models\Shop\Product;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Shop\SnapshotBuilder;
use App\Services\Site\PublicPageCache;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    config([
        'site.use_versioned_renderer' => true,
        'site.public_cache_enabled' => true,
    ]);
});

test('snapshot rebuild writes auto tags then busts the page cache', function () {
    $host = 'tag-cache-order.example';
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'business_name' => 'Cache Order',
        'theme' => 'trades-bold',
        'shop_mode' => 'cart',
        'shop_first_purchasable_at' => now()->subDay(),
        'product_tags' => [
            ['slug' => 'gift', 'label' => 'Gift', 'show_as_badge' => true, 'tone' => 'accent'],
        ],
        'auto_tags' => [
            'new' => ['enabled' => true, 'label' => 'New', 'show_as_badge' => true, 'params' => ['days' => 14]],
        ],
    ]);
    $product = Product::factory()->for($site)->create([
        'slug' => 'fresh',
        'name' => 'Fresh item',
        'status' => ProductStatus::Published,
        'published_at' => now()->subDay(),
        'tags' => ['gift'],
    ]);
    Product::factory()->for($site)->create([
        'slug' => 'also',
        'name' => 'Also item',
        'status' => ProductStatus::Published,
        'published_at' => now()->subDays(40),
        'tags' => ['gift'],
    ]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Welcome'],
            ['type' => 'featured_products', 'title' => 'Picks', 'source' => 'newest', 'count' => 4, 'cta_label' => 'Browse the shop', 'cta_url' => '/shop'],
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
    $cache = app(PublicPageCache::class);
    $before = $cache->generation($site);
    $html = $this->get('http://'.$host.'/')->assertSuccessful()->getContent();
    expect($html)->toContain('Gift')->and($html)->toContain('New');

    $product->update(['published_at' => now()->subDays(40)]);

    $sequence = new ArrayObject;
    ShopSnapshotCurrent::saved(function () use ($sequence): void {
        $sequence[] = 'snapshot';
    });
    $realCache = app(PublicPageCache::class);
    app()->instance(PublicPageCache::class, new class($realCache, $sequence) extends PublicPageCache
    {
        public function __construct(private PublicPageCache $inner, private ArrayObject $sequence) {}

        public function invalidate(\App\Models\Site $site): void
        {
            $this->sequence[] = 'cache';
            $this->inner->invalidate($site);
        }

        public function generation(\App\Models\Site $site): int
        {
            return $this->inner->generation($site);
        }
    });

    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));

    expect($sequence->getArrayCopy())->toBe(['snapshot', 'cache'])
        ->and(app(PublicPageCache::class)->generation($site))->toBe($before + 1);
    $after = $this->get('http://'.$host.'/')->assertSuccessful()->getContent();
    expect($after)->toContain('Gift')->and($after)->not->toContain('>New<');
});
