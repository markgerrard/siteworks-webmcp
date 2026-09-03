<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\GeneratedPage;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use App\Services\Site\CompositionDefaults;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

test('builds composition from existing pages — home as homepage, others in nav', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'nav_label' => 'Home', 'sort_order' => 0]);
    $about = GeneratedPage::factory()->for($site)->create(['page_type' => 'about', 'nav_label' => 'About', 'sort_order' => 1]);
    $contact = GeneratedPage::factory()->for($site)->create(['page_type' => 'contact', 'nav_label' => 'Contact', 'sort_order' => 99]);

    $composition = app(CompositionDefaults::class)->forSite($site);

    expect($composition['homepage_page_id'])->toBe($home->id);
    expect($composition['theme'])
        ->not->toHaveKey('key')
        ->toMatchArray(['primary_override' => null, 'accent_override' => null]);

    $navLabels = array_column($composition['nav']['items'], 'label');
    expect($navLabels)->toContain('About');
    expect($navLabels)->toContain('Contact');
});

test('archived pages excluded from default composition', function () {
    $site = Site::factory()->create();
    GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    GeneratedPage::factory()->for($site)->create(['page_type' => 'old', 'archived_at' => now()]);

    $composition = app(CompositionDefaults::class)->forSite($site);

    expect(count($composition['nav']['items']))->toBeLessThan(3);
});

test('falls back to first page if no home page_type exists', function () {
    $site = Site::factory()->create();
    $about = GeneratedPage::factory()->for($site)->create(['page_type' => 'about', 'sort_order' => 0]);

    $composition = app(CompositionDefaults::class)->forSite($site);
    expect($composition['homepage_page_id'])->toBe($about->id);
});

test('shop nav item is appended before contact when the site has something to sell', function () {
    // Renamed and corrected: this test previously blessed an EMPTY snapshot as sufficient
    // for Shop navigation. Snapshot row existence alone is not a meaningful predicate for
    // "has a shop" — a site can have a snapshot row with no products at all.
    $site = Site::factory()->create();
    \App\Models\Shop\Product::factory()->published()->for($site)->create();
    GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'sort_order' => 0]);
    GeneratedPage::factory()->for($site)->create(['page_type' => 'about', 'nav_label' => 'About', 'sort_order' => 1]);
    GeneratedPage::factory()->for($site)->create(['page_type' => 'contact', 'nav_label' => 'Contact', 'sort_order' => 99]);

    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => ['meta' => [], 'categories' => [], 'products' => [], 'featured_slugs' => []],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snap->id, 'updated_at' => now()]);

    $composition = app(CompositionDefaults::class)->forSite($site);

    $types = array_column($composition['nav']['items'], 'type');
    $labels = array_column($composition['nav']['items'], 'label');

    expect($types)->toContain('shop');
    expect($labels)->toContain('Shop');

    // Shop must appear before Contact
    $shopIndex = array_search('Shop', $labels);
    $contactIndex = array_search('Contact', $labels);
    expect($shopIndex)->toBeLessThan($contactIndex);
});

test('no shop nav item when site has no shop snapshot', function () {
    $site = Site::factory()->create();
    GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'sort_order' => 0]);
    GeneratedPage::factory()->for($site)->create(['page_type' => 'contact', 'nav_label' => 'Contact', 'sort_order' => 99]);

    $composition = app(CompositionDefaults::class)->forSite($site);

    $types = array_column($composition['nav']['items'], 'type');
    expect($types)->not->toContain('shop');
});
