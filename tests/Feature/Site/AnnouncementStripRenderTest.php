<?php

use App\Enums\PageKind;
use App\Enums\PreviewLayout;
use App\Enums\Shop\ProductStatus;
use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\GeneratedPage;
use App\Models\LayoutPreset;
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

/**
 * @param  array<string, mixed>  $siteAttrs
 */
function announcementStripSite(string $host, string $chrome = 'classic', array $siteAttrs = []): array
{
    config(['site.use_versioned_renderer' => true]);

    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'business_name' => 'Bloom & Stem',
        'preview_layout' => PreviewLayout::MultiPage,
        'chrome_layout' => $chrome,
        ...$siteAttrs,
    ]);

    if ($chrome !== 'classic') {
        LayoutPreset::factory()->for($site)->active()->create([
            'page_kind' => 'chrome',
            'key' => $chrome,
            'label' => 'Centred badge',
            'recipe' => [
                'schema_version' => 1,
                'layout' => 'centred',
                'top_bar' => 'off',
                'nav_row' => 'beneath',
                'nav_case' => 'caps',
                'logo_height' => 'md',
                'store_controls' => 'icons+labels',
                'sticky_shrink' => 'on',
            ],
        ]);
    }

    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
        'nav_label' => 'Home',
    ]);
    $rev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Welcome']]],
    ]);
    $home->update(['published_revision_id' => $rev->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => [
                ['type' => 'page', 'page_id' => $home->id, 'label' => 'Home'],
            ]],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'],
            'homepage_page_id' => $home->id,
        ],
        'page_revisions' => [
            ['page_id' => $home->id, 'revision_id' => $rev->id],
        ],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);

    return ['site' => $site->fresh(), 'home' => $home->fresh()];
}

function announcementStripSiteHtml(Site $site, GeneratedPage $page): string
{
    return app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');
}

/**
 * @param  array<string, mixed>  $siteAttrs
 */
function announcementStripShopHtml(string $host, array $siteAttrs = []): string
{
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'business_name' => 'Bloom & Stem',
        ...$siteAttrs,
    ]);

    $product = Product::factory()->published()->for($site)->create([
        'slug' => 'rose',
        'name' => 'Red Rose',
        'status' => ProductStatus::Published,
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'sku' => 'RR-1',
        'label' => 'Std',
        'price_cents' => 4500,
    ]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 3]);

    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => [
            'meta' => ['site_id' => $site->id, 'product_count' => 1],
            'categories' => [],
            'products' => [
                'rose' => [
                    'id' => $product->id,
                    'slug' => 'rose',
                    'status' => 'published',
                    'primary_category_slug' => null,
                    'price_cents' => 4500,
                    'price_display' => '£45.00',
                    'in_stock_any' => true,
                    'variant_in_stock' => [$variant->id => true],
                    'image_urls' => null,
                    'product_card' => ['slug' => 'rose', 'name' => 'Red Rose', 'price_display' => '£45.00'],
                    'product_detail' => ['slug' => 'rose', 'name' => 'Red Rose', 'description' => 'Lovely'],
                    'variants' => [['id' => $variant->id, 'sku' => 'RR-1', 'label' => 'Std', 'price_cents' => 4500, 'image_urls' => null]],
                    'is_ai_seeded' => false,
                    'is_ai_reviewed' => false,
                ],
            ],
            'featured_slugs' => ['rose'],
        ],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create([
        'site_id' => $site->id,
        'snapshot_id' => $snap->id,
        'updated_at' => now(),
    ]);

    return test()->get('http://'.$host.'/shop')->assertOk()->getContent();
}

function announcementStripSitsAboveHeader(string $html): void
{
    $stripPos = strpos($html, 'data-announcement-strip');
    $headerPos = strpos($html, '<header');

    expect($stripPos)->not->toBeFalse()
        ->and($headerPos)->not->toBeFalse()
        ->and($stripPos)->toBeLessThan($headerPos);
}

test('a default site emits no announcement strip markup', function () {
    ['site' => $site, 'home' => $home] = announcementStripSite('announce-off.example');
    $html = announcementStripSiteHtml($site, $home);

    expect($html)->not->toContain('data-announcement-strip')
        ->and($html)->not->toContain('aria-label="Announcement"');
});

