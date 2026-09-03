<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('emits the title tag from meta.seo.meta_title on a projects page', function () {
    $site = Site::factory()->create(['business_name' => 'Acme Roofing']);
    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'projects',
        'nav_label' => 'Our Work',
    ]);

    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => [
            'sections' => [
                ['type' => 'projects_hero', 'title' => 'Recent Roofing Work', 'subtitle' => 'Across Wigan.'],
            ],
            'meta' => [
                'seo' => [
                    'meta_title' => 'Wigan Roofer Projects and Case Studies',
                    'meta_description' => 'Roofing projects across Wigan — re-roofs, flat roofs and heritage work.',
                ],
            ],
        ],
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

    expect($html)->toContain('<title>Wigan Roofer Projects and Case Studies</title>')
        ->and($html)->toContain('<meta name="description" content="Roofing projects across Wigan — re-roofs, flat roofs and heritage work.">')
        ->and($html)->not->toContain('<title>Our Work | Acme Roofing</title>');
});

it('falls back when meta_title and meta_description are non-string junk', function () {
    $site = Site::factory()->create(['business_name' => 'Acme Roofing']);
    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'projects',
        'nav_label' => 'Our Work',
    ]);

    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => [
            'sections' => [
                ['type' => 'projects_hero', 'title' => 'Recent Roofing Work', 'subtitle' => 'Across Wigan.'],
            ],
            'meta' => [
                'seo' => [
                    'meta_title' => ['not', 'a', 'string'],
                    'meta_description' => ['also', 'junk'],
                ],
            ],
        ],
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

    expect($html)->toContain('<title>Our Work | Acme Roofing</title>')
        ->and($html)->not->toContain('name="description"');
});
