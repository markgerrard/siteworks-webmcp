<?php

use App\Enums\PageStatus;
use App\Enums\PreviewLayout;
use App\Enums\ProjectItemSource;
use App\Enums\ProjectItemStatus;
use App\Exceptions\Site\FirstPublishRequiredException;
use App\Exceptions\Site\PageStateException;
use App\Services\Site\PageService;
use App\Models\GeneratedPage;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\CompositionDelta;
use App\Services\Site\CompositionService;
use App\Services\Site\SitePublishService;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake([\App\Jobs\GenerateBrandImagesJob::class]);
    $this->svc = app(SitePublishService::class);
});

/**
 * Published site (v1) plus one extra Draft page with a draft revision.
 *
 * @return array{0: Site, 1: GeneratedPage, 2: PageRevision, 3: GeneratedPage, 4: PageRevision, 5: GeneratedPage, 6: PageRevision}
 */
function setupSinglePagePublishFixture(array $siteAttrs = []): array
{
    $site = Site::factory()->create(array_merge([
        'business_name' => 'Acme',
        'theme' => 'trades-bold',
        'preview_layout' => PreviewLayout::MultiPage,
    ], $siteAttrs));

    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'status' => PageStatus::Published,
        'nav_label' => 'Home',
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Welcome to Acme'],
        ]],
    ]);
    $home->update(['draft_revision_id' => $homeRev->id]);

    app(SitePublishService::class)->publishSite($site);

    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'planning-permission-guide',
        'status' => PageStatus::Draft,
        'nav_label' => 'Planning Guide',
    ]);
    $pageRev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Planning permission'],
        ]],
    ]);
    $page->update(['draft_revision_id' => $pageRev->id]);

    $sibling = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'cost-guide-loft',
        'status' => PageStatus::Draft,
        'nav_label' => 'Loft Costs',
    ]);
    $siblingRev = PageRevision::factory()->for($sibling, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Loft conversion costs'],
        ]],
    ]);
    $sibling->update(['draft_revision_id' => $siblingRev->id]);

    return [$site->fresh(), $home->fresh(), $homeRev->fresh(), $page->fresh(), $pageRev->fresh(), $sibling->fresh(), $siblingRev->fresh()];
}

test('publishSinglePage adds the page pin to a new version and leaves sibling drafts untouched', function () {
    [$site, $home, $homeRev, $page, $pageRev, $sibling, $siblingRev] = setupSinglePagePublishFixture();

    $version = $this->svc->publishSinglePage($site, $page);

    expect($version->version)->toBe(2);

    $pins = collect($version->page_revisions);
    expect($pins)->toHaveCount(2);
    expect($pins->pluck('page_id')->map(fn ($id) => (int) $id)->all())
        ->toEqualCanonicalizing([$home->id, $page->id]);
    expect((int) $pins->firstWhere('page_id', $home->id)['revision_id'])->toBe($homeRev->id);
    expect((int) $pins->firstWhere('page_id', $page->id)['revision_id'])->toBe($pageRev->id);

    $page->refresh();
    expect($page->status)->toBe(PageStatus::Published);
    expect($page->published_revision_id)->toBe($pageRev->id);
    expect($page->draft_revision_id)->toBeNull();

    $sibling->refresh();
    expect($sibling->status)->toBe(PageStatus::Draft);
    expect($sibling->draft_revision_id)->toBe($siblingRev->id);
    expect($sibling->published_revision_id)->toBeNull();

    $home->refresh();
    expect($home->published_revision_id)->toBe($homeRev->id);
    expect($home->draft_revision_id)->toBeNull();

    expect(SiteVersionCurrent::where('site_id', $site->id)->value('version_id'))->toBe($version->id);
});

