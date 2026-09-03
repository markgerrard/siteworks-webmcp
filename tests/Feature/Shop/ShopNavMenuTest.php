<?php

use App\Enums\PageKind;
use App\Enums\PageStatus;
use App\Enums\PreviewLayout;
use App\Enums\Shop\ProductStatus;
use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\GeneratedPage;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use App\Support\Shop\ShopNavMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Fixture: 3 visible top-level (Cakes with 2 children, Tarts, Biscuits),
 * one hidden top-level, one hidden child of Cakes.
 *
 * @param  array<string, mixed>  $siteAttrs
 * @return array{site: Site, home: GeneratedPage}
 */
function shopNavMenuSite(string $host, array $siteAttrs = [], bool $withCategories = true, bool $withShopNav = true, bool $withProduct = true): array
{
    config(['site.use_versioned_renderer' => true]);

    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'business_name' => 'Camino Bakery',
        'preview_layout' => PreviewLayout::MultiPage,
        ...$siteAttrs,
    ]);

    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
        'nav_label' => 'Home',
        'status' => PageStatus::Published,
    ]);
    $about = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'about',
        'kind' => PageKind::Core,
        'nav_label' => 'About',
        'status' => PageStatus::Published,
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Welcome']]],
    ]);
    $aboutRev = PageRevision::factory()->for($about, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'About us']]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);
    $about->update(['published_revision_id' => $aboutRev->id]);

    $navItems = [
        ['type' => 'page', 'page_id' => $about->id, 'label' => 'About'],
    ];
    if ($withShopNav) {
        $navItems[] = ['type' => 'shop', 'label' => 'Shop'];
    }

    $composition = [
        'nav' => ['items' => $navItems],
        'footer' => ['columns' => [], 'show_credit' => true],
        'theme' => ['key' => 'trades-bold'],
        'homepage_page_id' => $home->id,
    ];

    SiteDraft::create([
        'site_id' => $site->id,
        'composition' => $composition,
        'updated_at' => now(),
    ]);
    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => $composition,
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

    $product = null;
    if ($withProduct) {
        $product = Product::factory()->for($site)->create([
            'slug' => 'victoria',
            'name' => 'Victoria sponge',
            'status' => ProductStatus::Published,
        ]);
        $variant = ProductVariant::factory()->for($product)->create([
            'sku' => 'VS-1',
            'price_cents' => 1800,
        ]);
        VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 4]);
    }

    $categories = [];
    if ($withCategories) {
        $categories = [
            'cakes' => [
                'id' => 1,
                'slug' => 'cakes',
                'name' => 'Celebration Cakes',
                'parent_slug' => null,
                'path' => 'cakes',
                'depth' => 1,
                'visibility' => 'visible',
                'sort_order' => 1,
                'children' => ['birthdays', 'wedding-cakes'],
                'product_slugs' => $product ? ['victoria'] : [],
            ],
            'birthdays' => [
                'id' => 2,
                'slug' => 'birthdays',
                'name' => 'Birthdays',
                'parent_slug' => 'cakes',
                'path' => 'cakes/birthdays',
                'depth' => 2,
                'visibility' => 'visible',
                'sort_order' => 1,
                'children' => [],
                'product_slugs' => [],
            ],
            'wedding-cakes' => [
                'id' => 3,
                'slug' => 'wedding-cakes',
                'name' => 'Weddings',
                'parent_slug' => 'cakes',
                'path' => 'cakes/wedding-cakes',
                'depth' => 2,
                'visibility' => 'visible',
                'sort_order' => 2,
                'children' => [],
                'product_slugs' => [],
            ],
            'internal' => [
                'id' => 4,
                'slug' => 'internal',
                'name' => 'Staff only',
                'parent_slug' => 'cakes',
                'path' => 'cakes/internal',
                'depth' => 2,
                'visibility' => 'hidden',
                'sort_order' => 3,
                'children' => [],
                'product_slugs' => [],
            ],
            'tarts' => [
                'id' => 5,
                'slug' => 'tarts',
                'name' => 'Tarts',
                'parent_slug' => null,
                'path' => 'tarts',
                'depth' => 1,
                'visibility' => 'visible',
                'sort_order' => 2,
                'children' => [],
                'product_slugs' => [],
            ],
            'biscuits' => [
                'id' => 6,
                'slug' => 'biscuits',
                'name' => 'Biscuits',
                'parent_slug' => null,
                'path' => 'biscuits',
                'depth' => 1,
                'visibility' => 'visible',
                'sort_order' => 3,
                'children' => [],
                'product_slugs' => [],
            ],
            'secret' => [
                'id' => 7,
                'slug' => 'secret',
                'name' => 'Secret shelf',
                'parent_slug' => null,
                'path' => 'secret',
                'depth' => 1,
                'visibility' => 'hidden',
                'sort_order' => 4,
                'children' => ['secret-child'],
                'product_slugs' => [],
            ],
            'secret-child' => [
                'id' => 8,
                'slug' => 'secret-child',
                'name' => 'Hidden child of hidden',
                'parent_slug' => 'secret',
                'path' => 'secret/secret-child',
                'depth' => 2,
                'visibility' => 'visible',
                'sort_order' => 1,
                'children' => [],
                'product_slugs' => [],
            ],
        ];
    }

    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'product_count' => $product ? 1 : 0,
        'json' => [
            'meta' => ['site_id' => $site->id, 'product_count' => $product ? 1 : 0],
            'categories' => $categories,
            'products' => $product ? [
                'victoria' => [
                    'id' => $product->id,
                    'slug' => 'victoria',
                    'status' => 'published',
                    'name' => 'Victoria sponge',
                ],
            ] : [],
            'featured_slugs' => [],
        ],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create([
        'site_id' => $site->id,
        'snapshot_id' => $snap->id,
        'updated_at' => now(),
    ]);

    return ['site' => $site->fresh(), 'home' => $home->fresh()];
}

