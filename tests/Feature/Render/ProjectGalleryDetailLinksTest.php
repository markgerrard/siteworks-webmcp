<?php

use App\Enums\PageKind;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\LayoutPreset;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\ChildPageEnumerator;
use App\Services\Site\PageLayoutRegistry;
use App\Services\Site\PageRenderer;
use Illuminate\Support\Facades\View;

/**
 * @return array<string, mixed>
 */
function galleryLinkRecipe(bool $link, string $galleryVariant = 'classic'): array
{
    return [
        'schema_version' => 1,
        'variants' => ['project_gallery' => $galleryVariant],
        'options' => ['link_detail_pages' => $link],
        'eyebrow_policy' => 'all',
        'eyebrow_sections' => ['project_gallery'],
    ];
}

function galleryDetailPage(Site $site, GeneratedPage $parent, string $leaf, array $attributes = []): GeneratedPage
{
    return GeneratedPage::factory()->for($site)->create(array_merge([
        'page_type' => $parent->page_type.'/'.$leaf,
        'parent_id' => $parent->id,
        'kind' => PageKind::ProjectDetail,
        'status' => PageStatus::Published,
        'nav_label' => str_replace('-', ' ', $leaf),
        // Substance by default: the click-through gate only links detail
        // pages carrying more than the tile (about body / essay images).
        'content_data' => ['sections' => [
            ['type' => 'project_detail_hero', 'title' => $leaf],
            ['type' => 'project_about', 'title' => $leaf, 'body' => 'A fuller story about this project with enough said to earn a page.'],
        ]],
    ], $attributes));
}

/**
 * @param  list<array<string, mixed>>  $itemSpecs
 * @return array{0: Site, 1: GeneratedPage, 2: list<ProjectItem>}
 */
function makeLinkedGallerySite(array $itemSpecs, ?array $recipe = null, string $galleryVariant = 'classic'): array
{
    $site = Site::factory()->create([
        'business_name' => 'Acme Lofts',
        'theme' => 'trades-bold',
    ]);

    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'projects',
        'kind' => PageKind::Core,
        'nav_label' => 'Our Work',
    ]);

    if ($recipe !== null) {
        LayoutPreset::factory()->active()->create([
            'site_id' => $site->id,
            'page_kind' => 'projects',
            'key' => 'link-details',
            'recipe' => $recipe,
        ]);
        $page->update(['layout_preset_key' => 'link-details']);
    }

    $items = [];
    $pinned = [];
    foreach ($itemSpecs as $index => $spec) {
        $detail = null;
        if (array_key_exists('leaf', $spec)) {
            $detail = galleryDetailPage($site, $page, $spec['leaf'], $spec['page'] ?? []);
            $detailRev = PageRevision::factory()->for($detail, 'page')->create([
                'content_data' => ['sections' => [
                    ['type' => 'project_detail_hero', 'title' => $spec['title']],
                ]],
            ]);
            $detail->update(['published_revision_id' => $detailRev->id]);
            $pinned[] = ['page_id' => $detail->id, 'revision_id' => $detailRev->id];
        }

        $items[] = ProjectItem::factory()->gallery()->published()->for($site)->create([
            'page_id' => $page->id,
            'detail_page_id' => $detail?->id,
            'title' => $spec['title'],
            'category' => $spec['category'] ?? 'Lofts',
            'description' => 'd',
            'sort_order' => $index,
        ]);
    }

    $sections = [
        ['type' => 'projects_hero', 'title' => 'Projects'],
        [
            'type' => 'project_gallery',
            'title' => 'Recent Work',
            'variant' => $galleryVariant === 'classic' ? null : $galleryVariant,
            'item_ids' => array_map(fn (ProjectItem $item): int => $item->id, $items),
        ],
    ];
    if ($galleryVariant === 'classic') {
        unset($sections[1]['variant']);
    }

    $revision = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => $sections],
    ]);
    $page->update(['published_revision_id' => $revision->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'homepage_page_id' => $page->id,
        ],
        'page_revisions' => array_merge(
            [['page_id' => $page->id, 'revision_id' => $revision->id]],
            $pinned,
        ),
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);

    return [$site, $page->fresh(), $items];
}

