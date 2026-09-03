<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;

test('section index markers are restricted to admin edit renders', function () {
    $this->withoutVite();

    $site = Site::factory()->create([
        'business_name' => 'Section Marker Co',
        'theme' => 'trades-bold',
    ]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'about']);
    $revision = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'intro', 'title' => 'Our story'],
            ['type' => 'cta', 'title' => 'Work with us'],
        ]],
    ]);
    $page->update(['published_revision_id' => $revision->id]);
    $composition = [
        'nav' => ['items' => []],
        'footer' => ['columns' => [], 'show_credit' => true],
        'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
        'homepage_page_id' => $page->id,
    ];
    $version = SiteVersion::query()->create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => $composition,
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $revision->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::query()->create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);

    $renderer = app(PageRenderer::class);
    $publicBefore = $renderer->render($site, $page->id, mode: 'public');
    $adminEdit = $renderer->render($site, $page->id, mode: 'admin-edit');
    $publicAfter = $renderer->render($site, $page->id, mode: 'public');

    expect($adminEdit)
        ->toContain('data-section-index="0"')
        ->toContain('data-section-index="1"')
        ->and($publicAfter)->not->toContain('data-section-index')
        ->and($publicAfter)->toBe($publicBefore);
});