test('publishSinglePage applies the composition delta to the version and the draft', function () {
    [$site, , , $page] = setupSinglePagePublishFixture();

    $delta = new CompositionDelta(
        footerColumnEntries: [['column' => 'Guides & Advice', 'page_id' => $page->id]],
        navEntries: [['page_id' => $page->id, 'label' => 'Planning Guide']],
    );

    expect(app(CompositionService::class)->hasPendingComposition($site))->toBeFalse();

    $version = $this->svc->publishSinglePage($site, $page, $delta);

    $draft = SiteDraft::where('site_id', $site->id)->first();
    expect($draft)->not->toBeNull();

    // Persisted shapes must match: hasPendingComposition uses strict !==
    // on the JSON-round-tripped arrays. Compare draft vs version after
    // both have gone through the same store/load path.
    expect($draft->composition)->toBe($version->fresh()->composition);
    expect(app(CompositionService::class)->hasPendingComposition($site))->toBeFalse();

    $footerItems = collect($version->composition['footer']['columns'] ?? [])
        ->firstWhere('title', 'Guides & Advice')['items'] ?? [];
    expect(collect($footerItems)->pluck('page_id')->map(fn ($id) => (int) $id)->all())
        ->toContain($page->id);

    $navPageIds = collect($version->composition['nav']['items'] ?? [])
        ->pluck('page_id')
        ->map(fn ($id) => (int) $id)
        ->all();
    expect($navPageIds)->toContain($page->id);
});

test('publishSinglePage leaves an unrelated draft composition edit pending and out of the new version', function () {
    [$site, , , $page] = setupSinglePagePublishFixture();

    $draft = SiteDraft::where('site_id', $site->id)->first();
    $pending = $draft->composition;
    $pending['nav']['items'][] = ['type' => 'shop', 'label' => 'Shop'];
    $draft->composition = $pending;
    $draft->updated_at = now();
    $draft->save();

    expect(app(CompositionService::class)->hasPendingComposition($site))->toBeTrue();

    $delta = new CompositionDelta(
        footerColumnEntries: [['column' => 'Guides & Advice', 'page_id' => $page->id]],
    );

    $version = $this->svc->publishSinglePage($site, $page, $delta);

    $versionFooterItems = collect($version->composition['footer']['columns'] ?? [])
        ->firstWhere('title', 'Guides & Advice')['items'] ?? [];
    expect(collect($versionFooterItems)->pluck('page_id')->map(fn ($id) => (int) $id)->all())
        ->toContain($page->id);

    $shopLabels = collect($version->composition['nav']['items'] ?? [])
        ->pluck('label')
        ->all();
    expect($shopLabels)->not->toContain('Shop');

    $draft->refresh();
    $draftShopLabels = collect($draft->composition['nav']['items'] ?? [])
        ->pluck('label')
        ->all();
    expect($draftShopLabels)->toContain('Shop');

    $footerItems = collect($draft->composition['footer']['columns'] ?? [])
        ->firstWhere('title', 'Guides & Advice')['items'] ?? [];
    expect(collect($footerItems)->pluck('page_id')->map(fn ($id) => (int) $id)->all())
        ->toContain($page->id);

    expect(app(CompositionService::class)->hasPendingComposition($site))->toBeTrue();
});

test('publishSinglePage refuses a never-published site and writes nothing', function () {
    $site = Site::factory()->create();
    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'planning-permission-guide',
        'status' => PageStatus::Draft,
    ]);
    $rev = PageRevision::factory()->for($page, 'page')->create();
    $page->update(['draft_revision_id' => $rev->id]);

    expect(fn () => $this->svc->publishSinglePage($site, $page))
        ->toThrow(FirstPublishRequiredException::class);

    expect(SiteVersion::where('site_id', $site->id)->count())->toBe(0);
    expect(SiteVersionCurrent::where('site_id', $site->id)->exists())->toBeFalse();
    expect(SiteDraft::where('site_id', $site->id)->exists())->toBeFalse();

    $page->refresh();
    expect($page->status)->toBe(PageStatus::Draft);
    expect($page->published_revision_id)->toBeNull();
    expect($page->draft_revision_id)->toBe($rev->id);
});

