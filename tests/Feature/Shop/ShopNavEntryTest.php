<?php

use App\Console\Commands\Shop\PruneOldSnapshots;
use App\Enums\PageStatus;
use App\Enums\Shop\ProductStatus;
use App\Enums\Shop\ShopSnapshotStatus;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\GeneratedPage;
use App\Models\Shop\Product;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Shop\SnapshotBuilder;
use App\Services\Site\PublicPageCache;
use App\Services\Site\SitePublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * @return array{site: Site, draft: SiteDraft, version: SiteVersion, contact: GeneratedPage}
 */
function siteWithStoredNavigation(string $host): array
{
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
    ]);
    $about = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'about',
        'nav_label' => 'About',
        'status' => PageStatus::Published,
    ]);
    $contact = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'contact',
        'nav_label' => 'Contact',
        'status' => PageStatus::Published,
    ]);
    $aboutRevision = PageRevision::factory()->for($about, 'page')->create();
    $contactRevision = PageRevision::factory()->for($contact, 'page')->create();
    $about->update(['published_revision_id' => $aboutRevision->id]);
    $contact->update(['published_revision_id' => $contactRevision->id]);
    $composition = [
        'nav' => ['items' => [
            ['type' => 'page', 'page_id' => $about->id, 'label' => 'About'],
            ['type' => 'page', 'page_id' => $contact->id, 'label' => 'Contact'],
        ]],
        'footer' => ['columns' => [], 'show_credit' => true],
        'theme' => ['key' => 'trades-bold'],
        'homepage_page_id' => null,
    ];
    $draft = SiteDraft::create([
        'site_id' => $site->id,
        'composition' => $composition,
        'updated_at' => now(),
    ]);
    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => $composition,
        'page_revisions' => [
            ['page_id' => $about->id, 'revision_id' => $aboutRevision->id],
            ['page_id' => $contact->id, 'revision_id' => $contactRevision->id],
        ],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);

    return compact('site', 'draft', 'version', 'contact');
}

/** @return list<array<string, mixed>> */
function storedNavItems(SiteDraft|SiteVersion $storedComposition): array
{
    return $storedComposition->fresh()->composition['nav']['items'] ?? [];
}

function establishShopSnapshot(Site $site): void
{
    // A snapshot alone is not a shop — the gate is "there is something to buy"
    // (see Site::hasPurchasableShop), so the catalogue has to be non-empty.
    $product = Product::factory()->for($site)->create([
        'status' => ProductStatus::Published,
    ]);
    $variant = \App\Models\Shop\ProductVariant::factory()->for($product)->create(['price_cents' => 1500]);
    \App\Models\Shop\VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 2]);

    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));
}

function rebuildShopSnapshot(Site $site): void
{
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));
}

function removeShopNavItems(SiteDraft|SiteVersion $storedComposition): void
{
    $composition = $storedComposition->fresh()->composition;
    $composition['nav']['items'] = collect($composition['nav']['items'])
        ->reject(fn (array $item): bool => ($item['type'] ?? null) === 'shop')
        ->values()
        ->all();
    $storedComposition->update(['composition' => $composition]);
}

function snapshotJsonHasPublishedProduct(mixed $json): bool
{
    if (is_string($json)) {
        $json = json_decode($json, true);
    }
    if (! is_array($json)) {
        return false;
    }

    foreach ($json['products'] ?? [] as $product) {
        if (($product['status'] ?? 'published') === 'published') {
            return true;
        }
    }

    return false;
}

test('first snapshot adds exactly one Shop entry before Contact to the draft only', function () {
    ['site' => $site, 'draft' => $draft, 'version' => $version] = siteWithStoredNavigation('first-shop.example');
    $publishedBefore = $version->fresh()->getAttributes();
    $cache = app(PublicPageCache::class);
    expect($cache->generation($site))->toBe(0);

    establishShopSnapshot($site);

    $items = storedNavItems($draft);
    expect(collect($items)->where('type', 'shop')->values()->all())
        ->toBe([['type' => 'shop', 'label' => 'Shop']])
        ->and(collect($items)->pluck('label')->all())
        ->toBe(['About', 'Shop', 'Contact'])
        ->and($version->fresh()->getAttributes())->toBe($publishedBefore);

    expect($cache->generation($site))->toBe(1);
});

