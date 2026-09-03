<?php

use App\Enums\PageKind;
use App\Enums\PageStatus;
use App\Enums\ProjectItemStatus;
use App\Models\GeneratedPage;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\SiteMedia;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @return array{site: Site, projects: GeneratedPage, item: ProjectItem, gallery: list<SiteMedia>}
 */
function projectDetailScaffoldFixture(array $itemAttributes = []): array
{
    $site = Site::factory()->create();
    $projects = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'projects',
        'kind' => PageKind::Core,
        'status' => PageStatus::Published,
        'nav_label' => 'Our Work',
    ]);
    $item = ProjectItem::factory()->caseStudy()->published()->for($site)->create(array_merge([
        'title' => 'Crème & Loft 🏡',
        'description' => 'A bright new floor created above a family home.',
        'category' => 'Loft conversions',
    ], $itemAttributes));
    $gallery = [
        SiteMedia::factory()->for($site)->create([
            'project_item_id' => $item->id,
            'metadata' => ['role' => 'case_study_gallery', 'gallery_index' => 0],
        ]),
        SiteMedia::factory()->for($site)->create([
            'project_item_id' => $item->id,
            'metadata' => ['role' => 'case_study_gallery', 'gallery_index' => 1],
        ]),
    ];

    return compact('site', 'projects', 'item', 'gallery');
}

/**
 * @param  array{sections: list<array<string, mixed>>}  $expectedWithoutIds
 */
function assertMintedScaffoldContent(array $actual, array $expectedWithoutIds): void
{
    expect($actual)->toHaveKey('sections')
        ->and(array_keys($actual))->toBe(['sections'])
        ->and($actual['sections'])->toHaveCount(count($expectedWithoutIds['sections']));

    foreach ($expectedWithoutIds['sections'] as $index => $expectedSection) {
        $section = $actual['sections'][$index];
        expect($section)->toHaveKey('id')
            ->and(Str::isUlid($section['id']))->toBeTrue();
        unset($section['id']);
        expect($section)->toEqual($expectedSection);
    }
}

it('scaffolds a draft project detail page and point-in-time section seed', function () {
    ['site' => $site, 'projects' => $projects, 'item' => $item, 'gallery' => $gallery] = projectDetailScaffoldFixture();

    $this->artisan('site:scaffold-project-detail', [
        'site' => (string) $site->id,
        'project-item' => (string) $item->id,
    ])->expectsOutputToContain('Created draft project detail page')
        ->assertSuccessful();

    $page = GeneratedPage::query()->where('site_id', $site->id)->where('page_type', 'projects/creme-loft')->sole();
    $expectedContent = ['sections' => [
        [
            'type' => 'project_detail_hero',
            'title' => 'Crème & Loft 🏡',
            'intro' => 'A bright new floor created above a family home.',
            'hero_image_id' => $gallery[0]->id,
        ],
        [
            'type' => 'project_about',
            'title' => 'Crème & Loft 🏡',
            'body' => '',
            'project_type' => 'Loft conversions',
            'location' => '',
            'image_id' => $gallery[1]->id,
        ],
        [
            'type' => 'project_photo_essay',
            'title' => 'Project gallery',
            'intro' => '',
            'category' => 'Loft conversions',
            // Hero shows gallery[0]; the essay gets the rest.
            'image_ids' => [$gallery[1]->id],
        ],
        [
            'type' => 'project_cta_row',
            'title' => 'Planning something similar?',
            'body' => 'Tell us what you have in mind and we’ll help shape the next steps.',
            'cta_label' => 'Start a conversation',
            'cta_url' => '#contact',
        ],
    ]];

    expect($page->parent_id)->toBe($projects->id)
        ->and($page->kind)->toBe(PageKind::ProjectDetail)
        ->and($page->layout_preset_key)->toBeNull() // unpinned: inherits the projects personality
        ->and($page->status)->toBe(PageStatus::Draft)
        ->and($page->nav_label)->toBe('Crème & Loft 🏡')
        ->and($page->published_revision_id)->toBeNull()
        ->and($page->draftRevision)->toBeInstanceOf(PageRevision::class)
        ->and($page->draftRevision->ai_generated)->toBeFalse()
        ->and($item->fresh()->detail_page_id)->toBe($page->id)
        ->and($item->fresh()->status)->toBe(ProjectItemStatus::Published)
        ->and($projects->fresh()->page_type)->toBe('projects');

    assertMintedScaffoldContent($page->content_data, $expectedContent);
    assertMintedScaffoldContent($page->draftRevision->content_data, $expectedContent);
});

it('is idempotent and never refreshes the point-in-time copy', function () {
    ['site' => $site, 'item' => $item] = projectDetailScaffoldFixture();

    $this->artisan('site:scaffold-project-detail', [
        'site' => (string) $site->id,
        'project-item' => (string) $item->id,
    ])->assertSuccessful();

    $page = $item->fresh()->detailPage;
    $originalAttributes = $page->getAttributes();
    $originalRevisionAttributes = $page->draftRevision->getAttributes();
    $item->update([
        'title' => 'Merchant changed title',
        'description' => 'Merchant changed description.',
    ]);
    $rerunQueries = [];
    DB::listen(function (QueryExecuted $query) use (&$rerunQueries): void {
        $rerunQueries[] = $query->sql;
    });

    $this->artisan('site:scaffold-project-detail', [
        'site' => (string) $site->id,
        'project-item' => (string) $item->id,
    ])->expectsOutputToContain('Already scaffolded')
        ->assertSuccessful();

    expect(GeneratedPage::query()->where('site_id', $site->id)->count())->toBe(2)
        ->and(PageRevision::query()->where('page_id', $page->id)->count())->toBe(1)
        ->and($page->fresh()->getAttributes())->toBe($originalAttributes)
        ->and($page->draftRevision->fresh()->getAttributes())->toBe($originalRevisionAttributes)
        ->and(collect($rerunQueries)->contains(fn (string $sql): bool => str_starts_with(strtolower($sql), 'update ')))->toBeFalse();
});