test('publishSinglePage promotes Draft ProjectItems referenced by the new pin and writes published_snapshot', function () {
    [$site, , , $page, $pageRev] = setupSinglePagePublishFixture();

    $item = ProjectItem::factory()->create([
        'site_id' => $site->id,
        'page_id' => $page->id,
        'status' => ProjectItemStatus::Draft,
        'source' => ProjectItemSource::AiGenerated,
        'title' => 'Loft conversion, Wigan',
    ]);
    $orphan = ProjectItem::factory()->create([
        'site_id' => $site->id,
        'page_id' => $page->id,
        'status' => ProjectItemStatus::Draft,
        'source' => ProjectItemSource::AiGenerated,
        'title' => 'Orphan tile',
    ]);

    $pageRev->update([
        'content_data' => [
            'sections' => [
                ['type' => 'hero', 'title' => 'Case study'],
                ['type' => 'case_study_highlights', 'item_ids' => [$item->id]],
            ],
        ],
    ]);

    $this->svc->publishSinglePage($site, $page);

    $item->refresh();
    expect($item->status)->toBe(ProjectItemStatus::Published);
    expect($item->published_snapshot)->toBe($item->buildPublishSnapshot());

    $pageRev->refresh();
    $highlights = collect($pageRev->content_data['sections'])
        ->firstWhere('type', 'case_study_highlights');
    expect($highlights['published_content_hashes'] ?? [])->toHaveKey($item->id);
    expect($highlights['published_content_hashes'][$item->id])->toBe($item->content_hash);
    expect($highlights['published_media_hashes'] ?? [])->toHaveKey($item->id);
    expect($highlights['published_media_hashes'][$item->id])->toBe($item->media_hash);

    $orphan->refresh();
    expect($orphan->status)->toBe(ProjectItemStatus::Draft);
    expect($orphan->published_snapshot)->toBeNull();
});

test('sequential publishSinglePage calls compose on the current version with cumulative pins', function () {
    [$site, $home, $homeRev, $pageA, $pageARev, $pageB, $pageBRev] = setupSinglePagePublishFixture();

    $first = $this->svc->publishSinglePage($site, $pageA);
    $second = $this->svc->publishSinglePage($site, $pageB);

    expect($first->version)->toBe(2);
    expect($second->version)->toBe(3);

    $firstPins = collect($first->page_revisions)->pluck('page_id')->map(fn ($id) => (int) $id)->all();
    $secondPins = collect($second->page_revisions)->pluck('page_id')->map(fn ($id) => (int) $id)->all();

    expect($firstPins)->toEqualCanonicalizing([$home->id, $pageA->id]);
    expect($secondPins)->toEqualCanonicalizing([$home->id, $pageA->id, $pageB->id]);

    expect((int) collect($first->page_revisions)->firstWhere('page_id', $pageA->id)['revision_id'])->toBe($pageARev->id);
    expect((int) collect($second->page_revisions)->firstWhere('page_id', $pageA->id)['revision_id'])->toBe($pageARev->id);
    expect((int) collect($second->page_revisions)->firstWhere('page_id', $pageB->id)['revision_id'])->toBe($pageBRev->id);
    expect((int) collect($second->page_revisions)->firstWhere('page_id', $home->id)['revision_id'])->toBe($homeRev->id);

    expect(collect($firstPins))->not->toContain($pageB->id);
});

test('removePageFromVersion drops the pin, drafts the page, strips the delta, and 404s the public slug', function () {
    [$site, $home, $homeRev, $page, $pageRev] = setupSinglePagePublishFixture([
        'custom_domain' => 'guides.example',
        'custom_domain_status' => 'active',
        'preview_layout' => PreviewLayout::MultiPage,
    ]);

    config(['site.use_versioned_renderer' => true]);

    $delta = new CompositionDelta(
        footerColumnEntries: [['column' => 'Guides & Advice', 'page_id' => $page->id]],
    );

    $published = $this->svc->publishSinglePage($site, $page, $delta);
    expect($published->version)->toBe(2);

    $this->get('http://guides.example/planning-permission-guide')->assertOk();

    $removed = $this->svc->removePageFromVersion($site, $page, $delta);

    expect($removed->version)->toBe(3);
    expect(collect($removed->page_revisions)->pluck('page_id')->map(fn ($id) => (int) $id)->all())
        ->toEqualCanonicalizing([$home->id]);
    expect((int) collect($removed->page_revisions)->firstWhere('page_id', $home->id)['revision_id'])->toBe($homeRev->id);

    $page->refresh();
    expect($page->status)->toBe(PageStatus::Draft);
    expect($page->published_revision_id)->toBe($pageRev->id);

    $footerItems = collect($removed->composition['footer']['columns'] ?? [])
        ->firstWhere('title', 'Guides & Advice')['items'] ?? [];
    expect(collect($footerItems)->pluck('page_id')->map(fn ($id) => (int) $id)->all())
        ->not->toContain($page->id);

    $draft = SiteDraft::where('site_id', $site->id)->first();
    $draftFooterItems = collect($draft->composition['footer']['columns'] ?? [])
        ->firstWhere('title', 'Guides & Advice')['items'] ?? [];
    expect(collect($draftFooterItems)->pluck('page_id')->map(fn ($id) => (int) $id)->all())
        ->not->toContain($page->id);

    $this->get('http://guides.example/planning-permission-guide')->assertNotFound();
});

