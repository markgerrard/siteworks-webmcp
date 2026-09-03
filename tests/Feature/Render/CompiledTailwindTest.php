<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * The public renderer must ship compiled Tailwind, not the Play CDN:
 * cdn.tailwindcss.com is a ~300KB runtime JIT script that warns in the
 * console, adds an external runtime dependency to every client site,
 * and re-generates utilities on each visitor's device.
 */
it('serves compiled tailwind instead of the play cdn', function () {
    $site = Site::factory()->create();
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Welcome']]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);
    $version = SiteVersion::create([
        'site_id' => $site->id, 'version' => 1,
        'composition' => [
            'nav' => ['items' => []], 'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'homepage_page_id' => $page->id,
        ],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->not->toContain('cdn.tailwindcss.com')
        ->and($html)->toMatch('/<link rel="stylesheet"[^>]*href="[^"]*\/site-[^"]*\.css"/');
});

test('site css scans shop views so the storefront does not need the play cdn', function () {
    $css = file_get_contents(resource_path('css/site.css'));

    expect($css)->toContain("@source '../views/shop'")
        ->and($css)->toContain("@source '../views/components/shop'");
});

test('the compiled site css contains the shop layout classes (no play cdn)', function () {
    $css = file_get_contents(resource_path('css/site.css'));

    expect($css)->toContain("@source '../views/shop'")
        ->and($css)->toContain("@source '../views/components/shop'");
});
