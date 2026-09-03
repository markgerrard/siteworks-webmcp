<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use Illuminate\Support\Str;

/**
 * § D4's falsifier, on the rendered DOM (not a string search): every field
 * marker must sit inside a section-id wrapper, which is what lets the front end
 * resolve `el.closest('[data-section-id]')` without touching 52 section blades.
 * This is the test that holds the 52-file door shut.
 */
test('every data-editable node in an admin-edit render has a data-section-id ancestor', function () {
    $this->withoutVite();

    [$site, $page, $ids] = sectionIdMarkerSeed();

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'admin-edit');

    // The wrapper carries the STORED id, at the same gate as data-section-index.
    expect($html)
        ->toContain('data-section-id="'.$ids[0].'"')
        ->toContain('data-section-id="'.$ids[1].'"');

    $dom = new DOMDocument;
    $dom->loadHTML('<?xml encoding="utf-8 ?>'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);

    $editables = (new DOMXPath($dom))->query('//*[@data-editable]');
    expect($editables)->not->toBeFalse()->and($editables->length)->toBeGreaterThan(0);

    foreach ($editables as $node) {
        $ancestor = $node->parentNode;
        $wrapped = false;
        while ($ancestor instanceof DOMElement) {
            if ($ancestor->hasAttribute('data-section-id')) {
                $wrapped = true;
                break;
            }
            $ancestor = $ancestor->parentNode;
        }
        // Fail naming the orphan so a false negative is debuggable from the message.
        expect($wrapped)->toBeTrue('data-editable "'.$node->getAttribute('data-editable')
            .'" has no [data-section-id] ancestor');
    }
});

/**
 * § D7 / invariant 9. The marker is mode-gated: public render never emits it.
 */
test('public render contains no data-section-id', function () {
    $this->withoutVite();

    [$site, $page] = sectionIdMarkerSeed();

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)
        ->not->toContain('data-section-id')
        ->not->toContain('data-section-index');
});

/**
 * @return array{0: Site, 1: GeneratedPage, 2: list<string>}
 */
function sectionIdMarkerSeed(): array
{
    $site = Site::factory()->create([
        'business_name' => 'Section Id Marker Co',
        'theme' => 'trades-bold',
    ]);
    $ids = [(string) Str::ulid(), (string) Str::ulid()];
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'about']);
    $revision = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'intro', 'title' => 'Our story', 'id' => $ids[0]],
            ['type' => 'cta', 'title' => 'Work with us', 'id' => $ids[1]],
        ]],
    ]);
    $page->update(['published_revision_id' => $revision->id]);
    $version = SiteVersion::query()->create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'homepage_page_id' => $page->id,
        ],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $revision->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::query()->create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);

    return [$site, $page->fresh(), $ids];
}