test('snapshot rebuild does not duplicate Shop or Contact', function () {
    ['site' => $site, 'draft' => $draft, 'version' => $version] = siteWithStoredNavigation('rebuild-shop.example');
    establishShopSnapshot($site);
    $draftAfterFirstBuild = storedNavItems($draft);
    $versionAfterFirstBuild = storedNavItems($version);

    establishShopSnapshot($site);

    expect(storedNavItems($draft))->toBe($draftAfterFirstBuild)
        ->and(storedNavItems($version))->toBe($versionAfterFirstBuild)
        ->and(collect(storedNavItems($draft))->where('type', 'shop'))->toHaveCount(1)
        ->and(collect(storedNavItems($draft))->where('label', 'Contact'))->toHaveCount(1);
});

test('backfill leaves a site with no snapshot untouched', function () {
    ['draft' => $draft, 'version' => $version] = siteWithStoredNavigation('no-shop.example');
    $draftBefore = storedNavItems($draft);
    $versionBefore = storedNavItems($version);

    $this->artisan('site:shop-nav', ['--backfill' => true])->assertSuccessful();

    expect(storedNavItems($draft))->toBe($draftBefore)
        ->and(storedNavItems($version))->toBe($versionBefore);
});

test('backfill leaves published history byte-identical when the draft has unrelated merchant edits', function () {
    ['site' => $site, 'draft' => $draft, 'version' => $version] = siteWithStoredNavigation('backfill-shop.example');
    $draftComposition = $draft->composition;
    $draftComposition['footer']['show_credit'] = false;
    $draft->update(['composition' => $draftComposition]);
    $publishedBefore = $version->fresh()->getAttributes();

    \App\Models\Shop\Product::factory()->published()->for($site)->create();
    $snapshot = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'product_count' => 1, // paired with a real published product below — the counter alone is not trusted
        'json' => [],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create([
        'site_id' => $site->id,
        'snapshot_id' => $snapshot->id,
        'updated_at' => now(),
    ]);

    $this->artisan('site:shop-nav', ['--backfill' => true])
        ->expectsOutputToContain("site {$site->id}: pending merchant edits — Shop entry will appear on next publish")
        ->assertSuccessful();

    expect(collect(storedNavItems($draft))->pluck('label')->all())->toBe(['About', 'Shop', 'Contact'])
        ->and($draft->fresh()->composition['footer']['show_credit'])->toBeFalse()
        ->and((int) $draft->fresh()->admin_revision)->toBe(1)
        ->and($version->fresh()->getAttributes())->toBe($publishedBefore)
        ->and(SiteVersionCurrent::where('site_id', $site->id)->value('version_id'))->toBe($version->id);
});

test('backfill does not publish when a draft page revision differs from the published pins', function () {
    ['site' => $site, 'draft' => $draft, 'version' => $version] = siteWithStoredNavigation('draft-page-backfill-shop.example');
    $about = GeneratedPage::query()->where('site_id', $site->id)->where('page_type', 'about')->firstOrFail();
    $draftRevision = PageRevision::factory()->for($about, 'page')->create();
    $about->update(['draft_revision_id' => $draftRevision->id]);
    $publishedBefore = $version->fresh()->getAttributes();

    \App\Models\Shop\Product::factory()->published()->for($site)->create();
    $snapshot = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'product_count' => 1,
        'json' => [],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create([
        'site_id' => $site->id,
        'snapshot_id' => $snapshot->id,
        'updated_at' => now(),
    ]);

    $this->artisan('site:shop-nav', ['--backfill' => true])
        ->expectsOutputToContain("site {$site->id}: pending merchant edits — Shop entry will appear on next publish")
        ->assertSuccessful();

    expect(collect(storedNavItems($draft))->pluck('label')->all())->toBe(['About', 'Shop', 'Contact'])
        ->and($version->fresh()->getAttributes())->toBe($publishedBefore)
        ->and(SiteVersionCurrent::where('site_id', $site->id)->value('version_id'))->toBe($version->id)
        ->and($about->fresh()->draft_revision_id)->toBe($draftRevision->id);
});