function shopNavItem(Site $site): ?array
{
    $items = app(PageRenderer::class)->layoutContext($site)['navItems'];

    return collect($items)->first(
        fn (array $item): bool => ($item['type'] ?? null) === 'shop'
            || (($item['type'] ?? null) === 'group' && ($item['label'] ?? '') === 'Shop')
            || (($item['shop_nav_style'] ?? null) !== null),
    );
}

test('default and link knobs leave Shop as a plain /shop href', function () {
    ['site' => $default] = shopNavMenuSite('shop-nav-default.example');
    ['site' => $link] = shopNavMenuSite('shop-nav-link.example', ['shop_nav_style' => 'link']);

    $defaultShop = shopNavItem($default);
    $linkShop = shopNavItem($link);

    expect($defaultShop)->not->toBeNull()
        ->and($defaultShop['type'])->toBe('shop')
        ->and($defaultShop['href'])->toBe('/shop')
        ->and($defaultShop)->not->toHaveKey('children')
        ->and($linkShop['type'])->toBe('shop')
        ->and($linkShop['href'])->toBe('/shop');
});

test('dropdown expands visible top-level categories in sort order with nested paths', function () {
    ['site' => $site] = shopNavMenuSite('shop-nav-dropdown.example', ['shop_nav_style' => 'dropdown']);

    $shop = shopNavItem($site);

    expect($shop['type'])->toBe('group')
        ->and($shop['label'])->toBe('Shop')
        ->and($shop['href'])->toBe('/shop')
        ->and($shop['shop_nav_style'])->toBe('dropdown')
        ->and(collect($shop['children'])->pluck('label')->all())->toBe(['Celebration Cakes', 'Tarts', 'Biscuits'])
        ->and(collect($shop['children'])->pluck('href')->all())->toBe([
            '/collections/cakes',
            '/collections/tarts',
            '/collections/biscuits',
        ]);

    $cakes = $shop['children'][0];
    expect(collect($cakes['children'])->pluck('label')->all())->toBe(['Birthdays', 'Weddings'])
        ->and(collect($cakes['children'])->pluck('href')->all())->toBe([
            '/collections/cakes/birthdays',
            '/collections/cakes/wedding-cakes',
        ]);
});

test('dropdown and mega prune hidden categories and their subtrees', function (string $style) {
    ['site' => $site] = shopNavMenuSite('shop-nav-hidden-'.$style.'.example', ['shop_nav_style' => $style]);

    $labels = collect(shopNavItem($site)['children'])->flatMap(function (array $item) {
        $own = [$item['label']];
        foreach ($item['children'] ?? [] as $child) {
            $own[] = $child['label'];
        }

        return $own;
    })->all();

    expect($labels)->not->toContain('Secret shelf')
        ->and($labels)->not->toContain('Staff only')
        ->and($labels)->not->toContain('Hidden child of hidden')
        ->and($labels)->not->toContain('secret');
})->with(['dropdown', 'mega']);

