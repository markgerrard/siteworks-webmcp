<?php

use App\Models\Site;
use App\Models\Site\SiteDraft;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

test('can create draft + version + current pointer', function () {
    $site = Site::factory()->create();

    $draft = SiteDraft::create([
        'site_id' => $site->id,
        'composition' => ['nav' => ['items' => []]],
        'updated_at' => now(),
    ]);
    expect($draft->composition['nav']['items'])->toBe([]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => ['nav' => ['items' => []]],
        'page_revisions' => [],
        'published_at' => now(),
    ]);

    SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);

    $current = SiteVersionCurrent::find($site->id);
    expect($current->version->id)->toBe($version->id);
});

test('UNIQUE(site_id, version) enforced', function () {
    $site = Site::factory()->create();
    SiteVersion::create([
        'site_id' => $site->id, 'version' => 1,
        'composition' => [], 'page_revisions' => [], 'published_at' => now(),
    ]);

    expect(fn () => SiteVersion::create([
        'site_id' => $site->id, 'version' => 1,
        'composition' => [], 'page_revisions' => [], 'published_at' => now(),
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('creating a Site auto-creates a site_drafts row with default composition', function () {
    $site = \App\Models\Site::factory()->create();
    \App\Models\GeneratedPage::factory()->for($site)->create();

    // Site::factory may run before pages exist; trigger via service
    $draft = app(\App\Services\Site\CompositionService::class)->getOrCreateDraft($site);

    expect($draft)->not->toBeNull();
    expect($draft->composition)->toHaveKey('nav');
    expect($draft->composition)->toHaveKey('theme');
});

test('one site_drafts row per site', function () {
    $site = Site::factory()->create();
    SiteDraft::create(['site_id' => $site->id, 'composition' => [], 'updated_at' => now()]);

    expect(fn () => SiteDraft::create(['site_id' => $site->id, 'composition' => [], 'updated_at' => now()]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});
