<?php

use App\Models\GeneratedPage;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('includes projects sections when rendering a stacked one-page layout', function () {
    $site = Site::factory()->create([
        'business_name' => 'Acme Roofing',
        'theme' => 'trades-bold',
        'preview_layout' => \App\Enums\PreviewLayout::OnePage->value,
        'project_categories' => ['Residential', 'Commercial'],
    ]);

    $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $projects = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects']);

    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Welcome', 'subtitle' => 'Acme Roofing'],
        ]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);

    // Public render hydrates Published only (factory default is Draft).
    $galleryItems = ProjectItem::factory()->gallery()->published()->for($site)->count(6)->create([
        'page_id' => $projects->id,
        'category' => 'Residential',
    ]);

    $projectsRev = PageRevision::factory()->for($projects, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'projects_hero', 'title' => 'Our Projects', 'subtitle' => 'Precision work.'],
            ['type' => 'project_gallery', 'title' => 'Recent Work', 'item_ids' => $galleryItems->pluck('id')->all()],
        ]],
    ]);
    $projects->update(['published_revision_id' => $projectsRev->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'homepage_page_id' => $home->id,
        ],
        'page_revisions' => [
            ['page_id' => $home->id, 'revision_id' => $homeRev->id],
            ['page_id' => $projects->id, 'revision_id' => $projectsRev->id],
        ],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    $html = app(PageRenderer::class)->renderStacked($site, mode: 'public');

    expect($html)->toContain('Welcome');                     // home
    expect($html)->toContain('Our Projects');                // projects hero
    expect($html)->toContain('Recent Work');                 // gallery heading
    foreach ($galleryItems as $item) {
        expect($html)->toContain($item->title);              // each tile
    }
});