test('mega panel is position fixed against the viewport and the wrapper stays relative for hover', function () {
    ['site' => $site] = shopNavMenuSite('shop-nav-mega-cb.example', ['shop_nav_style' => 'mega']);
    $html = $this->get('http://shop-nav-mega-cb.example/')->assertOk()->getContent();

    expect($html)->toContain('data-shop-nav-style="mega"')
        ->and($html)->toContain('data-shop-mega-panel')
        ->and($html)->toContain('data-shop-mega-bridge')
        ->and($html)->toContain('position: fixed')
        ->and($html)->toContain('left: 0')
        ->and($html)->toContain('right: 0')
        ->and($html)->not->toContain('margin-left: -50vw')
        ->and($html)->not->toContain('class=""');

    preg_match('/<div x-data="\{ open: false[^"]*\}" class="([^"]*)" data-shop-nav-style="mega"/', $html, $m);
    expect($m)->not->toBeEmpty()
        ->and($m[1])->toContain('relative');

    ['site' => $dropdown] = shopNavMenuSite('shop-nav-dd-cb.example', ['shop_nav_style' => 'dropdown']);
    $html = $this->get('http://shop-nav-dd-cb.example/')->assertOk()->getContent();
    preg_match('/<div x-data="\{ open: false \}" class="([^"]*)" data-shop-nav-style="dropdown"/', $html, $m);

    expect($m)->not->toBeEmpty()
        ->and($m[1])->toContain('relative')
        ->and($html)->not->toContain('data-shop-mega-panel')
        ->and($html)->not->toContain('position: fixed');
});

test('mega keeps the same visible tree and appends All products', function () {
    ['site' => $site] = shopNavMenuSite('shop-nav-mega.example', ['shop_nav_style' => 'mega']);

    $shop = shopNavItem($site);

    expect($shop['shop_nav_style'])->toBe('mega')
        ->and($shop['href'])->toBe('/shop')
        ->and(collect($shop['children'])->pluck('label')->all())->toBe(['Celebration Cakes', 'Tarts', 'Biscuits'])
        ->and($shop['all_products_href'])->toBe('/shop');
});

test('mega with no visible categories stays a plain Shop link', function () {
    ['site' => $site] = shopNavMenuSite('shop-nav-mega-empty.example', ['shop_nav_style' => 'mega'], withCategories: false);

    $shop = shopNavItem($site);

    expect($shop['type'])->toBe('shop')
        ->and($shop['href'])->toBe('/shop')
        ->and($shop)->not->toHaveKey('children');
});

test('mega without a purchasable shop does not invent a Shop group', function () {
    ['site' => $site] = shopNavMenuSite(
        'shop-nav-mega-noshop.example',
        ['shop_nav_style' => 'mega', 'shop_enabled' => false],
        withShopNav: false,
        withProduct: false,
    );

    expect(shopNavItem($site))->toBeNull();
});

test('expand does not mutate stored composition', function () {
    ['site' => $site] = shopNavMenuSite('shop-nav-stored.example', ['shop_nav_style' => 'dropdown']);

    app(PageRenderer::class)->layoutContext($site);

    $stored = SiteVersion::query()->where('site_id', $site->id)->value('composition')['nav']['items'];

    expect(collect($stored)->pluck('type')->all())->toBe(['page', 'shop'])
        ->and(collect($stored)->firstWhere('type', 'shop'))->not->toHaveKey('children');
});

test('shop dropdown and mega expose haspopup expanded and escape-to-close', function (string $style) {
    ['site' => $site, 'home' => $home] = shopNavMenuSite('shop-nav-a11y-'.$style.'.example', ['shop_nav_style' => $style]);

    $html = app(PageRenderer::class)->render($site, $home->id, mode: 'public');

    expect($html)->toContain('aria-haspopup="true"')
        ->and($html)->toContain(':aria-expanded="open ? \'true\' : \'false\'"')
        ->and($html)->toContain('keydown.escape.window')
        ->and($html)->toContain('x-ref="shopTrigger"')
        ->and($html)->toMatch('/<nav\b/');
})->with(['dropdown', 'mega']);

test('a11y attributes are not added to an ordinary services group', function () {
    ['site' => $site, 'home' => $home] = shopNavMenuSite('shop-nav-a11y-group.example', ['shop_nav_style' => 'link']);
    $version = SiteVersion::query()->where('site_id', $site->id)->first();
    $composition = $version->composition;
    $composition['nav']['items'] = [
        ['type' => 'group', 'label' => 'Services', 'children' => [
            ['type' => 'page', 'page_id' => $home->id, 'label' => 'Home'],
        ]],
        ['type' => 'shop', 'label' => 'Shop'],
    ];
    $version->update(['composition' => $composition]);

    $html = app(PageRenderer::class)->render($site->fresh(), $home->id, mode: 'public');

    expect($html)->not->toContain('aria-haspopup="true"')
        ->and($html)->toContain('Services')
        ->and($html)->toMatch('/<nav\b/');
});