test('removePageFromVersion prunes the page nav entry even without a caller delta', function () {
    [$site, , , $page] = setupSinglePagePublishFixture();

    $delta = new CompositionDelta(
        navEntries: [['page_id' => $page->id, 'label' => 'Planning Guide']],
    );

    $this->svc->publishSinglePage($site, $page, $delta);

    $draft = SiteDraft::where('site_id', $site->id)->first();
    $navPageIds = collect($draft->composition['nav']['items'] ?? [])
        ->pluck('page_id')
        ->map(fn ($id) => (int) $id)
        ->all();
    expect($navPageIds)->toContain($page->id);

    $removed = $this->svc->removePageFromVersion($site, $page);

    $removedNavPageIds = collect($removed->composition['nav']['items'] ?? [])
        ->pluck('page_id')
        ->map(fn ($id) => (int) $id)
        ->all();
    expect($removedNavPageIds)->not->toContain($page->id);

    $draft->refresh();
    $draftNavPageIds = collect($draft->composition['nav']['items'] ?? [])
        ->pluck('page_id')
        ->map(fn ($id) => (int) $id)
        ->all();
    expect($draftNavPageIds)->not->toContain($page->id);
});

test('removePageFromVersion keeps draft nav entries for unpublished sibling pages', function () {
    [$site, , , $pageC, , $pageB] = setupSinglePagePublishFixture();

    $delta = new CompositionDelta(
        navEntries: [['page_id' => $pageC->id, 'label' => 'Planning Guide']],
    );

    $this->svc->publishSinglePage($site, $pageC, $delta);

    $draft = SiteDraft::where('site_id', $site->id)->first();
    $pending = $draft->composition;
    $pending['nav']['items'][] = [
        'type' => 'page',
        'page_id' => $pageB->id,
        'label' => 'Loft Costs',
    ];
    $draft->composition = $pending;
    $draft->updated_at = now();
    $draft->save();

    $removed = $this->svc->removePageFromVersion($site, $pageC);

    $removedNavPageIds = collect($removed->composition['nav']['items'] ?? [])
        ->pluck('page_id')
        ->map(fn ($id) => (int) $id)
        ->all();
    expect($removedNavPageIds)->not->toContain($pageC->id)
        ->and($removedNavPageIds)->not->toContain($pageB->id);

    $draft->refresh();
    $draftNavPageIds = collect($draft->composition['nav']['items'] ?? [])
        ->pluck('page_id')
        ->map(fn ($id) => (int) $id)
        ->all();
    expect($draftNavPageIds)->not->toContain($pageC->id)
        ->and($draftNavPageIds)->toContain($pageB->id);
});

test('removePageFromVersion refuses to unpin the composition homepage', function () {
    [$site, $home, $homeRev, $page] = setupSinglePagePublishFixture();

    $this->svc->publishSinglePage($site, $page);

    expect(fn () => $this->svc->removePageFromVersion($site, $home))
        ->toThrow(PageStateException::class);

    expect(SiteVersion::where('site_id', $site->id)->count())->toBe(2);

    $home->refresh();
    expect($home->status)->toBe(PageStatus::Published);

    $current = SiteVersion::find(SiteVersionCurrent::where('site_id', $site->id)->value('version_id'));
    expect(collect($current->page_revisions)->pluck('page_id')->map(fn ($id) => (int) $id)->all())
        ->toEqualCanonicalizing([$home->id, $page->id]);
    expect((int) collect($current->page_revisions)->firstWhere('page_id', $home->id)['revision_id'])->toBe($homeRev->id);
});

