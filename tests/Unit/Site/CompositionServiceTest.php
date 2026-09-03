<?php

use App\Enums\MutationSource;
use App\Models\Site;
use App\Models\Site\SiteDraft;
use App\Services\Site\CompositionService;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => $this->svc = app(CompositionService::class));

test('getOrCreateDraft creates a draft from defaults if none exists', function () {
    $site = Site::factory()->create();
    \App\Models\GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);

    $draft = $this->svc->getOrCreateDraft($site);

    expect($draft)->toBeInstanceOf(SiteDraft::class);
    expect($draft->composition['homepage_page_id'])->not->toBeNull();
    expect(SiteDraft::where('site_id', $site->id)->count())->toBe(1);
});

test('getOrCreateDraft returns existing draft on subsequent calls', function () {
    $site = Site::factory()->create();
    $first = $this->svc->getOrCreateDraft($site);
    $second = $this->svc->getOrCreateDraft($site);

    expect($first->id)->toBe($second->id);
});

test('updateNav replaces nav items array', function () {
    $site = Site::factory()->create();
    $draft = $this->svc->getOrCreateDraft($site);

    $this->svc->updateNav($draft, [
        ['type' => 'page', 'page_id' => 5, 'label' => 'About'],
        ['type' => 'shop', 'label' => 'Shop'],
    ], MutationSource::Admin);

    $draft->refresh();
    expect($draft->composition['nav']['items'])->toHaveCount(2);
    expect($draft->composition['nav']['items'][1]['type'])->toBe('shop');
});

test('updateTheme allows preset key + optional overrides', function () {
    $site = Site::factory()->create();
    $draft = $this->svc->getOrCreateDraft($site);

    $this->svc->updateTheme($draft, 'professional-clean', '#112233', null, MutationSource::Admin);

    $draft->refresh();
    expect($draft->composition['theme']['key'])->toBe('professional-clean');
    expect($draft->composition['theme']['primary_override'])->toBe('#112233');
});

test('setHomepage updates homepage_page_id', function () {
    $site = Site::factory()->create();
    $page = \App\Models\GeneratedPage::factory()->for($site)->create();
    $draft = $this->svc->getOrCreateDraft($site);

    $this->svc->setHomepage($draft, $page->id, MutationSource::Admin);

    expect($draft->fresh()->composition['homepage_page_id'])->toBe($page->id);
});

test('hasPendingComposition returns true when draft != current published composition', function () {
    $site = Site::factory()->create();
    $draft = $this->svc->getOrCreateDraft($site);

    \App\Models\Site\SiteVersion::create([
        'site_id' => $site->id, 'version' => 1,
        'composition' => $draft->composition,
        'page_revisions' => [], 'published_at' => now(),
    ])->id;

    \App\Models\Site\SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => \App\Models\Site\SiteVersion::where('site_id', $site->id)->first()->id,
        'updated_at' => now(),
    ]);

    expect($this->svc->hasPendingComposition($site))->toBeFalse();

    $this->svc->updateNav($draft, [['type' => 'shop', 'label' => 'Shop']], MutationSource::Admin);

    expect($this->svc->hasPendingComposition($site))->toBeTrue();
});

test('discardComposition resets draft to current published composition', function () {
    $site = Site::factory()->create();
    $draft = $this->svc->getOrCreateDraft($site);
    $publishedComposition = $draft->composition;

    \App\Models\Site\SiteVersion::create([
        'site_id' => $site->id, 'version' => 1,
        'composition' => $publishedComposition,
        'page_revisions' => [], 'published_at' => now(),
    ]);
    \App\Models\Site\SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => \App\Models\Site\SiteVersion::first()->id,
        'updated_at' => now(),
    ]);

    $this->svc->updateNav($draft, [['type' => 'page', 'page_id' => 99, 'label' => 'Garbage']], MutationSource::Admin);

    $this->svc->discardComposition($site);

    expect($draft->fresh()->composition)->toEqual($publishedComposition);
});