test('clean backfill publishes a new system version and rollback reproduces the pre-backfill nav', function () {
    ['site' => $site, 'draft' => $draft, 'version' => $version] = siteWithStoredNavigation('clean-backfill-shop.example');
    $publishedBefore = $version->fresh()->getAttributes();

    \App\Models\Shop\Product::factory()->published()->for($site)->create();
    $snapshot = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'product_count' => 1,
        'json' => [],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create([
        'site_id' => $site->id,
        'snapshot_id' => $snapshot->id,
        'updated_at' => now(),
    ]);

    $this->artisan('site:shop-nav', ['--backfill' => true])->assertSuccessful();

    $newVersion = SiteVersion::query()->where('site_id', $site->id)->latest('version')->firstOrFail();
    expect($version->fresh()->getAttributes())->toBe($publishedBefore)
        ->and($newVersion->id)->not->toBe($version->id)
        ->and($newVersion->published_by_user_id)->toBeNull()
        ->and($newVersion->publish_note)->toBe('shop-nav-backfill')
        ->and(collect($newVersion->composition['nav']['items'])->pluck('label')->all())->toBe(['About', 'Shop', 'Contact'])
        ->and(SiteVersionCurrent::where('site_id', $site->id)->value('version_id'))->toBe($newVersion->id)
        ->and((int) $draft->fresh()->admin_revision)->toBe(1);

    app(SitePublishService::class)->rollbackToVersion($site, $version);

    $rolledBack = SiteVersion::findOrFail(
        SiteVersionCurrent::where('site_id', $site->id)->value('version_id')
    );
    expect($rolledBack->id)->toBe($version->id)
        ->and(collect($rolledBack->composition['nav']['items'])->pluck('label')->all())->toBe(['About', 'Contact']);
});

test('merchant removal survives a snapshot rebuild', function () {
    ['site' => $site, 'draft' => $draft, 'version' => $version] = siteWithStoredNavigation('removed-shop.example');
    establishShopSnapshot($site);

    foreach ([$draft, $version] as $storedComposition) {
        $composition = $storedComposition->fresh()->composition;
        $composition['nav']['items'] = collect($composition['nav']['items'])
            ->reject(fn (array $item): bool => ($item['type'] ?? null) === 'shop')
            ->values()
            ->all();
        $storedComposition->update(['composition' => $composition]);
    }

    establishShopSnapshot($site);

    expect(collect(storedNavItems($draft))->where('type', 'shop'))->toBeEmpty()
        ->and(collect(storedNavItems($version))->where('type', 'shop'))->toBeEmpty();
});

