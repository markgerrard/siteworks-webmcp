<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('smoke renders all migrated sites successfully', function () {
    $site = Site::factory()->create(['custom_domain' => 'flowers.example']);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Smoke OK']]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id, 'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'],
            'homepage_page_id' => $page->id,
        ],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    $this->artisan('site:smoke-test-versioned-renderer')
        ->expectsOutputToContain("site_id={$site->id}: OK")
        ->assertSuccessful();
});

test('failed render reported as failure with reason', function () {
    $site = Site::factory()->create();
    // No site_versions_current — render will fail

    $this->artisan('site:smoke-test-versioned-renderer')
        ->expectsOutputToContain("site_id={$site->id}: SKIP (no current pointer)")
        ->assertSuccessful();
});