function renderGalleryPage(Site $site, GeneratedPage $page): string
{
    return app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');
}

it('accepts boolean link_detail_pages on the projects kind and rejects other values', function () {
    $registry = app(PageLayoutRegistry::class);
    $base = [
        'schema_version' => 1,
        'variants' => ['project_gallery' => 'classic'],
        'eyebrow_policy' => 'all',
        'eyebrow_sections' => ['project_gallery'],
    ];

    expect($registry->isUsable($base + ['options' => ['link_detail_pages' => true]], 'projects'))->toBeTrue()
        ->and($registry->isUsable($base + ['options' => ['link_detail_pages' => false]], 'projects'))->toBeTrue()
        ->and($registry->isUsable($base, 'projects'))->toBeTrue()
        ->and($registry->isUsable($base + ['options' => ['link_detail_pages' => 'yes']], 'projects'))->toBeFalse()
        ->and($registry->hardErrors($base + ['options' => ['link_detail_pages' => 'yes']], 'projects'))
        ->toContain('recipe.options.link_detail_pages has an invalid value');
});

it('keeps the listing byte-identical when link_detail_pages is absent or false even with detail pages', function () {
    [$site, $page] = makeLinkedGallerySite([
        ['title' => 'Loft conversion Wigan', 'leaf' => 'loft-conversion-wigan'],
        ['title' => 'Kitchen extension'],
    ]);

    $absent = renderGalleryPage($site, $page);

    LayoutPreset::factory()->active()->create([
        'site_id' => $site->id,
        'page_kind' => 'projects',
        'key' => 'link-details',
        'recipe' => galleryLinkRecipe(false),
    ]);
    $page->update(['layout_preset_key' => 'link-details']);

    $off = renderGalleryPage($site, $page);

    expect(app(PageLayoutRegistry::class)->isUsable(galleryLinkRecipe(false), 'projects'))->toBeTrue();

    expect($off)->toBe($absent)
        ->and($absent)->not->toContain('href="/projects/loft-conversion-wigan"')
        ->and($absent)->toContain('<article class="group relative overflow-hidden aspect-[4/5]"')
        ->and($absent)->toContain('group-hover:opacity-100')
        ->and($absent)->toContain('bg-black/65');
});

it('wraps only tiles whose detail page is published and not archived when the option is on', function () {
    [$site, $page] = makeLinkedGallerySite([
        ['title' => 'Linked loft', 'leaf' => 'loft-conversion-wigan'],
        ['title' => 'Unlinked kitchen'],
        ['title' => 'Draft loft', 'leaf' => 'draft-loft', 'page' => ['status' => PageStatus::Draft]],
        ['title' => 'Archived loft', 'leaf' => 'archived-loft', 'page' => ['status' => PageStatus::Archived]],
    ], galleryLinkRecipe(true));

    $html = renderGalleryPage($site, $page);

    expect($html)->toContain('href="/projects/loft-conversion-wigan"')
        ->and($html)->not->toContain('href="/projects/draft-loft"')
        ->and($html)->not->toContain('href="/projects/archived-loft"')
        ->and(substr_count($html, 'href="/projects/loft-conversion-wigan"'))->toBe(1)
        ->and($html)->toContain('Unlinked kitchen')
        ->and($html)->toContain('Draft loft')
        ->and($html)->toContain('Archived loft');

    expect($html)->toMatch('/<a href="\/projects\/loft-conversion-wigan"[^>]*>[\s\S]*Linked loft[\s\S]*<\/a>/')
        ->and($html)->not->toMatch('/<a href="\/projects\/[^"]+"[^>]*>[\s\S]*Unlinked kitchen[\s\S]*<\/a>/')
        ->and($html)->not->toMatch('/<a href="\/projects\/[^"]+"[^>]*>[\s\S]*Draft loft[\s\S]*<\/a>/')
        ->and($html)->not->toMatch('/<a href="\/projects\/[^"]+"[^>]*>[\s\S]*Archived loft[\s\S]*<\/a>/');
});