test('removePageFromVersion refuses to unpin the last remaining pin', function () {
    [$site, $home, $homeRev, $page, $pageRev] = setupSinglePagePublishFixture();

    $published = $this->svc->publishSinglePage($site, $page);

    $composition = $published->composition;
    $composition['homepage_page_id'] = $home->id;
    $published->update([
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $pageRev->id]],
        'composition' => $composition,
    ]);

    expect(fn () => $this->svc->removePageFromVersion($site, $page))
        ->toThrow(PageStateException::class);

    expect(SiteVersion::where('site_id', $site->id)->count())->toBe(2);

    $page->refresh();
    expect($page->status)->toBe(PageStatus::Published);

    $current = SiteVersion::find(SiteVersionCurrent::where('site_id', $site->id)->value('version_id'));
    expect(collect($current->page_revisions)->pluck('page_id')->map(fn ($id) => (int) $id)->all())
        ->toBe([$page->id]);
    expect((int) collect($current->page_revisions)->firstWhere('page_id', $page->id)['revision_id'])->toBe($pageRev->id);
});

test('publishSinglePage promotes only same-site ProjectItems referenced by the pin', function () {
    [$site, , , $page, $pageRev] = setupSinglePagePublishFixture();

    $otherSite = Site::factory()->create();
    $foreign = ProjectItem::factory()->create([
        'site_id' => $otherSite->id,
        'status' => ProjectItemStatus::Draft,
        'source' => ProjectItemSource::AiGenerated,
        'title' => 'Foreign tile',
    ]);

    $pageRev->update([
        'content_data' => [
            'sections' => [
                ['type' => 'hero', 'title' => 'Case study'],
                ['type' => 'case_study_highlights', 'item_ids' => [$foreign->id]],
            ],
        ],
    ]);

    $this->svc->publishSinglePage($site, $page);

    $foreign->refresh();
    expect($foreign->status)->toBe(ProjectItemStatus::Draft);
    expect($foreign->published_snapshot)->toBeNull();
});

test('publishSinglePage clears archived_at on the published page', function () {
    [$site, , , $page] = setupSinglePagePublishFixture();

    $this->svc->publishSinglePage($site, $page);
    app(PageService::class)->archivePage($page->refresh());
    expect($page->fresh()->archived_at)->not->toBeNull();

    $this->svc->publishSinglePage($site, $page->fresh());

    $page->refresh();
    expect($page->status)->toBe(PageStatus::Published);
    expect($page->archived_at)->toBeNull();
});

test('CompositionDelta apply is idempotent under strict equality', function () {
    $composition = [
        'nav' => ['items' => [
            ['type' => 'page', 'page_id' => 1, 'label' => 'About'],
        ]],
        'footer' => ['columns' => [], 'show_credit' => true],
        'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
        'homepage_page_id' => 1,
    ];

    $delta = new CompositionDelta(
        footerColumnEntries: [
            ['column' => 'Guides & Advice', 'page_id' => 42],
            ['column' => 'Guides & Advice', 'page_id' => 42],
        ],
        navEntries: [
            ['page_id' => 42, 'label' => 'Guide'],
            ['page_id' => 42, 'label' => 'Guide'],
        ],
    );

    $once = $delta->apply($composition);
    $twice = $delta->apply($once);

    expect($twice === $once)->toBeTrue();
    expect($delta->apply($delta->apply($composition)) === $delta->apply($composition))->toBeTrue();

    $guideItems = collect($once['footer']['columns'])->firstWhere('title', 'Guides & Advice')['items'];
    expect($guideItems)->toHaveCount(1);
    expect($guideItems[0]['page_id'])->toBe(42);

    $navMatches = collect($once['nav']['items'])->where('page_id', 42)->values();
    expect($navMatches)->toHaveCount(1);
    expect($navMatches[0]['label'])->toBe('Guide');
});
