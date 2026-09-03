<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Regression guard for the "save loses formatting" bug: the page-manager
 * flyout demotes TipTap doc bodies to plain strings joined with "\n\n",
 * and the section templates must render those breaks as real paragraphs.
 * Before RichTextRenderer::renderValue, the string path was bare e() and
 * the whole body collapsed into one blob paragraph.
 */
it('renders a plain-string section body with paragraph breaks as separate <p> blocks', function () {
    $site = Site::factory()->create();
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);

    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [[
            'type' => 'intro',
            'title' => 'Fuse Board Replacements You Can Rely On',
            'body' => "We take electrical safety seriously.\n\nOur qualified team covers Newquay.",
        ]]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'homepage_page_id' => $page->id,
        ],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('<p>We take electrical safety seriously.</p>')
        ->and($html)->toContain('<p>Our qualified team covers Newquay.</p>');
});