it('preserves the classic hover overlay on a linked tile', function () {
    [$site, $page] = makeLinkedGallerySite([
        ['title' => 'Linked loft', 'leaf' => 'loft-conversion-wigan'],
    ], galleryLinkRecipe(true));

    $html = renderGalleryPage($site, $page);

    expect($html)->toMatch(
        '/<a href="\/projects\/loft-conversion-wigan"[^>]*class="group relative overflow-hidden aspect-\[4\/5\][^"]*"[^>]*>[\s\S]*group-hover:opacity-100[\s\S]*bg-black\/65[\s\S]*<\/a>/',
    );
});

it('links filter-tabs tiles under the same option and leaves unlinked items as articles', function () {
    [$site, $page] = makeLinkedGallerySite([
        ['title' => 'Linked loft', 'leaf' => 'loft-conversion-wigan', 'category' => 'Lofts'],
        ['title' => 'Unlinked kitchen', 'category' => 'Kitchens'],
    ], galleryLinkRecipe(true, 'filter-tabs'), 'filter-tabs');

    $html = renderGalleryPage($site, $page);

    expect($html)->toContain('href="/projects/loft-conversion-wigan"')
        ->and($html)->toMatch('/<a href="\/projects\/loft-conversion-wigan"[^>]*data-cat="/')
        ->and($html)->toMatch('/<article data-cat="[^"]*"[^>]*>[\s\S]*Unlinked kitchen/')
        ->and($html)->not->toMatch('/<a href="\/projects\/[^"]+"[^>]*>[\s\S]*Unlinked kitchen[\s\S]*<\/a>/');
});

it('enumerates published children of the listing page in sort order and exposes them to gallery variants', function () {
    $site = Site::factory()->create();
    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'projects',
        'kind' => PageKind::Core,
    ]);
    $otherParent = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'about',
        'kind' => PageKind::Core,
    ]);

    $second = galleryDetailPage($site, $page, 'second', ['sort_order' => 2]);
    $first = galleryDetailPage($site, $page, 'first', ['sort_order' => 1]);
    galleryDetailPage($site, $page, 'draft-child', ['status' => PageStatus::Draft, 'sort_order' => 0]);
    galleryDetailPage($site, $page, 'archived-child', ['status' => PageStatus::Archived, 'sort_order' => 0]);
    galleryDetailPage($site, $otherParent, 'foreign', ['sort_order' => 0]);

    $children = app(ChildPageEnumerator::class)->forPage($site, $page);

    expect($children->pluck('id')->all())->toBe([$first->id, $second->id]);

    $captured = null;
    View::composer('site.sections.variants.project_gallery.classic', function ($view) use (&$captured): void {
        $captured = $view->offsetExists('childPages') ? $view['childPages'] : null;
    });

    [$renderSite, $renderPage] = makeLinkedGallerySite([
        ['title' => 'Linked loft', 'leaf' => 'loft-conversion-wigan'],
        ['title' => 'Unlinked kitchen'],
    ], galleryLinkRecipe(true));

    renderGalleryPage($renderSite, $renderPage);

    expect($captured)->not->toBeNull()
        ->and($captured->pluck('page_type')->all())->toBe(['projects/loft-conversion-wigan']);
});

it('does not link a bare scaffold with no extra substance even when published', function () {
    [$site, $page, $items] = makeLinkedGallerySite(
        [['leaf' => 'bare-scaffold', 'title' => 'Bare', 'page' => ['content_data' => ['sections' => [
            ['type' => 'project_detail_hero', 'title' => 'Bare'],
            ['type' => 'project_about', 'title' => 'Bare', 'body' => ''],
            ['type' => 'project_photo_essay', 'title' => 'Gallery', 'image_ids' => []],
        ]]]]],
        recipe: galleryLinkRecipe(true),
    );

    $html = renderGalleryPage($site, $page);

    expect($html)->not->toContain('href="/projects/bare-scaffold"');
});