test('the backfill skips snapshot rows whose site no longer exists', function () {
    // shop_snapshot_current is derived state that is NOT cleaned up when a site is
    // deleted. Dereferencing the null relation
    // aborted the whole command partway through, leaving some sites patched and the
    // rest silently untouched, which is worse than failing outright.
    $live = Site::factory()->create();
    $snap = ShopSnapshot::create([
        'site_id' => $live->id, 'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'product_count' => 1, // paired with a real published product below — the counter alone is not trusted
        'json' => ['products' => []], 'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $live->id, 'snapshot_id' => $snap->id, 'updated_at' => now()]);

    // An orphan: the site is SOFT-deleted, so the sites row still exists (no FK cascade
    // fires) but Site's SoftDeletes scope excludes it, and the relation resolves to null.
    // That is the shape a real deployment produces.
    $doomed = Site::factory()->create();
    $doomedSnap = ShopSnapshot::create([
        'site_id' => $doomed->id, 'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'product_count' => 1, // paired with a real published product below — the counter alone is not trusted
        'json' => ['products' => []], 'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $doomed->id, 'snapshot_id' => $doomedSnap->id, 'updated_at' => now()]);
    $doomed->delete(); // soft delete

    $this->artisan('site:shop-nav --backfill')
        ->expectsOutputToContain('Skipped 1 snapshot row(s) whose site no longer exists.')
        ->assertSuccessful();
});

test('a snapshot with an empty catalogue does not advertise a shop', function () {
    // The bug this pins: a site can be given a ShopSnapshotCurrent row without ever
    // having products, so gating the Shop link on row existence alone would
    // advertise a shop on a storefront with nothing to sell.
    ['site' => $site, 'draft' => $draft, 'version' => $version] = siteWithStoredNavigation('empty-shop.example');
    $draftBefore = storedNavItems($draft);
    $versionBefore = storedNavItems($version);

    $snapshot = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'product_count' => 0,
        'json' => ['meta' => ['site_id' => $site->id], 'products' => []],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snapshot->id, 'updated_at' => now()]);

    expect($site->fresh()->hasPurchasableShop())->toBeFalse();

    $this->artisan('site:shop-nav --backfill')->assertSuccessful();

    expect(storedNavItems($draft))->toBe($draftBefore)
        ->and(storedNavItems($version))->toBe($versionBefore);
});

test('a site that gains its first product LATER still gets a Shop nav entry', function () {
    // Every site was given a shop_snapshot_current row before any had products,
    // so the previous hook (wasRecentlyCreated) was already spent and such a site could
    // never gain a Shop link.
    ['site' => $site, 'draft' => $draft] = siteWithStoredNavigation('later-shop.example');

    // First rebuild with an EMPTY catalogue: the snapshot row is created, no entry added.
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));
    $labels = fn () => array_map(fn ($i) => $i['label'] ?? null, storedNavItems($draft));
    expect($labels())->not->toContain('Shop');

    // The site now gains a published product, and rebuilds again.
    $product = \App\Models\Shop\Product::factory()->for($site)->create([
        'status' => \App\Enums\Shop\ProductStatus::Published,
    ]);
    $variant = \App\Models\Shop\ProductVariant::factory()->for($product)->create(['price_cents' => 1200]);
    \App\Models\Shop\VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 3]);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));

    expect($labels())->toContain('Shop');
});

test('a product created as DRAFT then published still gains the Shop nav entry', function () {
    // The transition was computed from product_count, which is
    // draft-inclusive, while products default to Draft. So the trigger was SPENT on the
    // draft create (where ensureShopNavEntry correctly declined) and silent on the publish
    // that actually made the site a shop — a normally-created shop never gained its entry.
    //
    // Every prior nav test created products already Published, so none of them could fail
    // on this. That is the gap: walk the real merchant flow.
    ['site' => $site, 'draft' => $draft] = siteWithStoredNavigation('draft-then-publish.example');
    $labels = fn () => array_map(fn ($i) => $i['label'] ?? null, storedNavItems($draft));

    $product = \App\Models\Shop\Product::factory()->for($site)->create([
        'status' => \App\Enums\Shop\ProductStatus::Draft,
    ]);
    $variant = \App\Models\Shop\ProductVariant::factory()->for($product)->create(['price_cents' => 1500]);
    \App\Models\Shop\VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 2]);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));

    // NB: toContain() is variadic — a second argument is another NEEDLE, not a message (T3).
    expect($labels())->not->toContain('Shop');

    // The merchant publishes it. THIS is the transition.
    $product->update(['status' => \App\Enums\Shop\ProductStatus::Published]);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));

    expect($labels())->toContain('Shop');
});

