<?php

use App\Enums\PageKind;
use App\Enums\PreviewLayout;
use App\Enums\Shop\ProductStatus;
use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\BusinessProfile;
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
use App\Support\ChromeKnobs;
use App\Support\Site\ChromeRecipe;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * store_controls_slot moves search + cart out of the nav-link row and into the
 * header's right slot (used with CTA off, so the controls "let breathe").
 * Default `inline` must stay byte-for-byte what every existing standard site
 * renders.
 *
 * @return array{site: Site, home: GeneratedPage}
 */
function storeSlotSite(string $host, ?string $slot, array $themeOverrides = []): array
{
    config(['site.use_versioned_renderer' => true]);

    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'business_name' => 'Verdant Bloom',
        'preview_layout' => PreviewLayout::MultiPage,
        'chrome_layout' => $slot === null ? 'classic' : 'standard-spread',
        'right_action' => 'none',
        'shop_mode' => 'cart',
    ]);
    BusinessProfile::factory()->for($site)->create([
        'profile_data' => ['top_bar_enabled' => false, 'contact' => ['phones' => []]],
    ]);
    if ($slot !== null) {
        LayoutPreset::factory()->for($site)->active()->create([
            'page_kind' => 'chrome',
            'key' => 'standard-spread',
            'label' => 'Standard, spread nav',
            'recipe' => [
                'schema_version' => 1,
                'layout' => 'standard',
                'top_bar' => 'off',
                'nav_row' => 'inline',
                'store_controls' => 'icons+labels',
                'store_controls_slot' => $slot,
            ],
        ]);
    }

    $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'kind' => PageKind::Core, 'nav_label' => 'Home']);
    $rev = PageRevision::factory()->for($home, 'page')->create(['content_data' => ['sections' => [['type' => 'hero', 'title' => 'Welcome']]]]);
    $home->update(['published_revision_id' => $rev->id]);
    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => [['type' => 'page', 'page_id' => $home->id, 'label' => 'Home']]],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold', ...$themeOverrides],
            'homepage_page_id' => $home->id,
        ],
        'page_revisions' => [['page_id' => $home->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    $product = Product::factory()->for($site)->create(['slug' => 'posy', 'name' => 'Posy', 'status' => ProductStatus::Published]);
    $variant = ProductVariant::factory()->for($product)->create(['sku' => 'P-1', 'label' => 'Standard', 'price_cents' => 3800]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 6]);
    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'product_count' => 1,
        'json' => [
            'meta' => ['site_id' => $site->id, 'product_count' => 1],
            'categories' => [],
            'products' => ['posy' => [
                'id' => $product->id, 'slug' => 'posy', 'status' => 'published', 'primary_category_slug' => null,
                'price_cents' => 3800, 'price_display' => '£38.00', 'in_stock_any' => true,
                'variant_in_stock' => [$variant->id => true], 'image_urls' => [],
                'product_card' => ['slug' => 'posy', 'name' => 'Posy', 'price_display' => '£38.00'],
                'product_detail' => ['slug' => 'posy', 'name' => 'Posy', 'description' => 'Stems'],
                'variants' => [['id' => $variant->id, 'sku' => 'P-1', 'label' => 'Standard', 'price_cents' => 3800, 'image_urls' => null]],
                'is_ai_seeded' => false, 'is_ai_reviewed' => false,
            ]],
            'featured_slugs' => ['posy'],
        ],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snap->id, 'updated_at' => now()]);

    return ['site' => $site->fresh(), 'home' => $home->fresh()];
}

function storeSlotHeader(Site $site, GeneratedPage $home): string
{
    $html = app(PageRenderer::class)->render($site->fresh(), $home->id, mode: 'public');
    expect(preg_match('/<header\b[^>]*>.*<\/header>/s', $html, $m))->toBe(1);

    return $m[0];
}

it('accepts inline and right and rejects anything else', function () {
    $base = ['schema_version' => 1, 'layout' => 'standard'];
    expect(ChromeRecipe::errors([...$base, 'store_controls_slot' => 'inline']))->toBe([])
        ->and(ChromeRecipe::errors([...$base, 'store_controls_slot' => 'right']))->toBe([])
        ->and(ChromeRecipe::errors([...$base, 'store_controls_slot' => 'left']))->not->toBeEmpty();
});

it('defaults to inline for classic and unset recipes', function () {
    ['site' => $site, 'home' => $home] = storeSlotSite('slot-default.example', null);

    expect(ChromeKnobs::storeControlsSlot($site))->toBe('inline');
    $header = storeSlotHeader($site, $home);
    expect($header)->toContain('data-shop-search-toggle')
        ->toContain('data-shop-cart-control')
        ->not->toContain('data-store-controls-slot');
});

it('renders the controls once, in the right slot, when the recipe says right', function () {
    ['site' => $site, 'home' => $home] = storeSlotSite('slot-right.example', 'right');

    expect(ChromeKnobs::storeControlsSlot($site))->toBe('right');
    $header = storeSlotHeader($site, $home);

    expect(preg_match('/<div class="hidden md:flex items-center gap-6 ml-10" data-store-controls-slot="right">(.*?)<\/div>/s', $header, $m))->toBe(1);
    expect($m[1])->toContain('data-shop-search-toggle')->toContain('data-shop-cart-control');
    // The inline copy after the nav links is gone: nothing between the desktop
    // nav row and the right slot carries a cart control (the mobile menu still does).
    $navRowStart = strpos($header, 'space-x-8');
    $slotStart = strpos($header, 'data-store-controls-slot="right"');
    expect($navRowStart)->not->toBeFalse()->and($slotStart)->toBeGreaterThan($navRowStart);
    expect(substr($header, $navRowStart, $slotStart - $navRowStart))->not->toContain('data-shop-cart-control');
    expect(substr_count($header, 'data-shop-cart-control'))->toBe(2); // right slot + mobile menu
});

it('keeps the inline placement when the recipe says inline', function () {
    ['site' => $site, 'home' => $home] = storeSlotSite('slot-inline.example', 'inline');

    $header = storeSlotHeader($site, $home);
    expect($header)->toContain('data-shop-cart-control')->not->toContain('data-store-controls-slot');
});

it('steps the right-slot controls up under the grand display scale', function () {
    ['site' => $site, 'home' => $home] = storeSlotSite('slot-grand.example', 'right', ['display_scale_override' => 'grand']);

    $header = storeSlotHeader($site, $home);
    expect(preg_match('/data-store-controls-slot="right">(.*?)<\/div>/s', $header, $m))->toBe(1);
    expect($m[1])->toContain('class="h-5 w-5"')->toContain('text-base font-medium')->not->toContain('h-4 w-4');
});

it('keeps the standard control sizes without grand', function () {
    ['site' => $site, 'home' => $home] = storeSlotSite('slot-std.example', 'right');

    $header = storeSlotHeader($site, $home);
    expect(preg_match('/data-store-controls-slot="right">(.*?)<\/div>/s', $header, $m))->toBe(1);
    expect($m[1])->toContain('class="h-4 w-4"')->toContain('text-sm font-medium')->not->toContain('h-5 w-5');
});

