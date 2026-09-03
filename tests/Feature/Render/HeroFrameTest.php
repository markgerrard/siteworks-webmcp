<?php

use App\Enums\PageKind;
use App\Enums\PreviewLayout;
use App\Enums\Shop\ProductStatus;
use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\LayoutPreset;

uses(RefreshDatabase::class);

function heroFrameSite(string $host, string $shopMode = 'cart', array $siteAttrs = []): array
{
    config(['site.use_versioned_renderer' => true]);

    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'business_name' => 'Bloom & Stem',
        'shop_mode' => $shopMode,
        'preview_layout' => PreviewLayout::MultiPage,
        ...$siteAttrs,
    ]);

    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
        'nav_label' => 'Home',
    ]);
    $about = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'about',
        'kind' => PageKind::Core,
        'nav_label' => 'About',
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Welcome to the bakery'],
        ]],
    ]);
    $aboutRev = PageRevision::factory()->for($about, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'About the bakery'],
        ]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);
    $about->update(['published_revision_id' => $aboutRev->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => [
                ['type' => 'page', 'page_id' => $about->id, 'label' => 'About'],
            ]],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'],
            'homepage_page_id' => $home->id,
        ],
        'page_revisions' => [
            ['page_id' => $home->id, 'revision_id' => $homeRev->id],
            ['page_id' => $about->id, 'revision_id' => $aboutRev->id],
        ],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);

    $product = Product::factory()->for($site)->create([
        'slug' => 'bakewell',
        'name' => 'Bakewell Tart',
        'status' => ProductStatus::Published,
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'sku' => 'BT-1',
        'label' => 'Each',
        'price_cents' => 450,
    ]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 6]);

    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'product_count' => 1,
        'json' => [
            'meta' => ['site_id' => $site->id, 'product_count' => 1],
            'categories' => [
                'tarts' => [
                    'id' => 1,
                    'slug' => 'tarts',
                    'name' => 'Tarts',
                    'product_slugs' => ['bakewell'],
                ],
                'empty-shelf' => [
                    'id' => 2,
                    'slug' => 'empty-shelf',
                    'name' => 'Empty shelf',
                    'product_slugs' => [],
                ],
            ],
            'products' => [
                'bakewell' => [
                    'id' => $product->id,
                    'slug' => 'bakewell',
                    'status' => 'published',
                    'primary_category_slug' => 'tarts',
                    'price_cents' => 450,
                    'price_display' => '£4.50',
                    'in_stock_any' => true,
                    'variant_in_stock' => [$variant->id => true],
                    'image_urls' => ['thumb' => '/bakewell-thumb.jpg', 'card' => '/bakewell-card.jpg', 'full' => '/bakewell-full.jpg'],
                    'product_card' => ['slug' => 'bakewell', 'name' => 'Bakewell Tart', 'price_display' => '£4.50'],
                    'product_detail' => ['slug' => 'bakewell', 'name' => 'Bakewell Tart', 'description' => 'Almond'],
                    'variants' => [['id' => $variant->id, 'sku' => 'BT-1', 'label' => 'Each', 'price_cents' => 450, 'image_urls' => null]],
                    'is_ai_seeded' => false,
                    'is_ai_reviewed' => false,
                ],
            ],
            'featured_slugs' => ['bakewell'],
        ],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create([
        'site_id' => $site->id,
        'snapshot_id' => $snap->id,
        'updated_at' => now(),
    ]);

    return ['site' => $site->fresh(), 'host' => $host];
}

test('hero_frame=boxed insets the hero in the shell container; full (default) is untouched', function () {
    ['site' => $boxedSite] = heroFrameSite('boxed-hero.example', 'enquire');
    LayoutPreset::create(['site_id' => $boxedSite->id, 'page_kind' => 'chrome', 'key' => 'boxed-test', 'label' => 'Boxed',
        'recipe' => ['layout' => 'standard', 'hero_frame' => 'boxed', 'hero_corners' => 'square', 'hero_backdrop' => 'white'], 'status' => 'active']);
    $boxedSite->forceFill(['chrome_layout' => 'boxed-test'])->save();
    heroFrameSite('full-hero.example', 'enquire');

    $boxed = $this->get('http://boxed-hero.example/')->assertOk()->getContent();
    $full = $this->get('http://full-hero.example/')->assertOk()->getContent();

    expect($boxed)->toContain('data-hero-frame="boxed"')
        ->and($boxed)->toMatch('/data-hero-frame="boxed"[^>]*class="site-shell-container/')
        ->and($boxed)->toContain('data-hero-corners="square"')
        ->and($boxed)->toContain('border-radius: 0;')
        ->and($boxed)->toContain('data-hero-backdrop="white" style="background-color: #ffffff;"')
        ->and($full)->not->toContain('data-hero-frame');
});
