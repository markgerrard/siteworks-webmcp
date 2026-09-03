<?php

use App\Enums\MutationSource;
use App\Enums\PageKind;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\SiteDraft;
use App\Services\Site\CompositionDefaults;
use App\Services\Site\CompositionService;

it('excludes nested pages from default nav composition', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
        'nav_label' => 'Home',
        'sort_order' => 0,
    ]);
    $projects = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'projects',
        'kind' => PageKind::Core,
        'nav_label' => 'Our Work',
        'sort_order' => 1,
    ]);
    $nested = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'projects/loft-conversion-wigan',
        'parent_id' => $projects->id,
        'kind' => PageKind::ProjectDetail,
        'nav_label' => 'Loft Conversion Wigan',
        'sort_order' => 2,
        'status' => PageStatus::Published,
    ]);

    $composition = app(CompositionDefaults::class)->forSite($site);
    $navIds = collect($composition['nav']['items'])->pluck('page_id')->all();

    expect($composition['homepage_page_id'])->toBe($home->id)
        ->and($navIds)->toContain($projects->id)
        ->and($navIds)->not->toContain($nested->id)
        ->and(array_column($composition['nav']['items'], 'label'))->not->toContain('Loft Conversion Wigan');
});

it('refuses to append a nested page into top-level nav', function () {
    $site = Site::factory()->create();
    $cs = app(CompositionService::class);

    GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
        'status' => PageStatus::Published,
    ]);
    $projects = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'projects',
        'kind' => PageKind::Core,
        'status' => PageStatus::Published,
    ]);
    $nested = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'projects/loft-conversion-wigan',
        'parent_id' => $projects->id,
        'kind' => PageKind::ProjectDetail,
        'status' => PageStatus::Published,
        'nav_label' => 'Loft Conversion Wigan',
    ]);

    $draft = $cs->getOrCreateDraft($site);
    $composition = $draft->composition;
    $composition['nav']['items'] = [];
    $draft->composition = $composition;
    $draft->save();

    $result = $cs->appendNavPageAtomic($site, $nested->id, 'Loft Conversion Wigan', MutationSource::Pipeline);

    $fresh = SiteDraft::where('site_id', $site->id)->first();
    $navIds = collect($fresh->composition['nav']['items'] ?? [])->pluck('page_id')->all();

    expect($result)->toBeFalse()
        ->and($navIds)->not->toContain($nested->id);
});
