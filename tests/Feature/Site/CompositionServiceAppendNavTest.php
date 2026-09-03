<?php

use App\Enums\MutationSource;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\SiteDraft;
use App\Services\Site\CompositionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeNavPage(int $siteId, string $type = 'svc', PageStatus $status = PageStatus::Published): GeneratedPage
{
    return GeneratedPage::create([
        'site_id' => $siteId,
        'page_type' => $type,
        'content_data' => [],
        'sort_order' => 0,
        'version' => 1,
        'status' => $status,
    ]);
}

test('appendNavPageAtomic appends a new page nav item', function () {
    // Always seed a 'home' page first so CompositionDefaults picks it
    // as homepage — otherwise the test's target page becomes the
    // auto-elected homepage and appendNav rightly refuses.
    $site = Site::factory()->create();
    $cs = app(CompositionService::class);

    makeNavPage($site->id, 'home');
    $page = makeNavPage($site->id, 'about');
    // Clear the auto-seeded nav so the append is the only insert
    $draft = $cs->getOrCreateDraft($site);
    $c = $draft->composition;
    $c['nav']['items'] = [];
    $draft->composition = $c;
    $draft->save();

    $result = $cs->appendNavPageAtomic($site, $page->id, 'About Us', MutationSource::Pipeline);

    expect($result)->toBeTrue();

    $draft = SiteDraft::where('site_id', $site->id)->first();
    $items = $draft->composition['nav']['items'] ?? [];
    expect($items)->toHaveCount(1);
    expect($items[0]['page_id'])->toBe($page->id);
    expect($items[0]['label'])->toBe('About Us');
    expect($items[0]['type'])->toBe('page');
});

test('appendNavPageAtomic skips duplicate page_id', function () {
    $site = Site::factory()->create();
    $cs = app(CompositionService::class);
    makeNavPage($site->id, 'home');
    $page = makeNavPage($site->id, 'about');
    $draft = $cs->getOrCreateDraft($site);
    $c = $draft->composition;
    $c['nav']['items'] = [];
    $draft->composition = $c;
    $draft->save();

    $cs->appendNavPageAtomic($site, $page->id, 'First', MutationSource::Pipeline);
    $result = $cs->appendNavPageAtomic($site, $page->id, 'Second', MutationSource::Pipeline);

    expect($result)->toBeFalse();

    $draft = SiteDraft::where('site_id', $site->id)->first();
    expect($draft->composition['nav']['items'])->toHaveCount(1);
    expect($draft->composition['nav']['items'][0]['label'])->toBe('First');
});

test('appendNavPageAtomic refuses to append the homepage as a nav item', function () {
    $site = Site::factory()->create();
    $cs = app(CompositionService::class);
    $home = makeNavPage($site->id, 'home');
    $draft = $cs->getOrCreateDraft($site);
    $composition = $draft->composition;
    $composition['homepage_page_id'] = $home->id;
    $draft->update(['composition' => $composition]);

    $result = $cs->appendNavPageAtomic($site, $home->id, 'Home', MutationSource::Pipeline);

    expect($result)->toBeFalse();
});

test('appendNavPageAtomic creates a draft if none exists (no prior getOrCreateDraft call)', function () {
    // Focus of this test: the "no draft yet" code path creates one.
    // Whether the append itself succeeds or de-dupes against defaults is
    // incidental — the observable behaviour is that after the call, a
    // draft exists with the page represented in nav.
    $site = Site::factory()->create();
    makeNavPage($site->id, 'home');
    $page = makeNavPage($site->id, 'new-page');
    expect(SiteDraft::where('site_id', $site->id)->count())->toBe(0);

    app(CompositionService::class)->appendNavPageAtomic($site, $page->id, 'New Page', MutationSource::Pipeline);

    $draft = SiteDraft::where('site_id', $site->id)->first();
    expect($draft)->not->toBeNull();
    $ids = collect($draft->composition['nav']['items'])->pluck('page_id')->all();
    expect($ids)->toContain($page->id);
});

test('sequential appends each add a distinct nav entry', function () {
    // Pest's SQLite in-memory DB doesn't simulate true concurrency, but this
    // test confirms the service accumulates rather than overwriting — the
    // bug v1.2.3 fixed. Under concurrency, the SELECT FOR UPDATE lock in
    // appendNavPageAtomic serialises conflicting transactions; this test
    // just verifies the non-concurrent correctness of that logic.
    $site = Site::factory()->create();
    $cs = app(CompositionService::class);
    makeNavPage($site->id, 'home');
    $pages = [];
    for ($i = 1; $i <= 8; $i++) {
        $pages[] = makeNavPage($site->id, "page-{$i}");
    }

    $draft = $cs->getOrCreateDraft($site);
    $c = $draft->composition;
    $c['nav']['items'] = [];
    $draft->composition = $c;
    $draft->save();

    foreach ($pages as $i => $p) {
        $cs->appendNavPageAtomic($site, $p->id, 'Page '.($i + 1), MutationSource::Pipeline);
    }

    $draft = SiteDraft::where('site_id', $site->id)->first();
    $items = $draft->composition['nav']['items'];
    expect($items)->toHaveCount(8);
    expect(collect($items)->pluck('page_id')->all())
        ->toBe(collect($pages)->pluck('id')->all());
});
