<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\Editor\SectionIdentifiers;
use App\Services\Site\PageRenderer;
use App\Services\Site\SitePublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Oracle rules:
 * - Every expected value computed independently of the code under test.
 * - Every assertion asserts equality on a fixture where a wrong implementation
 *   cannot coincide with a right one.
 * - Every test names the wrong implementation it catches.
 */

// ────────────────────────────────────────────────────────────────────────
// Test 1: Lost-update guard (§ D1.1) — write this one first
// ────────────────────────────────────────────────────────────────────────

test('saving hook does not write stale content_data over a fresher DB row', function () {
    $staleContent = [
        'sections' => [
            ['type' => 'hero', 'title' => 'Original'],
        ],
    ];
    $page = GeneratedPage::factory()->create([
        'content_data' => $staleContent,
    ]);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => []],
    ]);

    GeneratedPage::query()->whereKey($page->id)
        ->update(['content_data' => json_encode($staleContent)]);
    $stalePage = GeneratedPage::query()->findOrFail($page->id);

    $freshContent = ['sections' => [['type' => 'cta', 'title' => 'Fresh from DB']]];
    GeneratedPage::query()->whereKey($page->id)
        ->update(['content_data' => json_encode($freshContent)]);

    $stalePage->update(['published_revision_id' => $rev->id]);

    $dbRow = GeneratedPage::query()->where('id', $page->id)->first();
    $dbContent = is_string($dbRow->content_data)
        ? json_decode($dbRow->content_data, true)
        : $dbRow->content_data;
    expect($dbContent)->toBe($freshContent);

    // Catches: an ungated hook, which writes the stale content back into the DB.
});

test('partial-select save does not blank content_data', function () {
    $page = GeneratedPage::factory()->create([
        'content_data' => [
            'sections' => [
                ['type' => 'hero', 'title' => 'Keep me'],
            ],
        ],
    ]);

    $partial = GeneratedPage::select('id', 'archived_at')->find($page->id);
    expect($partial->content_data)->toBeNull();

    $partial->update(['archived_at' => now()]);

    $dbRow = GeneratedPage::query()->where('id', $page->id)->first();
    $dbContent = is_string($dbRow->content_data)
        ? json_decode($dbRow->content_data, true)
        : $dbRow->content_data;
    expect($dbContent['sections'][0]['title'])->toBe('Keep me');

    // Catches: an ungated hook that overwrites content_data with null
    // or [] when it is not loaded.
});
// ────────────────────────────────────────────────────────────────────────
// Test 3: Stability — editor operations preserve ids
// ────────────────────────────────────────────────────────────────────────

test('editor add_section preserves existing ids and mints one new id', function () {
    $site = Site::factory()->create();
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'about']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => [
            'sections' => [
                ['type' => 'intro', 'id' => 'A'],
                ['type' => 'cta', 'id' => 'B'],
            ],
        ],
    ]);
    $page->update(['draft_revision_id' => $rev->id]);

    $content = $rev->content_data;
    $content['sections'][] = ['type' => 'services'];
    $rev->content_data = $content;
    $rev->save();

    $ids = array_column($rev->fresh()->content_data['sections'], 'id');
    expect($ids)->toHaveCount(3);
    expect($ids[0])->toBe('A');
    expect($ids[1])->toBe('B');
    expect($ids[2])->toBeString()->toHaveLength(26);
    expect($ids[2])->not->toBe('A');
    expect($ids[2])->not->toBe('B');
});

test('editor move_section preserves the id set', function () {
    $site = Site::factory()->create();
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'about']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => [
            'sections' => [
                ['type' => 'intro', 'id' => 'A'],
                ['type' => 'cta', 'id' => 'B'],
            ],
        ],
    ]);
    $page->update(['draft_revision_id' => $rev->id]);

    $content = $rev->content_data;
    $content['sections'] = [$content['sections'][1], $content['sections'][0]];
    $rev->content_data = $content;
    $rev->save();

    $ids = array_column($rev->fresh()->content_data['sections'], 'id');
    expect($ids)->toHaveCount(2);
    expect($ids)->toContain('A');
    expect($ids)->toContain('B');
});

test('publish preserves ids unchanged', function () {
    $site = Site::factory()->create();
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'about']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => [
            'sections' => [
                ['type' => 'intro', 'id' => 'A'],
                ['type' => 'cta', 'id' => 'B'],
            ],
        ],
    ]);
    $page->update(['draft_revision_id' => $rev->id]);

    app(SitePublishService::class)->publishSite($site);

    $current = SiteVersionCurrent::query()
        ->where('site_id', $site->id)
        ->firstOrFail();
    $pinned = collect($current->version->page_revisions)
        ->firstWhere('page_id', $page->id);
    $pinnedRev = PageRevision::find($pinned['revision_id']);
    $pinnedIds = array_column($pinnedRev->content_data['sections'], 'id');
    expect($pinnedIds)->toHaveCount(2);
    expect($pinnedIds)->toContain('A');
    expect($pinnedIds)->toContain('B');
});

// ────────────────────────────────────────────────────────────────────────
// Test 4: Class (c) hero — id stability
// ────────────────────────────────────────────────────────────────────────

// ────────────────────────────────────────────────────────────────────────
// Test 5: Class (c) CTA — home page id must NOT leak onto the projects page
// ────────────────────────────────────────────────────────────────────────

// ────────────────────────────────────────────────────────────────────────
// Test 8: Render strip — assert on the returned array, NOT the DOM
// ────────────────────────────────────────────────────────────────────────

test('injectedServiceBlock strips id from both injected blocks', function () {
    $this->withoutVite();

    $site = Site::factory()->create([
        'business_name' => 'Test Co',
        'location' => 'Test Town',
        'theme' => 'trades-bold',
    ]);
    \App\Models\BusinessProfile::factory()->for($site)->create([
        'profile_data' => [
            'archetype' => 'local_service',
            'lead_form_policy' => 'all',
            'contact' => ['phones' => ['+44 1234 567890']],
        ],
    ]);

    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'plumbing-service']);
    $pageRev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [['type' => 'intro', 'title' => 'Intro']]],
    ]);
    $page->update(['published_revision_id' => $pageRev->id]);

    $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => [
            'sections' => [
                ['type' => 'lead_form', 'id' => 'HOME_ID',
                    'title' => 'Form', 'intro' => 'Intro', 'extra_fields' => []],
            ],
        ],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);

    // Publish so the render-time version-resolver finds the home form.
    app(SitePublishService::class)->publishSite($site);

    $renderer = app(PageRenderer::class);

    $ref = new \ReflectionMethod(PageRenderer::class, 'injectedServiceBlock');
    $result = $ref->invoke($renderer, $site, $page, [], false);

    expect($result)->not->toBeNull();
    expect(array_key_exists('id', $result['block'][0]))->toBeFalse();
    expect(array_key_exists('id', $result['block'][1]))->toBeFalse();

    // Catches: omission of 'id' from the strip list.
});
