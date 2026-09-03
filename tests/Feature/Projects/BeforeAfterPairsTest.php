<?php

use App\Models\BeforeAfterPair;
use App\Models\GeneratedPage;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Models\SiteMedia;
use App\Services\Site\PageRenderer;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function aiResponseWithPairs(array $pairs = []): array
{
    return [
        'hero' => ['title' => 'Our Work', 'subtitle' => 'Stuff', 'image_prompt' => 'p'],
        'categories' => ['Residential', 'Commercial'],
        'gallery' => array_fill(0, 4, ['title' => 't', 'description' => 'd', 'category' => 'Residential', 'image_prompt' => 'p']),
        'case_studies' => [[
            'title' => 'CS', 'description' => 'd', 'category' => 'Commercial',
            'metrics' => [], 'image_prompt' => 'p', 'gallery_image_prompts' => [],
        ]],
        'before_after_pairs' => $pairs,
    ];
}

it('renders B/A section only when both images exist on a pair', function () {
    $site = Site::factory()->create([
        'project_categories' => ['Residential'],
        'honest_project_framing' => false,
    ]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects']);

    $beforeMedia = SiteMedia::factory()->create(['site_id' => $site->id, 'url' => 'https://test.example/b.jpg']);
    $afterMedia = SiteMedia::factory()->create(['site_id' => $site->id, 'url' => 'https://test.example/a.jpg']);

    $completePair = BeforeAfterPair::factory()->for($site)->create([
        'page_id' => $page->id, 'sort_order' => 0,
        'narrative' => 'A complete transformation story here.',
        'before_image_id' => $beforeMedia->id, 'after_image_id' => $afterMedia->id,
    ]);
    $partialPair = BeforeAfterPair::factory()->for($site)->create([
        'page_id' => $page->id, 'sort_order' => 1,
        'narrative' => 'Partial pair — should be hidden.',
        'before_image_id' => $beforeMedia->id, 'after_image_id' => null,
    ]);

    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'projects_hero', 'title' => 'Our Work'],
            ['type' => 'before_after', 'title' => 'Transformation Stories', 'pair_ids' => [$completePair->id, $partialPair->id]],
        ]],
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

    expect($html)->toContain('Transformation Stories');
    expect($html)->toContain('A complete transformation story here.');
    expect($html)->toContain('https://test.example/b.jpg');
    expect($html)->toContain('https://test.example/a.jpg');
    // Partial pair is filtered at render time.
    expect($html)->not->toContain('Partial pair — should be hidden.');
});

it('suppresses the B/A section at render time when honest_project_framing is on', function () {
    $site = Site::factory()->create([
        'project_categories' => ['Residential'],
        'honest_project_framing' => true,
    ]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects']);

    $beforeMedia = SiteMedia::factory()->create(['site_id' => $site->id]);
    $afterMedia = SiteMedia::factory()->create(['site_id' => $site->id]);
    $pair = BeforeAfterPair::factory()->for($site)->create([
        'page_id' => $page->id,
        'narrative' => 'Should not appear under honest framing.',
        'before_image_id' => $beforeMedia->id, 'after_image_id' => $afterMedia->id,
    ]);

    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'before_after', 'title' => 'Transformation Stories', 'pair_ids' => [$pair->id]],
        ]],
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

    expect($html)->not->toContain('Transformation Stories');
    expect($html)->not->toContain('Should not appear under honest framing.');
});