test('dropdown header markup links Shop to /shop and lists nested hrefs with a chevron', function () {
    ['site' => $site, 'home' => $home] = shopNavMenuSite('shop-nav-drop-html.example', ['shop_nav_style' => 'dropdown']);

    $html = app(PageRenderer::class)->render($site, $home->id, mode: 'public');

    expect($html)->toContain('data-shop-nav-style="dropdown"')
        ->and($html)->toContain('href="/shop"')
        ->and($html)->toContain('href="/collections/cakes"')
        ->and($html)->toContain('href="/collections/cakes/birthdays"')
        ->and($html)->toContain('href="/collections/tarts"')
        ->and($html)->toContain('›')
        ->and($html)->not->toContain('Secret shelf')
        ->and($html)->not->toContain('/collections/secret')
        ->and($html)->not->toContain('Staff only');
});

test('mega header markup is a full-width panel with column headings and All products', function () {
    ['site' => $site, 'home' => $home] = shopNavMenuSite('shop-nav-mega-html.example', ['shop_nav_style' => 'mega']);

    $html = app(PageRenderer::class)->render($site, $home->id, mode: 'public');

    expect($html)->toContain('data-shop-nav-style="mega"')
        ->and($html)->toContain('href="/collections/cakes"')
        ->and($html)->toContain('href="/collections/cakes/birthdays"')
        ->and($html)->toContain('All products')
        ->and($html)->toContain('var(--color-surface)');
});

test('mobile drawer renders Shop as an accordion with expandable children', function () {
    ['site' => $site, 'home' => $home] = shopNavMenuSite('shop-nav-mobile.example', ['shop_nav_style' => 'dropdown']);

    $html = app(PageRenderer::class)->render($site, $home->id, mode: 'public');

    expect($html)->toContain('data-shop-nav-mobile')
        ->and($html)->toMatch('/id="mobile-nav-panel"[\s\S]*data-shop-nav-mobile/')
        ->and($html)->toContain('href="/collections/cakes/birthdays"');
});

test('link knob render is byte-identical to an unset knob on the same shop', function () {
    ['site' => $unset, 'home' => $homeUnset] = shopNavMenuSite('shop-nav-ident-unset.example');
    ['site' => $link, 'home' => $homeLink] = shopNavMenuSite('shop-nav-ident-link.example', ['shop_nav_style' => 'link']);

    $renderer = app(PageRenderer::class);
    $unsetHtml = $renderer->render($unset, $homeUnset->id, mode: 'public');
    $linkHtml = $renderer->render($link, $homeLink->id, mode: 'public');

    $strip = static function (string $html): string {
        return preg_replace('/shop-nav-ident-(unset|link)/', 'HOST', $html) ?? $html;
    };

    expect($strip($unsetHtml))->toBe($strip($linkHtml))
        ->and($unsetHtml)->not->toContain('data-shop-nav-style')
        ->and($unsetHtml)->toContain('href="/shop"');
});

test('the public page cache holds the shop header separately from the snapshot', function () {
    config(['site.public_cache_enabled' => true]);

    ['site' => $site] = shopNavMenuSite('shop-nav-cache-split.example', ['shop_nav_style' => 'dropdown']);

    $url = 'http://shop-nav-cache-split.example/';
    $first = $this->get($url)->assertSuccessful()->getContent();
    expect($first)->toContain('Celebration Cakes');

    $row = ShopSnapshot::query()->where('site_id', $site->id)->latest('id')->first();
    $json = $row->json;
    $json['categories']['cakes']['name'] = 'Party Cakes';
    $row->update(['json' => $json]);
    app(\App\Services\Shop\SnapshotReader::class)->invalidate($site->id);

    $stale = $this->get($url)->assertSuccessful()->getContent();
    expect($stale)->toContain('Celebration Cakes')
        ->and($stale)->not->toContain('Party Cakes');

    app(\App\Services\Site\PublicPageCache::class)->invalidate($site);

    $fresh = $this->get($url)->assertSuccessful()->getContent();
    expect($fresh)->toContain('Party Cakes')
        ->and($fresh)->not->toContain('Celebration Cakes');
});

test('ShopNavMenu::categories returns visible top-level nodes in snapshot order', function () {
    ['site' => $site] = shopNavMenuSite('shop-nav-reader.example', ['shop_nav_style' => 'dropdown']);

    $tree = ShopNavMenu::categories($site);

    expect(collect($tree)->pluck('label')->all())->toBe(['Celebration Cakes', 'Tarts', 'Biscuits'])
        ->and($tree[0]['href'])->toBe('/collections/cakes')
        ->and(collect($tree[0]['children'])->pluck('href')->all())->toBe([
            '/collections/cakes/birthdays',
            '/collections/cakes/wedding-cakes',
        ]);
});