test('enabled with empty messages emits no announcement strip markup', function () {
    ['site' => $site, 'home' => $home] = announcementStripSite('announce-empty.example', siteAttrs: [
        'announcement_enabled' => true,
        'announcement_messages' => [],
    ]);
    $html = announcementStripSiteHtml($site, $home);

    expect($html)->not->toContain('data-announcement-strip');
});

test('a single message renders static centred text above the header on both layouts', function (string $chrome) {
    ['site' => $site, 'home' => $home] = announcementStripSite(
        'announce-one-'.$chrome.'.example',
        $chrome,
        [
            'announcement_enabled' => true,
            'announcement_messages' => [
                ['text' => 'Made by hand, crafted by a local florist'],
            ],
        ],
    );
    $html = announcementStripSiteHtml($site, $home);

    announcementStripSitsAboveHeader($html);
    expect($html)->toContain('Made by hand, crafted by a local florist')
        ->and($html)->toContain('aria-label="Announcement"')
        ->and($html)->not->toContain('aria-live="polite"')
        ->and($html)->not->toContain('aria-label="Previous announcement"')
        ->and($html)->not->toContain('aria-label="Next announcement"')
        ->and($html)->toContain('background-color: var(--color-accent)')
        ->and($html)->toContain('color: var(--color-accent-text)');
})->with(['classic', 'centred-badge']);

test('a linked single message wraps the text in the url', function () {
    ['site' => $site, 'home' => $home] = announcementStripSite('announce-link.example', siteAttrs: [
        'announcement_enabled' => true,
        'announcement_messages' => [
            ['text' => 'Free delivery this weekend', 'url' => '/shop'],
        ],
    ]);
    $html = announcementStripSiteHtml($site, $home);

    expect($html)->toContain('href="/shop"')
        ->and($html)->toContain('Free delivery this weekend')
        ->and($html)->not->toContain('aria-live="polite"');
});

test('multiple messages render prev/next controls, aria-live, and no autoplay', function () {
    ['site' => $site, 'home' => $home] = announcementStripSite('announce-many.example', siteAttrs: [
        'announcement_enabled' => true,
        'announcement_messages' => [
            ['text' => 'Hand-tied by a local florist'],
            ['text' => 'Same-day delivery in season', 'url' => 'https://example.com/delivery'],
        ],
    ]);
    $html = announcementStripSiteHtml($site, $home);

    announcementStripSitsAboveHeader($html);
    expect($html)->toContain('Hand-tied by a local florist')
        ->and($html)->toContain('Same-day delivery in season')
        ->and($html)->toContain('href="https://example.com/delivery"')
        ->and($html)->toContain('aria-live="polite"')
        ->and($html)->toContain('aria-label="Previous announcement"')
        ->and($html)->toContain('aria-label="Next announcement"')
        ->and($html)->toContain('type="button"')
        ->and($html)->not->toContain('setInterval')
        ->and($html)->not->toContain('setTimeout');
});

test('a background override paints the strip and contrasts the text', function (string $bg, string $ink) {
    ['site' => $site, 'home' => $home] = announcementStripSite('announce-bg-'.ltrim($bg, '#').'.example', siteAttrs: [
        'announcement_enabled' => true,
        'announcement_bg' => $bg,
        'announcement_messages' => [
            ['text' => 'Seasonal stems, same-day'],
        ],
    ]);
    $html = announcementStripSiteHtml($site, $home);

    expect($html)->toContain('background-color: '.$bg)
        ->and($html)->toContain('color: '.$ink)
        ->and($html)->not->toContain('background-color: var(--color-accent)');
})->with([
    'dark' => ['#111111', '#ffffff'],
    'light' => ['#f5f5f5', '#111111'],
]);

test('the shop layout omits the strip when the knob is off', function () {
    $html = announcementStripShopHtml('announce-shop-off.example');

    expect($html)->not->toContain('data-announcement-strip');
});

test('the shop layout paints the strip above the header when enabled', function () {
    $html = announcementStripShopHtml('announce-shop-on.example', [
        'announcement_enabled' => true,
        'announcement_messages' => [
            ['text' => 'Free greenery with every bouquet'],
            ['text' => 'Click and collect from noon'],
        ],
    ]);

    announcementStripSitsAboveHeader($html);
    expect($html)->toContain('Free greenery with every bouquet')
        ->and($html)->toContain('Click and collect from noon')
        ->and($html)->toContain('aria-live="polite"')
        ->and($html)->toContain('aria-label="Previous announcement"');
});