test('a merchant-removed Shop entry is not resurrected when the catalogue is emptied and relisted', function () {
    // The first-ever-purchasable guard ($hadPublishedBefore) is what makes removal stick
    // across a 0 → >0 transition; without it, archiving every product and relisting one
    // looks like a first transition and re-adds the entry. Pinned after verification
    // that deleting the guard left every test green.
    ['site' => $site, 'draft' => $draft, 'version' => $version] = siteWithStoredNavigation('relisted-shop.example');
    establishShopSnapshot($site);

    foreach ([$draft, $version] as $storedComposition) {
        $composition = $storedComposition->fresh()->composition;
        $composition['nav']['items'] = collect($composition['nav']['items'])
            ->reject(fn (array $item): bool => ($item['type'] ?? null) === 'shop')
            ->values()
            ->all();
        $storedComposition->update(['composition' => $composition]);
    }

    \App\Models\Shop\Product::query()->where('site_id', $site->id)
        ->update(['status' => \App\Enums\Shop\ProductStatus::Archived]);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));
    expect($site->fresh()->hasPurchasableShop())->toBeFalse();

    establishShopSnapshot($site); // relist: 0 → 1 purchasable again

    expect($site->fresh()->hasPurchasableShop())->toBeTrue()
        ->and(collect(storedNavItems($draft))->where('type', 'shop'))->toBeEmpty()
        ->and(collect(storedNavItems($version))->where('type', 'shop'))->toBeEmpty();
});

test('pruning snapshots does not resurrect a Shop entry the merchant removed', function () {
    // `$hadPublishedBefore` is derived from
    // `shop_snapshots` rows that `shop:prune-snapshots` garbage-collects. Sequence:
    // publish (Shop entry appears) → merchant removes it → archive the catalogue →
    // ≥ KEEP_SUCCESS empty rebuilds → prune → relist. The published-product snapshot
    // is gone, the 0→>0 transition looks like a first, and the deleted entry comes back.
    Queue::fake();
    ['site' => $site, 'draft' => $draft] = siteWithStoredNavigation('prune-resurrect-shop.example');
    $labels = fn () => array_map(fn ($i) => $i['label'] ?? null, storedNavItems($draft));

    establishShopSnapshot($site);
    expect($labels())->toContain('Shop');

    removeShopNavItems($draft);
    expect($labels())->not->toContain('Shop');

    Product::query()->where('site_id', $site->id)->update(['status' => ProductStatus::Archived]);
    foreach (range(1, PruneOldSnapshots::KEEP_SUCCESS) as $_) {
        rebuildShopSnapshot($site);
    }

    $this->artisan('shop:prune-snapshots')->assertSuccessful();

    expect(
        ShopSnapshot::query()
            ->where('site_id', $site->id)
            ->get()
            ->contains(fn (ShopSnapshot $snapshot): bool => snapshotJsonHasPublishedProduct($snapshot->json))
    )->toBeFalse();

    Product::query()->where('site_id', $site->id)->update(['status' => ProductStatus::Published]);
    rebuildShopSnapshot($site);

    expect($labels())->not->toContain('Shop');
});

test('merchant removal survives fifty-one empty snapshots without prune', function () {
    // Same resurrection, raw horizon: RebuildShopSnapshot only scans 50 earlier
    // snapshots for a published product. 51 empty ones between archive and relist
    // push the original published snapshot out of that window with no prune involved.
    Queue::fake();
    ['site' => $site, 'draft' => $draft] = siteWithStoredNavigation('horizon-resurrect-shop.example');
    $labels = fn () => array_map(fn ($i) => $i['label'] ?? null, storedNavItems($draft));

    establishShopSnapshot($site);
    expect($labels())->toContain('Shop');

    removeShopNavItems($draft);
    expect($labels())->not->toContain('Shop');

    Product::query()->where('site_id', $site->id)->update(['status' => ProductStatus::Archived]);
    foreach (range(1, 51) as $_) {
        rebuildShopSnapshot($site);
    }

    Product::query()->where('site_id', $site->id)->update(['status' => ProductStatus::Published]);
    rebuildShopSnapshot($site);

    expect($labels())->not->toContain('Shop');
});
