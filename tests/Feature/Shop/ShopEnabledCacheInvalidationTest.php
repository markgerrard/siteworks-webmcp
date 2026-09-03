<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Enums\PreviewLayout;
use App\Enums\Shop\ProductStatus;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\GeneratedPage;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Models\User;
use App\Services\Shop\SnapshotBuilder;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * @return array{0: Site, 1: User}
 */
function shopFlagCachedPublicSite(string $host, bool $shopEnabled): array
{
    config([
        'site.use_versioned_renderer' => true,
        'site.public_cache_enabled' => true,
    ]);
    Cache::flush();

    $actor = User::factory()->staff(AgentRole::Admin)->create();
    $site = Site::factory()
        ->{$shopEnabled ? 'shopEnabled' : 'shopDisabled'}()
        ->create([
        'created_by_user_id' => $actor->id,
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'preview_layout' => PreviewLayout::MultiPage,
    ]);
    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'status' => PageStatus::Published,
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Home']]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);
    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => [['type' => 'shop', 'label' => 'Shop']]],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'],
            'homepage_page_id' => $home->id,
        ],
        'page_revisions' => [['page_id' => $home->id, 'revision_id' => $homeRev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::query()->updateOrCreate(
        ['site_id' => $site->id],
        ['version_id' => $version->id, 'updated_at' => now()],
    );

    $product = Product::factory()->published()->for($site)->create([
        'status' => ProductStatus::Published,
        'slug' => 'flag-cache-item',
    ]);
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 900]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 3]);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));

    return [$site->fresh(), $actor];
}

test('toggling the shop flag invalidates cached home HTML and sitemap on ON to OFF and OFF to ON', function () {
    [$site, $admin] = shopFlagCachedPublicSite('flag-cache.example', shopEnabled: true);
    $homeUrl = 'http://flag-cache.example/';
    $mapUrl = 'http://flag-cache.example/sitemap.xml';

    $onHtml = $this->get($homeUrl)->assertSuccessful()->getContent();
    $onMap = $this->get($mapUrl)->assertSuccessful()->getContent();
    expect($onHtml)->toContain('href="/shop"')
        ->and($onMap)->toContain('/shop');

    Livewire::actingAs($admin)
        ->test('site-shop-enabled', ['siteId' => $site->id])
        ->call('toggle');

    expect($site->refresh()->shopEnabled())->toBeFalse();

    $offHtml = $this->get($homeUrl)->assertSuccessful()->getContent();
    $offMap = $this->get($mapUrl)->assertSuccessful()->getContent();
    expect($offHtml)->not->toBe($onHtml)
        ->and($offHtml)->not->toContain('href="/shop"')
        ->and($offMap)->not->toBe($onMap)
        ->and($offMap)->not->toContain('/shop');

    $site->update(['shop_enabled' => true]);

    $onAgainHtml = $this->get($homeUrl)->assertSuccessful()->getContent();
    $onAgainMap = $this->get($mapUrl)->assertSuccessful()->getContent();
    expect($onAgainHtml)->not->toBe($offHtml)
        ->and($onAgainHtml)->toContain('href="/shop"')
        ->and($onAgainMap)->not->toBe($offMap)
        ->and($onAgainMap)->toContain('/shop');
});