it('suffixes a taken full path without mutating the colliding page', function () {
    ['site' => $site, 'projects' => $projects, 'item' => $item] = projectDetailScaffoldFixture([
        'title' => 'Garden Room',
    ]);
    $collision = GeneratedPage::factory()->for($site)->create([
        'parent_id' => $projects->id,
        'page_type' => 'projects/garden-room',
        'kind' => PageKind::ProjectDetail,
        'status' => PageStatus::Draft,
        'content_data' => ['sections' => [['type' => 'project_detail_hero', 'title' => 'Existing']]],
    ]);
    $collisionAttributes = $collision->refresh()->getAttributes();

    $this->artisan('site:scaffold-project-detail', [
        'site' => (string) $site->id,
        'project-item' => (string) $item->id,
    ])->expectsOutputToContain('projects/garden-room-2')
        ->assertSuccessful();

    expect($item->fresh()->detailPage->page_type)->toBe('projects/garden-room-2')
        ->and($collision->fresh()->getAttributes())->toBe($collisionAttributes);
});

it('fails clearly when the site has no active root projects page', function () {
    $site = Site::factory()->create();
    $item = ProjectItem::factory()->for($site)->create();

    $this->artisan('site:scaffold-project-detail', [
        'site' => (string) $site->id,
        'project-item' => (string) $item->id,
    ])->expectsOutputToContain("Site [{$site->id}] has no active root projects page")
        ->assertFailed();

    expect(GeneratedPage::query()->where('site_id', $site->id)->count())->toBe(0)
        ->and($item->fresh()->detail_page_id)->toBeNull();
});

it('rejects an unknown preset and a project item from another site', function () {
    ['site' => $site, 'item' => $item] = projectDetailScaffoldFixture();
    $foreignSite = Site::factory()->create();

    $this->artisan('site:scaffold-project-detail', [
        'site' => (string) $site->id,
        'project-item' => (string) $item->id,
        '--preset' => 'missing',
    ])->expectsOutputToContain('Unknown project detail preset [missing]')
        ->assertFailed();

    $this->artisan('site:scaffold-project-detail', [
        'site' => (string) $foreignSite->id,
        'project-item' => (string) $item->id,
    ])->expectsOutputToContain("Project item [{$item->id}] does not belong to site [{$foreignSite->id}]")
        ->assertFailed();

    expect($item->fresh()->detail_page_id)->toBeNull();
});

it('reports a dry run without writing a page revision or item link', function () {
    ['site' => $site, 'item' => $item] = projectDetailScaffoldFixture();
    $pageCount = GeneratedPage::query()->count();

    $this->artisan('site:scaffold-project-detail', [
        'site' => (string) $site->id,
        'project-item' => (string) $item->id,
        '--dry-run' => true,
    ])->expectsOutputToContain('Dry run: would create draft project detail page')
        ->expectsOutputToContain('page_type=projects/creme-loft')
        ->expectsOutputToContain('preset=inherit')
        ->assertSuccessful();

    expect(GeneratedPage::query()->count())->toBe($pageCount)
        ->and(PageRevision::query()->count())->toBe(0)
        ->and($item->fresh()->detail_page_id)->toBeNull();
});

it('bounds nav_label to the column limit for long real-world titles (post-merge fix)', function () {
    ['site' => $site, 'item' => $item] = projectDetailScaffoldFixture([
        'title' => 'Large-format porcelain patio with sawn-edge kerbing, Altrincham',
    ]);

    $exit = \Illuminate\Support\Facades\Artisan::call('site:scaffold-project-detail', ['site' => (string) $site->id, 'project-item' => (string) $item->id]);
    expect($exit)->toBe(0, 'command output: '.\Illuminate\Support\Facades\Artisan::output());

    $page = \App\Models\GeneratedPage::where('site_id', $site->id)
        ->where('kind', \App\Enums\PageKind::ProjectDetail)->firstOrFail();
    expect(mb_strlen((string) $page->nav_label))->toBeLessThanOrEqual(30)
        ->and((string) $page->nav_label)->not->toMatch('/[,;:.\\-\x{2013}\x{2014}\s]$/u');
});

it('falls back to role-less attached media for gallery items (post-merge fix)', function () {
    ['site' => $site, 'item' => $item] = projectDetailScaffoldFixture();
    // Strip the role tags so the media look like real gallery-item rows.
    \App\Models\SiteMedia::where('project_item_id', $item->id)->update(['metadata' => null]);

    \Illuminate\Support\Facades\Artisan::call('site:scaffold-project-detail', ['site' => (string) $site->id, 'project-item' => (string) $item->id]);

    $page = \App\Models\GeneratedPage::where('site_id', $site->id)->where('kind', \App\Enums\PageKind::ProjectDetail)->firstOrFail();
    $essay = collect($page->content_data['sections'])->firstWhere('type', 'project_photo_essay');
    expect($essay['image_ids'])->toHaveCount(1);
});
