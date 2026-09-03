<?php

use App\Enums\PageKind;
use App\Enums\PageStatus;
use App\Enums\ProjectItemStatus;
use App\Models\GeneratedPage;
use App\Models\ProjectCategory;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Services\Site\SimilarProjectsSelector;
use Illuminate\Support\Facades\View;

function similarDetailPage(Site $site, GeneratedPage $parent, string $leaf, array $attributes = []): GeneratedPage
{
    return GeneratedPage::factory()->for($site)->create(array_merge([
        'page_type' => $parent->page_type.'/'.$leaf,
        'parent_id' => $parent->id,
        'kind' => PageKind::ProjectDetail,
        'status' => PageStatus::Published,
        'nav_label' => str_replace('-', ' ', $leaf),
    ], $attributes));
}

function similarItem(Site $site, GeneratedPage $detail, ProjectCategory $category, array $attributes = []): ProjectItem
{
    return ProjectItem::factory()->published()->for($site)->create(array_merge([
        'detail_page_id' => $detail->id,
        'category_id' => $category->id,
        'category' => $category->name,
        'title' => $detail->nav_label,
    ], $attributes));
}

it('selects only same-site items that share category_id', function () {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $parent = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects', 'kind' => PageKind::Core]);
    $otherParent = GeneratedPage::factory()->for($otherSite)->create(['page_type' => 'projects', 'kind' => PageKind::Core]);

    $lofts = ProjectCategory::factory()->for($site)->create(['name' => 'Lofts']);
    $kitchens = ProjectCategory::factory()->for($site)->create(['name' => 'Kitchens']);
    $foreignCat = ProjectCategory::factory()->for($otherSite)->create(['name' => 'Lofts']);

    $currentPage = similarDetailPage($site, $parent, 'loft-one');
    $sameCatPage = similarDetailPage($site, $parent, 'loft-two');
    $otherCatPage = similarDetailPage($site, $parent, 'kitchen-one');
    $foreignPage = similarDetailPage($otherSite, $otherParent, 'loft-foreign');

    $current = similarItem($site, $currentPage, $lofts, ['title' => 'Current loft']);
    $same = similarItem($site, $sameCatPage, $lofts, ['title' => 'Sister loft']);
    similarItem($site, $otherCatPage, $kitchens, ['title' => 'Kitchen']);
    similarItem($otherSite, $foreignPage, $foreignCat, ['title' => 'Foreign loft']);

    $selected = app(SimilarProjectsSelector::class)->forPage($site, $currentPage);

    expect($selected->pluck('id')->all())->toBe([$same->id])
        ->and($selected->pluck('id')->all())->not->toContain($current->id);
});

it('orders newest published first and ties on id descending', function () {
    $site = Site::factory()->create();
    $parent = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects', 'kind' => PageKind::Core]);
    $category = ProjectCategory::factory()->for($site)->create(['name' => 'Lofts']);

    $currentPage = similarDetailPage($site, $parent, 'loft-current');
    similarItem($site, $currentPage, $category, [
        'title' => 'Current',
        'created_at' => '2026-01-01 00:00:00',
    ]);

    $olderPage = similarDetailPage($site, $parent, 'loft-older');
    $older = similarItem($site, $olderPage, $category, [
        'title' => 'Older',
        'created_at' => '2026-02-01 00:00:00',
    ]);

    $tiedFirstPage = similarDetailPage($site, $parent, 'loft-tied-a');
    $tiedFirst = similarItem($site, $tiedFirstPage, $category, [
        'title' => 'Tied first inserted',
        'created_at' => '2026-03-01 00:00:00',
    ]);

    $tiedSecondPage = similarDetailPage($site, $parent, 'loft-tied-b');
    $tiedSecond = similarItem($site, $tiedSecondPage, $category, [
        'title' => 'Tied second inserted',
        'created_at' => '2026-03-01 00:00:00',
    ]);

    expect($tiedSecond->id)->toBeGreaterThan($tiedFirst->id);

    $selected = app(SimilarProjectsSelector::class)->forPage($site, $currentPage);

    expect($selected->pluck('id')->all())->toBe([$tiedSecond->id, $tiedFirst->id])
        ->and($selected->pluck('id')->all())->not->toContain($older->id);
});

it('caps similar projects at two candidates', function () {
    $site = Site::factory()->create();
    $parent = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects', 'kind' => PageKind::Core]);
    $category = ProjectCategory::factory()->for($site)->create(['name' => 'Lofts']);

    $currentPage = similarDetailPage($site, $parent, 'loft-current');
    similarItem($site, $currentPage, $category, ['created_at' => '2026-01-01 00:00:00']);

    $ids = [];
    foreach (['a', 'b', 'c'] as $i => $leaf) {
        $page = similarDetailPage($site, $parent, 'loft-'.$leaf);
        $item = similarItem($site, $page, $category, [
            'created_at' => '2026-04-0'.($i + 1).' 00:00:00',
        ]);
        $ids[] = $item->id;
    }

    $selected = app(SimilarProjectsSelector::class)->forPage($site, $currentPage);

    expect($selected)->toHaveCount(2)
        ->and($selected->pluck('id')->all())->toBe([
            $ids[2],
            $ids[1],
        ]);
});

it('returns no candidates for a draft sibling or a null category_id', function () {
    $site = Site::factory()->create();
    $parent = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects', 'kind' => PageKind::Core]);
    $category = ProjectCategory::factory()->for($site)->create(['name' => 'Lofts']);

    $currentPage = similarDetailPage($site, $parent, 'loft-current');
    similarItem($site, $currentPage, $category);

    $draftPage = similarDetailPage($site, $parent, 'loft-draft');
    similarItem($site, $draftPage, $category, [
        'status' => ProjectItemStatus::Draft,
        'title' => 'Draft loft',
    ]);

    $uncategorisedPage = similarDetailPage($site, $parent, 'loft-plain');
    ProjectItem::factory()->published()->for($site)->create([
        'detail_page_id' => $uncategorisedPage->id,
        'category_id' => null,
        'title' => 'No category',
    ]);

    expect(app(SimilarProjectsSelector::class)->forPage($site, $currentPage))->toHaveCount(0)
        ->and(app(SimilarProjectsSelector::class)->forPage($site, $uncategorisedPage))->toHaveCount(0);
});

it('suppresses the similar_projects section when there are zero candidates', function () {
    $site = Site::factory()->create();
    $parent = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects', 'kind' => PageKind::Core]);
    $page = similarDetailPage($site, $parent, 'loft-lonely');

    $html = View::make('site.sections.similar_projects', [
        'section' => ['type' => 'similar_projects', 'title' => 'Similar projects'],
        'sectionIndex' => 4,
        'pageId' => $page->id,
        'mode' => 'public',
        'emitMarkers' => false,
        'emitFormMarkers' => false,
        'schema' => [],
        'theme' => [],
        'profile' => [],
        'site' => $site,
        'page' => $page,
        'pagesBySlug' => [],
        'itemsById' => collect(),
        'mediaById' => collect(),
    ])->render();

    expect(trim($html))->toBe('')
        ->and($html)->not->toContain('data-similar-projects')
        ->and($html)->not->toContain('Similar projects');
});
