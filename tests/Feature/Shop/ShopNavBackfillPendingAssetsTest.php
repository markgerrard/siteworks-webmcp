<?php

// Pins the site:shop-nav --backfill cleanliness guard for PENDING ASSET SELECTIONS
// (hero/logo draft channel): publishSite promotes them and rollback does not restore
// them, so a system backfill must treat them as merchant edits and hold off. This
// guard previously shipped without a committed test. The control proves the
// fixture shape IS publish-eligible so the two holds cannot pass vacuously.

use App\Enums\PageStatus;
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
use App\Models\Site\SiteDraft;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\Editor\DraftAssetSelections;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A site whose draft matches the published composition and page pins exactly, with a
 * purchasable shop and no Shop entry yet — i.e. the backfill's publish path is armed.
 *
 * @return array{site: Site, draft: SiteDraft, version: SiteVersion}
 */
function cleanPurchasableShopSite(string $host): array
{
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
    ]);
    $about = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'about', 'nav_label' => 'About', 'status' => PageStatus::Published,
    ]);
    $contact = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'contact', 'nav_label' => 'Contact', 'status' => PageStatus::Published,
    ]);
    $aboutRev = PageRevision::factory()->for($about, 'page')->create();
    $contactRev = PageRevision::factory()->for($contact, 'page')->create();
    $about->update(['published_revision_id' => $aboutRev->id]);
    $contact->update(['published_revision_id' => $contactRev->id]);

    $composition = [
        'nav' => ['items' => [
            ['type' => 'page', 'page_id' => $about->id, 'label' => 'About'],
            ['type' => 'page', 'page_id' => $contact->id, 'label' => 'Contact'],
        ]],
        'footer' => ['columns' => [], 'show_credit' => true],
        'theme' => ['key' => 'trades-bold'],
        'homepage_page_id' => null,
    ];
    $draft = SiteDraft::create(['site_id' => $site->id, 'composition' => $composition, 'updated_at' => now()]);
    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => $composition,
        'page_revisions' => [
            ['page_id' => $about->id, 'revision_id' => $aboutRev->id],
            ['page_id' => $contact->id, 'revision_id' => $contactRev->id],
        ],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    $product = Product::factory()->for($site)->create([
        'status' => \App\Enums\Shop\ProductStatus::Published,
    ]);
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 1500]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 2]);

    $snap = ShopSnapshot::create([
        'site_id' => $site->id, 'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'product_count' => 1,
        'json' => ['meta' => ['site_id' => $site->id], 'products' => [['slug' => 'p', 'status' => 'published']]],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snap->id, 'updated_at' => now()]);

    return compact('site', 'draft', 'version');
}

test('control: a clean purchasable site is published by the backfill', function () {
    ['site' => $site, 'draft' => $draft, 'version' => $version] = cleanPurchasableShopSite('backfill-ctl.example');

    expect(app(DraftAssetSelections::class)->any($site))->toBeFalse()
        ->and($site->fresh()->hasPurchasableShop())->toBeTrue();

    $this->artisan('site:shop-nav', ['--backfill' => true])->assertSuccessful();

    $newVersion = SiteVersion::query()->where('site_id', $site->id)->latest('version')->firstOrFail();

    expect($newVersion->id)->not->toBe($version->id)
        ->and($newVersion->publish_note)->toBe('shop-nav-backfill')
        ->and(SiteVersionCurrent::where('site_id', $site->id)->value('version_id'))->toBe($newVersion->id)
        ->and(collect($newVersion->composition['nav']['items'])->pluck('label')->all())->toBe(['About', 'Shop', 'Contact']);
});

test('a pending hero selection holds the backfill back from publishing', function () {
    ['site' => $site, 'draft' => $draft, 'version' => $version] = cleanPurchasableShopSite('backfill-hero.example');
    $hero = HeroVersion::factory()->for($site)->create(['page_type' => 'home', 'slot' => 'hero']);
    app(DraftAssetSelections::class)->setHero($site, 'home', 'hero', $hero, null);
    $versionsBefore = SiteVersion::where('site_id', $site->id)->count();

    expect(app(DraftAssetSelections::class)->any($site))->toBeTrue();

    $this->artisan('site:shop-nav', ['--backfill' => true])
        ->expectsOutputToContain("site {$site->id}: pending merchant edits — Shop entry will appear on next publish")
        ->assertSuccessful();

    expect(SiteVersion::where('site_id', $site->id)->count())->toBe($versionsBefore)
        ->and(SiteVersionCurrent::where('site_id', $site->id)->value('version_id'))->toBe($version->id)
        ->and(SiteVersion::find($version->id)->publish_note)->toBeNull()
        ->and(app(DraftAssetSelections::class)->any($site))->toBeTrue()
        ->and($hero->fresh()->is_active)->toBeFalse()
        ->and(collect($draft->fresh()->composition['nav']['items'])->pluck('label')->all())->toBe(['About', 'Shop', 'Contact']);
});

test('a pending logo selection holds the backfill back from publishing', function () {
    ['site' => $site, 'draft' => $draft, 'version' => $version] = cleanPurchasableShopSite('backfill-logo.example');
    $logo = \App\Models\LogoConcept::factory()->for($site)->create();
    app(DraftAssetSelections::class)->setLogo($site, $logo, null);
    $versionsBefore = SiteVersion::where('site_id', $site->id)->count();

    expect(app(DraftAssetSelections::class)->any($site))->toBeTrue();

    $this->artisan('site:shop-nav', ['--backfill' => true])
        ->expectsOutputToContain("site {$site->id}: pending merchant edits — Shop entry will appear on next publish")
        ->assertSuccessful();

    expect(SiteVersion::where('site_id', $site->id)->count())->toBe($versionsBefore)
        ->and(SiteVersionCurrent::where('site_id', $site->id)->value('version_id'))->toBe($version->id)
        ->and(app(DraftAssetSelections::class)->any($site))->toBeTrue();
});
