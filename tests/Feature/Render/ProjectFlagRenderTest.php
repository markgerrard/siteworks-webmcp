<?php

use App\Enums\ProjectItemSource;
use App\Models\GeneratedPage;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function publishProjectsPage(Site $site, array $items, array $sectionOverrides = []): GeneratedPage
{
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects']);

    $sections = [array_merge([
        'type' => 'project_gallery',
        'title' => 'Recent Work',
        'item_ids' => collect($items)->pluck('id')->all(),
    ], $sectionOverrides)];

    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => $sections],
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

    return $page;
}

it('renders Recent Work heading when flag is off', function () {
    config()->set('site.honest_project_framing', false);
    $site = Site::factory()->create(['honest_project_framing' => null]);
    // Public render hydrates Published only (factory default is Draft).
    $items = ProjectItem::factory()->gallery()->published()->for($site)->count(6)->create([
        'source' => ProjectItemSource::AiGenerated,
    ]);
    $page = publishProjectsPage($site, $items->all());

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('Recent Work');
    expect($html)->not->toContain('Example Projects');
    expect($html)->not->toContain('>Example<');    // tile badge text
});

it('renders Example Projects heading when flag is on and items are AI-generated', function () {
    config()->set('site.honest_project_framing', false);
    $site = Site::factory()->create(['honest_project_framing' => true]);
    $items = ProjectItem::factory()->gallery()->published()->for($site)->count(3)->create([
        'source' => ProjectItemSource::AiGenerated,
    ]);
    $page = publishProjectsPage($site, $items->all());

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('Example Projects');
    // At least one "Example" tile badge
    expect(substr_count($html, 'Example'))->toBeGreaterThan(1);
});

it('renders Recent Work even with flag on when all items are sourced', function () {
    config()->set('site.honest_project_framing', false);
    $site = Site::factory()->create(['honest_project_framing' => true]);
    $items = ProjectItem::factory()->gallery()->published()->for($site)->count(3)->create([
        'source' => ProjectItemSource::AgentUpload,
    ]);
    $page = publishProjectsPage($site, $items->all());

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('Recent Work');
    expect($html)->not->toContain('Example Projects');
});

it('uses example vocabulary for mixed sections (any AI → conservative)', function () {
    config()->set('site.honest_project_framing', false);
    $site = Site::factory()->create(['honest_project_framing' => true]);
    $ai = ProjectItem::factory()->gallery()->published()->for($site)->create(['source' => ProjectItemSource::AiGenerated]);
    $sourced = ProjectItem::factory()->gallery()->published()->for($site)->create(['source' => ProjectItemSource::AgentUpload]);
    $page = publishProjectsPage($site, [$ai, $sourced]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('Example Projects');
});
