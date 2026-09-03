<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('migrates a site with no current pointer', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'X']]],
    ]);
    $about = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'about',
        'content_data' => ['sections' => [['type' => 'about-text', 'body' => 'About us']]],
    ]);

    // Backfill revisions first.
    $this->artisan('site:backfill-initial-page-revisions');

    $this->artisan('site:migrate-to-versioned-architecture')->assertSuccessful();

    expect(SiteDraft::where('site_id', $site->id)->count())->toBe(1);
    expect(SiteVersion::where('site_id', $site->id)->where('version', 1)->count())->toBe(1);

    $current = SiteVersionCurrent::where('site_id', $site->id)->first();
    expect($current)->not->toBeNull();

    $version = SiteVersion::find($current->version_id);
    expect($version->composition['homepage_page_id'])->toBe($home->id);
    expect(collect($version->page_revisions)->pluck('page_id')->all())
        ->toContain($home->id, $about->id);
});

test('idempotent — re-running skips already-migrated sites', function () {
    $site = Site::factory()->create();
    GeneratedPage::factory()->for($site)->create();
    $this->artisan('site:backfill-initial-page-revisions');

    $this->artisan('site:migrate-to-versioned-architecture');
    $countAfterFirst = SiteVersion::where('site_id', $site->id)->count();

    $this->artisan('site:migrate-to-versioned-architecture');
    $countAfterSecond = SiteVersion::where('site_id', $site->id)->count();

    expect($countAfterFirst)->toBe(1);
    expect($countAfterSecond)->toBe(1);
});

test('per-site logging — outputs migrated/skipped lines', function () {
    $migratedSite = Site::factory()->create();
    GeneratedPage::factory()->for($migratedSite)->create();

    $skippedSite = Site::factory()->create();
    GeneratedPage::factory()->for($skippedSite)->create();
    $this->artisan('site:backfill-initial-page-revisions');
    $this->artisan('site:migrate-to-versioned-architecture');  // first run migrates skippedSite

    // Add a new site that isn't migrated yet
    $newSite = Site::factory()->create();
    GeneratedPage::factory()->for($newSite)->create();
    $this->artisan('site:backfill-initial-page-revisions');

    $this->artisan('site:migrate-to-versioned-architecture')
        ->expectsOutputToContain("site_id={$skippedSite->id}: skipped (already migrated)")
        ->expectsOutputToContain("site_id={$newSite->id}: migrated")
        ->assertSuccessful();
});

test('skips sites with no pages', function () {
    $site = Site::factory()->create();
    // No GeneratedPage rows
    $this->artisan('site:migrate-to-versioned-architecture')
        ->expectsOutputToContain("site_id={$site->id}: skipped (no pages)")
        ->assertSuccessful();

    expect(SiteVersionCurrent::where('site_id', $site->id)->count())->toBe(0);
});

test('skips sites where pages have no published_revision_id (backfill not run)', function () {
    $site = Site::factory()->create();
    $page = GeneratedPage::factory()->for($site)->create();
    // Deliberately NOT running backfill — page has content_data but no published_revision_id

    $this->artisan('site:migrate-to-versioned-architecture')
        ->expectsOutputToContain("site_id={$site->id}: skipped (pages without revision pointers)")
        ->assertSuccessful();

    expect(SiteVersionCurrent::where('site_id', $site->id)->count())->toBe(0);
});
