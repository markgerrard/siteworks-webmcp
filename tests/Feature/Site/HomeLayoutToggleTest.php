<?php

use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\HeroVideoVersion;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Models\SiteMedia;
use App\Services\Site\PageRenderer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('sites default to the classic home layout', function () {
    $site = Site::factory()->create();

    // DB default applied at insert; reload so the model reflects it.
    expect($site->fresh()->home_layout)->toBe('classic');
});

function makeHomePageForLayoutTest(array $sections, string $pageType = 'home'): array
{
    $site = Site::factory()->create(['business_name' => 'Acme Plumbing', 'theme' => 'trades-bold']);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => $pageType]);
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

    return [$site, $page];
}

it('classic home layout leaves rendered output unchanged', function () {
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'hero', 'title' => 'Welcome to Acme', 'subtitle' => 'Plumbing in Wigan'],
        ['type' => 'cta', 'title' => 'Ready to start?', 'button_label' => 'Get a quote'],
    ]);

    $before = app(PageRenderer::class)->render($site, $page->id, mode: 'public');
    $site->update(['home_layout' => 'classic']);
    $after = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($after)->toBe($before);
});

it('showcase leaves the cta on the dark-surface default', function () {
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'hero', 'title' => 'Welcome to Acme'],
        ['type' => 'cta', 'title' => 'Ready to start?', 'button_label' => 'Get a quote'],
    ]);
    $site->update(['home_layout' => 'showcase']);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    // Dark-surface + accent-button is the default everywhere — a full
    // accent wall crushes photography-led pages. The loud treatment is
    // opt-in per section, tested below.
    expect($html)->not->toContain('data-cta-variant="accent-band"');
});

it('an explicit accent-band variant still renders the loud cta treatment', function () {
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'hero', 'title' => 'Welcome to Acme'],
        ['type' => 'cta', 'title' => 'Ready to start?', 'button_label' => 'Get a quote', 'variant' => 'accent-band'],
    ]);
    $site->update(['home_layout' => 'showcase']);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('data-cta-variant="accent-band"');
});

it('service_area_card panel_side right renders the spacer and flipped scrim', function () {
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'service_area_card', 'title' => 'Servicing Wigan', 'panel_side' => 'right',
            'areas' => ['Wigan', 'Bolton', 'Leigh'], 'cta_label' => 'Check coverage'],
    ]);
    HeroVersion::create([
        'site_id' => $site->id, 'page_type' => 'home', 'slot' => 'intro',
        'is_active' => true, 'url' => 'https://cdn.example/intro-shot.jpg',
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('data-panel-side="right"')
        ->toContain('linear-gradient(270deg')
        ->not->toContain('linear-gradient(90deg');
});

it('service_area_card right mode renders exactly one spacer cell', function () {
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'service_area_card', 'title' => 'Servicing Wigan', 'panel_side' => 'right',
            'areas' => ['Wigan', 'Bolton', 'Leigh'], 'cta_label' => 'Check coverage'],
    ]);
    HeroVersion::create([
        'site_id' => $site->id, 'page_type' => 'home', 'slot' => 'intro',
        'is_active' => true, 'url' => 'https://cdn.example/intro-shot.jpg',
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    // Leading spacer only — both spacers would make 18 cols in a 12-col
    // grid and wrap the card onto a phantom second row.
    expect(substr_count($html, 'hidden lg:block lg:col-span-6'))->toBe(1);
});

it('service_area_card defaults to the left panel', function () {
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'service_area_card', 'title' => 'Servicing Wigan',
            'areas' => ['Wigan', 'Bolton', 'Leigh'], 'cta_label' => 'Check coverage'],
    ]);
    HeroVersion::create([
        'site_id' => $site->id, 'page_type' => 'home', 'slot' => 'intro',
        'is_active' => true, 'url' => 'https://cdn.example/intro-shot.jpg',
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->not->toContain('data-panel-side')
        ->toContain('linear-gradient(90deg');
});

it('an explicit section variant beats the preset', function () {
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'cta', 'title' => 'Ready to start?', 'variant' => 'someone-elses-choice'],
    ]);
    $site->update(['home_layout' => 'showcase']);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->not->toContain('data-cta-variant="accent-band"');
});

it('showcase does not touch non-home pages', function () {
    [$site, $page] = makeHomePageForLayoutTest(
        [['type' => 'cta', 'title' => 'Service CTA', 'button_label' => 'Call us']],
        pageType: 'emergency-plumbing-wigan',
    );
    $site->update(['home_layout' => 'showcase']);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->not->toContain('data-cta-variant="accent-band"');
});

/**
 * ProjectItem::factory()->gallery() leaves image_id null; the insert query
 * filters whereNotNull('image_id'), so tests must attach media explicitly.
 *
 * @return \Illuminate\Support\Collection<int, ProjectItem>
 */
function makeGalleryItemsWithImages(Site $site, GeneratedPage $projectsPage, int $count): \Illuminate\Support\Collection
{
    return collect(range(1, $count))->map(function (int $i) use ($site, $projectsPage) {
        $media = SiteMedia::factory()->create([
            'site_id' => $site->id,
            'url' => "https://test.example/project-{$i}.jpg",
        ]);

        // Public home insert only surfaces Published items; factory default is Draft.
        return ProjectItem::factory()->gallery()->published()->for($site)->create([
            'page_id' => $projectsPage->id,
            'title' => "Gallery project {$i}",
            'image_id' => $media->id,
        ]);
    });
}

it('showcase inserts a featured-projects dark band when the site has project items', function () {
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'hero', 'title' => 'Welcome to Acme'],
        ['type' => 'services', 'title' => 'What we do', 'items' => [
            ['title' => 'Boiler repair', 'body' => 'Fast fixes.', 'icon' => 'wrench'],
        ]],
        ['type' => 'cta', 'title' => 'Ready?', 'button_label' => 'Go'],
    ]);
    $projectsPage = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects']);
    $items = makeGalleryItemsWithImages($site, $projectsPage, 3);
    $site->update(['home_layout' => 'showcase']);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('data-portfolio-variant="dark-band"');
    expect($html)->toContain('var(--color-band)');
    expect($html)->toContain($items->first()->title);
    // Cache-buster matches project_gallery.blade.php (?v=<media_id>).
    expect($html)->toContain($items->first()->image->url.'?v='.$items->first()->image->id);
});

it('showcase does not insert portfolio_strip in admin-edit mode', function () {
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'hero', 'title' => 'Welcome to Acme'],
        ['type' => 'services', 'title' => 'What we do', 'items' => [
            ['title' => 'Boiler repair', 'body' => 'Fast fixes.', 'icon' => 'wrench'],
        ]],
        ['type' => 'cta', 'title' => 'Ready?', 'button_label' => 'Go'],
    ]);
    $projectsPage = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects']);
    makeGalleryItemsWithImages($site, $projectsPage, 3);
    $site->update(['home_layout' => 'showcase']);

    $adminHtml = app(PageRenderer::class)->render($site, $page->id, mode: 'admin-edit');
    $publicHtml = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    // Splice shifts section indices used by data-editable paths — skip in editor.
    expect($adminHtml)->not->toContain('data-portfolio-variant="dark-band"');
    // Public path still inserts (existing showcase insert tests also pin this).
    expect($publicHtml)->toContain('data-portfolio-variant="dark-band"');
});

it('showcase skips the featured-projects band when the site has no project items', function () {
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'hero', 'title' => 'Welcome to Acme'],
        ['type' => 'cta', 'title' => 'Ready?', 'button_label' => 'Go'],
    ]);
    $site->update(['home_layout' => 'showcase']);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->not->toContain('data-portfolio-variant="dark-band"');
});

it('showcase does not double-insert when a portfolio_strip already exists', function () {
    // Create site + items first so the pre-existing strip can reference
    // real item_ids (dark-band only emits the marker when band items resolve).
    $site = Site::factory()->create(['business_name' => 'Acme Plumbing', 'theme' => 'trades-bold']);
    $projectsPage = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects']);
    $items = makeGalleryItemsWithImages($site, $projectsPage, 2);

    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Welcome to Acme'],
            [
                'type' => 'portfolio_strip',
                'title' => 'Hand-picked work',
                'item_ids' => $items->pluck('id')->all(),
            ],
            ['type' => 'cta', 'title' => 'Ready?', 'button_label' => 'Go'],
        ]],
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

    $site->update(['home_layout' => 'showcase']);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect(substr_count($html, 'data-portfolio-variant="dark-band"'))->toBe(1);
});

it('showcase public home band excludes draft project items', function () {
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'hero', 'title' => 'Welcome to Acme'],
        ['type' => 'services', 'title' => 'What we do', 'items' => [
            ['title' => 'Boiler repair', 'body' => 'Fast fixes.', 'icon' => 'wrench'],
        ]],
        ['type' => 'cta', 'title' => 'Ready?', 'button_label' => 'Go'],
    ]);
    $projectsPage = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects']);

    $published = makeGalleryItemsWithImages($site, $projectsPage, 2);

    $draftMedia = SiteMedia::factory()->create([
        'site_id' => $site->id,
        'url' => 'https://test.example/draft-only.jpg',
    ]);
    $draft = ProjectItem::factory()->gallery()->for($site)->create([
        'page_id' => $projectsPage->id,
        'title' => 'Draft Only Secret Project',
        'image_id' => $draftMedia->id,
        'status' => \App\Enums\ProjectItemStatus::Draft,
    ]);

    $site->update(['home_layout' => 'showcase']);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('data-portfolio-variant="dark-band"');
    foreach ($published as $item) {
        expect($html)->toContain($item->title);
    }
    expect($html)->not->toContain($draft->title);
});

it('showcase admin-preview band shows draft-only items while public does not', function () {
    // Facet A: insert query is mode-aware — admin-preview hydrates non-archived
    // (Draft visible); public stays Published-only so draft-only sites get no band.
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'hero', 'title' => 'Welcome to Acme'],
        ['type' => 'services', 'title' => 'What we do', 'items' => [
            ['title' => 'Boiler repair', 'body' => 'Fast fixes.', 'icon' => 'wrench'],
        ]],
        ['type' => 'cta', 'title' => 'Ready?', 'button_label' => 'Go'],
    ]);
    $projectsPage = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects']);

    $draftMedia = SiteMedia::factory()->create([
        'site_id' => $site->id,
        'url' => 'https://test.example/draft-band.jpg',
    ]);
    $draft = ProjectItem::factory()->gallery()->for($site)->create([
        'page_id' => $projectsPage->id,
        'title' => 'Admin Preview Draft Band Item',
        'image_id' => $draftMedia->id,
        'status' => \App\Enums\ProjectItemStatus::Draft,
    ]);

    $site->update(['home_layout' => 'showcase']);

    $previewHtml = app(PageRenderer::class)->render($site, $page->id, mode: 'admin-preview');
    $publicHtml = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($previewHtml)->toContain('data-portfolio-variant="dark-band"');
    expect($previewHtml)->toContain($draft->title);
    expect($publicHtml)->not->toContain('data-portfolio-variant="dark-band"');
    expect($publicHtml)->not->toContain($draft->title);
});

it('showcase backfills empty pre-existing portfolio_strip item_ids publicly', function () {
    // Facet B: traditional_craftsman-style bare portfolio_strip (no item_ids)
    // + Showcase + published gallery items → dark-band renders those items.
    $site = Site::factory()->create(['business_name' => 'Acme Plumbing', 'theme' => 'trades-bold']);
    $projectsPage = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects']);
    $items = makeGalleryItemsWithImages($site, $projectsPage, 2);

    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Welcome to Acme'],
            ['type' => 'portfolio_strip', 'title' => 'Our craftsmanship'],
            ['type' => 'cta', 'title' => 'Ready?', 'button_label' => 'Go'],
        ]],
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

    $site->update(['home_layout' => 'showcase']);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('data-portfolio-variant="dark-band"');
    expect($html)->toContain($items->first()->title);
    expect($html)->toContain('var(--color-band)');
});

it('showcase leaves empty pre-existing portfolio_strip unstamped when site has no items', function () {
    // Facet B: zero items → do not stamp dark-band (preserve light strip self-gate).
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'hero', 'title' => 'Welcome to Acme'],
        ['type' => 'portfolio_strip', 'title' => 'Our craftsmanship'],
        ['type' => 'cta', 'title' => 'Ready?', 'button_label' => 'Go'],
    ]);
    $site->update(['home_layout' => 'showcase']);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    // No marker (dark-band blade self-gates on empty items) and strip stays
    // un-stamped so the light strip's self-gate is preserved.
    expect($html)->not->toContain('data-portfolio-variant="dark-band"');
});

it('public mode ignores explicit portfolio_strip item_ids from another site', function () {
    $otherSite = Site::factory()->create(['business_name' => 'Rival Roofing']);
    $otherProjects = GeneratedPage::factory()->for($otherSite)->create(['page_type' => 'projects']);
    $foreignMedia = SiteMedia::factory()->create([
        'site_id' => $otherSite->id,
        'url' => 'https://test.example/foreign-project.jpg',
    ]);
    $foreignItem = ProjectItem::factory()->gallery()->published()->for($otherSite)->create([
        'page_id' => $otherProjects->id,
        'title' => 'Foreign Site Secret Portfolio Item',
        'image_id' => $foreignMedia->id,
    ]);

    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'hero', 'title' => 'Welcome to Acme'],
        [
            'type' => 'portfolio_strip',
            'variant' => 'dark-band',
            'title' => 'Hand-picked work',
            'item_ids' => [$foreignItem->id],
        ],
        ['type' => 'cta', 'title' => 'Ready?', 'button_label' => 'Go'],
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->not->toContain($foreignItem->title);
});

it('public mode ignores same-site draft item_ids on an explicit portfolio_strip', function () {
    $site = Site::factory()->create(['business_name' => 'Acme Plumbing', 'theme' => 'trades-bold']);
    $projectsPage = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects']);
    $draftMedia = SiteMedia::factory()->create([
        'site_id' => $site->id,
        'url' => 'https://test.example/explicit-draft.jpg',
    ]);
    $draft = ProjectItem::factory()->gallery()->for($site)->create([
        'page_id' => $projectsPage->id,
        'title' => 'Explicit Draft Portfolio Leak',
        'image_id' => $draftMedia->id,
        'status' => \App\Enums\ProjectItemStatus::Draft,
    ]);

    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Welcome to Acme'],
            [
                'type' => 'portfolio_strip',
                'variant' => 'dark-band',
                'title' => 'Hand-picked work',
                'item_ids' => [$draft->id],
            ],
            ['type' => 'cta', 'title' => 'Ready?', 'button_label' => 'Go'],
        ]],
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

    expect($html)->not->toContain($draft->title);
});

it('showcase stacked insert stays in the home group and ignores non-home portfolio_strip', function () {
    $site = Site::factory()->create([
        'business_name' => 'Acme Plumbing',
        'theme' => 'trades-bold',
        'preview_layout' => \App\Enums\PreviewLayout::OnePage->value,
        'home_layout' => 'showcase',
    ]);

    $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $service = GeneratedPage::factory()->for($site)->create(['page_type' => 'emergency-plumbing-wigan']);
    $projectsPage = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects']);
    $items = makeGalleryItemsWithImages($site, $projectsPage, 2);

    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Home Hero Welcome', 'subtitle' => 'Acme Plumbing'],
            ['type' => 'services', 'title' => 'Home Services Block', 'items' => [
                ['title' => 'Boiler repair', 'body' => 'Fast fixes.', 'icon' => 'wrench'],
            ]],
            ['type' => 'cta', 'title' => 'Home CTA Ready', 'button_label' => 'Go'],
        ]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);

    // Service page has both a services anchor (would steal insertAt) and a
    // portfolio_strip (would suppress home insert if scans are unscoped).
    $serviceUnique = 'Service Page Unique Anchor Content';
    $serviceRev = PageRevision::factory()->for($service, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => $serviceUnique],
            ['type' => 'services', 'title' => 'Service Page Services', 'items' => [
                ['title' => 'Drain unblocking', 'body' => '24/7.', 'icon' => 'wrench'],
            ]],
            [
                'type' => 'portfolio_strip',
                'title' => 'Service Page Portfolio Strip',
                'item_ids' => $items->pluck('id')->all(),
            ],
        ]],
    ]);
    $service->update(['published_revision_id' => $serviceRev->id]);

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
            ['page_id' => $service->id, 'revision_id' => $serviceRev->id],
        ],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    $html = app(PageRenderer::class)->renderStacked($site, mode: 'public');

    expect($html)->toContain('data-portfolio-variant="dark-band"');

    $bandPos = strpos($html, 'data-portfolio-variant="dark-band"');
    $servicePos = strpos($html, $serviceUnique);

    expect($bandPos)->not->toBeFalse();
    expect($servicePos)->not->toBeFalse();
    // Band must land among home sections, before the service page group.
    expect($bandPos)->toBeLessThan($servicePos);
});

it('renders the hero boxed-left variant when stamped', function () {
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'hero', 'title' => 'Welcome to Acme', 'subtitle' => 'Plumbing in Wigan', 'variant' => 'boxed-left'],
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('data-hero-variant="boxed-left"');
    expect($html)->toContain('Welcome to Acme');
    expect($html)->toContain('max-width: 44rem');
});

it('boxed-left hero copy container uses min-height vh floor without fixed height', function () {
    // Fixed height: 55vh clips the taller color-mix panel (padding + max-w-44rem wrap).
    // boxed-left must emit min-height: <vh> only so the wrapper grows with the review.
    [$site, $page] = makeHomePageForLayoutTest([
        [
            'type' => 'hero',
            'title' => 'Welcome to Acme Plumbing Experts Serving Wigan',
            'subtitle' => 'Plumbing in Wigan with same-day callouts and Gas Safe engineers on every job',
            'variant' => 'boxed-left',
            'cta_label' => 'Get a quote',
        ],
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('data-hero-variant="boxed-left"');
    expect($html)->toContain('max-width: 44rem');

    // Target the copy-container style on the same relative z-[2] shell that
    // currently carries height/min-height (sibling of the boxed panel).
    expect(preg_match(
        '/class="relative z-\[2\][^"]*site-shell-container[^"]*overflow-hidden"\s+style="([^"]*)"/',
        $html,
        $m
    ))->toBe(1);

    $style = $m[1];
    expect($style)->toContain('min-height: 55vh');
    // No fixed height on this wrapper (min-height alone provides the floor).
    expect(preg_match('/(?:^|;)\s*height\s*:/', $style))->toBe(0);

    // Boxed-left uses tighter py-12 (all breakpoints); default path keeps py-28.
    expect(preg_match(
        '/class="relative z-\[2\][^"]*site-shell-container[^"]*\bpy-12\b[^"]*overflow-hidden"/',
        $html
    ))->toBe(1);
    expect($html)->not->toMatch('/class="relative z-\[2\][^"]*site-shell-container[^"]*\bpy-28\b/');
});

it('default hero copy container keeps fixed height style', function () {
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'hero', 'title' => 'Welcome to Acme', 'subtitle' => 'Plumbing in Wigan'],
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->not->toContain('data-hero-variant=');

    expect(preg_match(
        '/class="relative z-\[2\][^"]*site-shell-container[^"]*overflow-hidden"\s+style="([^"]*)"/',
        $html,
        $m
    ))->toBe(1);

    $style = $m[1];
    expect($style)->toContain('height: 55vh');
    expect($style)->toContain('min-height: 280px');

    // Default path keeps existing py-28 md:py-36 lg:py-40 (byte-identical).
    expect(preg_match(
        '/class="relative z-\[2\][^"]*site-shell-container[^"]*\bpy-28\b[^"]*\bmd:py-36\b[^"]*\blg:py-40\b[^"]*overflow-hidden"/',
        $html
    ))->toBe(1);
    expect($html)->not->toMatch('/class="relative z-\[2\][^"]*site-shell-container[^"]*\bpy-12\b/');
});

it('boxed-left hero does not combine items-center with items-start on flex rows', function () {
    // $flexJustifyClass must be a main-axis (justify-*) reset, not items-start.
    // Combining items-center + items-start breaks eyebrow rule, trust pills, and CTA stretch.
    // Eyebrow + trust_signals required so those flex rows (which hardcode items-center)
    // actually emit and would collide with a bad flexJustifyClass value.
    [$site, $page] = makeHomePageForLayoutTest([
        [
            'type' => 'hero',
            'title' => 'Welcome to Acme',
            'subtitle' => 'Plumbing in Wigan',
            'variant' => 'boxed-left',
            'eyebrow' => 'Local experts',
            'trust_signals' => ['Gas Safe', '5-star reviews'],
            'cta_label' => 'Get a quote',
        ],
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('data-hero-variant="boxed-left"');
    expect(preg_match('/class="[^"]*items-center[^"]*items-start[^"]*"/', $html))->toBe(0);
    expect(preg_match('/class="[^"]*items-start[^"]*items-center[^"]*"/', $html))->toBe(0);
});

it('default hero has no variant marker', function () {
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'hero', 'title' => 'Welcome to Acme'],
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->not->toContain('data-hero-variant=');
});

it('photo-cards variant shows dedicated service hero images per card', function () {
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'services', 'title' => 'What we do', 'variant' => 'photo-cards', 'items' => [
            ['title' => 'Boiler repair', 'body' => 'Fast fixes.', 'icon' => 'wrench', 'source_service' => 'Boiler Repair'],
            ['title' => 'Bathrooms', 'body' => 'Full refits.', 'icon' => 'bath', 'source_service' => 'Bathroom Fitting'],
        ]],
    ]);
    $site->update(['location' => 'Wigan']);

    foreach ([['Boiler Repair', 'https://cdn.example/hero-boiler.jpg'], ['Bathroom Fitting', 'https://cdn.example/hero-bath.jpg']] as [$service, $url]) {
        $slug = Str::slug($service.'-Wigan');
        GeneratedPage::factory()->for($site)->create(['page_type' => $slug, 'hero_source' => 'dedicated']);
        HeroVersion::create([
            'site_id' => $site->id, 'page_type' => $slug, 'slot' => 'hero',
            'is_active' => true, 'url' => $url,
        ]);
    }

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('https://cdn.example/hero-boiler.jpg');
    expect($html)->toContain('https://cdn.example/hero-bath.jpg');
    expect($html)->toContain('data-service-card-photo');
});

it('photo-cards prefer watermark_url when watermark_enabled is true', function () {
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'services', 'title' => 'What we do', 'variant' => 'photo-cards', 'items' => [
            ['title' => 'Boiler repair', 'body' => 'Fast fixes.', 'icon' => 'wrench', 'source_service' => 'Boiler Repair'],
        ]],
    ]);
    $site->update(['location' => 'Wigan']);

    $slug = Str::slug('Boiler Repair-Wigan');
    GeneratedPage::factory()->for($site)->create(['page_type' => $slug, 'hero_source' => 'dedicated']);
    HeroVersion::create([
        'site_id' => $site->id,
        'page_type' => $slug,
        'slot' => 'hero',
        'is_active' => true,
        'url' => 'https://cdn.example/hero-boiler-clean.jpg',
        'watermark_url' => 'https://cdn.example/hero-boiler-wm.jpg',
    ]);

    // watermark_enabled defaults TRUE when absent from profile (canonical hero path).
    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('https://cdn.example/hero-boiler-wm.jpg');
    expect($html)->not->toContain('https://cdn.example/hero-boiler-clean.jpg');
    expect($html)->toContain('data-service-card-photo');
});

it('photo-cards falls back to icons when service images are shared duplicates', function () {
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'services', 'title' => 'What we do', 'variant' => 'photo-cards', 'items' => [
            ['title' => 'Boiler repair', 'body' => 'Fast fixes.', 'icon' => 'wrench', 'source_service' => 'Boiler Repair'],
            ['title' => 'Bathrooms', 'body' => 'Full refits.', 'icon' => 'bath', 'source_service' => 'Bathroom Fitting'],
        ]],
    ]);
    $site->update(['location' => 'Wigan']);

    HeroVersion::create([
        'site_id' => $site->id, 'page_type' => '__shared_service_hero', 'slot' => 'hero',
        'is_active' => true, 'url' => 'https://cdn.example/hero-shared.jpg',
    ]);
    foreach (['Boiler Repair', 'Bathroom Fitting'] as $service) {
        GeneratedPage::factory()->for($site)->create(['page_type' => Str::slug($service.'-Wigan'), 'hero_source' => 'shared']);
    }

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->not->toContain('data-service-card-photo');
});

it('default services cards render no photo markup', function () {
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'services', 'title' => 'What we do', 'items' => [
            ['title' => 'Boiler repair', 'body' => 'Fast fixes.', 'icon' => 'wrench'],
        ]],
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->not->toContain('data-service-card-photo');
});

it('reviews grid variant renders a static grid instead of the carousel', function () {
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'reviews_summary', 'heading' => 'What clients say', 'variant' => 'grid', 'max_items' => 4],
    ]);
    $site->update(['reviews_cache' => [
        'rating' => 4.8, 'user_ratings_total' => 27, 'url' => 'https://maps.example/acme',
        'reviews' => [
            ['author_name' => 'Jo B', 'rating' => 5, 'text' => 'Superb work, tidy and quick.', 'relative_time_description' => 'a month ago'],
            ['author_name' => 'Sam K', 'rating' => 5, 'text' => 'Great communication throughout.', 'relative_time_description' => '2 months ago'],
        ],
    ]]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('data-reviews-variant="grid"');
    expect($html)->toContain('Superb work, tidy and quick.');
    expect($html)->not->toContain('reviews-carousel-');
});

it('reviews summary defaults to the carousel', function () {
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'reviews_summary', 'heading' => 'What clients say'],
    ]);
    $site->update(['reviews_cache' => [
        'rating' => 4.8, 'user_ratings_total' => 27,
        'reviews' => [
            ['author_name' => 'Jo B', 'rating' => 5, 'text' => 'Superb work, tidy and quick.'],
        ],
    ]]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('reviews-carousel-');
    expect($html)->not->toContain('data-reviews-variant=');
});

it('cta accent-band variant renders the band in the accent colour', function () {
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'cta', 'title' => 'Get an instant quote', 'button_label' => 'Get quote', 'variant' => 'accent-band'],
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('data-cta-variant="accent-band"');
    // Target the band wrapper (style precedes the marker attribute), not the button's accent fill.
    expect($html)->toContain('style="background-color: var(--brand-accent);" data-cta-variant="accent-band"');
    // Inverted primary fill on the button (accent-on-accent mitigation).
    expect($html)->toContain('background-color: var(--brand-primary)');
    // 1px contrast ring so the button keeps an edge when primary ≈ accent.
    expect($html)->toContain('0 0 0 1px var(--color-text-on-accent');
});

it('a showcase site composes all five variants end to end', function () {
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'hero', 'title' => 'Welcome to Acme', 'subtitle' => 'Plumbing in Wigan'],
        ['type' => 'services', 'title' => 'What we do', 'items' => [
            ['title' => 'Boiler repair', 'body' => 'Fast fixes.', 'icon' => 'wrench'],
        ]],
        ['type' => 'reviews_summary', 'heading' => 'What clients say'],
        ['type' => 'cta', 'title' => 'Get an instant quote', 'button_label' => 'Get quote'],
    ]);
    $site->update([
        'home_layout' => 'showcase',
        'reviews_cache' => [
            'rating' => 4.9, 'user_ratings_total' => 12,
            'reviews' => [['author_name' => 'Jo B', 'rating' => 5, 'text' => 'Superb work.']],
        ],
    ]);
    $projectsPage = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects']);
    // Published + image_id required for the dark-band insert (factory default is draft/null).
    makeGalleryItemsWithImages($site, $projectsPage, 3);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('data-hero-variant="boxed-left"');
    expect($html)->toContain('data-portfolio-variant="dark-band"');
    expect($html)->toContain('data-reviews-variant="grid"');
    // cta stays on the dark-surface default — Showcase no longer stamps
    // accent-band (loud treatment is per-section opt-in).
    expect($html)->not->toContain('data-cta-variant="accent-band"');
    // services photo-cards degrade to icons here (no service hero images
    // seeded) — that fallback IS the assertion:
    expect($html)->not->toContain('data-service-card-photo');
});

/**
 * Attach a non-legacy kind=video home_hero_scene with a resolvable composite
 * HeroVideoVersion so PageRenderer takes the _hero_scene path (not legacy
 * single-asset). home_hero_video_enabled stays off so HeroSceneService::resolve
 * hydrates the stored scene rather than deriveLegacyScene.
 *
 * @param  array<string, mixed>  $slideOverlay  Per-slide copy overrides
 * @param  array<string, mixed>  $sceneMeta  Top-level scene keys (height, panel_width, …)
 */
function attachCompositeVideoHeroScene(Site $site, array $slideOverlay = [], array $sceneMeta = []): HeroVideoVersion
{
    // HeroVideoVersion::url() hits the s3 disk; fake it so tests don't need AWS region/credentials.
    Storage::fake('s3');

    $clip = HeroVideoVersion::create([
        'site_id' => $site->id,
        's3_key' => 'tests/scene-clip-'.$site->id.'.mp4',
        'prompt' => 'clip',
        'provider' => 'test',
        'resolution' => '720p',
        'duration_secs' => 4,

        'source' => 'ai_generated',
        'metadata' => [],
        'is_active' => false,
    ]);
    $composite = HeroVideoVersion::create([
        'site_id' => $site->id,
        's3_key' => 'tests/scene-composite-'.$site->id.'.mp4',
        'prompt' => 'composite',
        'provider' => 'test',
        'resolution' => '720p',
        'duration_secs' => 8,

        'source' => 'composite',
        'metadata' => [],
        'is_active' => true,
    ]);

    $site->update([
        'home_hero_video_enabled' => false,
        'home_hero_scene' => array_merge([
            'kind' => 'video',
            'slides' => [[
                'asset_type' => 'hero_video_version',
                'asset_id' => $clip->id,
                'heading' => $slideOverlay['heading'] ?? 'Scene heading',
                'subheading' => $slideOverlay['subheading'] ?? 'Scene subheading',
                'cta_label' => $slideOverlay['cta_label'] ?? 'Get a quote',
                'text_zone' => 'middle-left',
                'text_color' => 'white',
                'overlay_strength' => 'medium',
                'dwell_secs' => 6,
            ]],
            'transitions' => [['type' => 'fade', 'duration_secs' => 1.0]],
            'composite_video_id' => $composite->id,
        ], $sceneMeta),
    ]);

    return $composite;
}

it('showcase scene video hero composes boxed-left panel over the composite', function () {
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'hero', 'title' => 'Welcome to Acme', 'subtitle' => 'Plumbing in Wigan', 'cta_label' => 'Get a quote'],
    ]);
    attachCompositeVideoHeroScene($site);
    $site->update(['home_layout' => 'showcase']);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($html)->toContain('<video');
    expect($html)->toContain('data-hero-variant="boxed-left"');
    expect($html)->toContain('max-width: 44rem');
    expect($html)->toContain('color-mix(in srgb, var(--brand-primary) 78%, transparent)');

    // Scene shell uses min-height: 68vh only (taller floor for panel breathing
    // room @ 1440x900; no fixed height so content can still grow).
    expect(preg_match(
        '/class="relative z-\[2\][^"]*site-shell-container[^"]*"\s+style="([^"]*)"/',
        $html,
        $m
    ))->toBe(1);
    $style = $m[1];
    expect($style)->toContain('min-height: 68vh');
    expect(preg_match('/(?:^|;)\s*height\s*:/', $style))->toBe(0);
});

it('boxed scene overlay panel is in-flow so the shell can grow past 68vh', function () {
    // Absolute top:0;bottom:0 slide wrappers contribute no height to the
    // min-height shell, so long boxed copy clips under overflow-hidden.
    // Boxed path must emit an in-flow overlay (data-hero-overlay-flow) and
    // must not nest the review under absolute+top/bottom stretch.
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'hero', 'title' => 'Welcome to Acme', 'subtitle' => 'Plumbing in Wigan', 'cta_label' => 'Get a quote'],
    ]);
    attachCompositeVideoHeroScene($site);
    $site->update(['home_layout' => 'showcase']);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($html)->toContain('data-hero-variant="boxed-left"');
    expect($html)->toContain('data-hero-overlay-flow');
    expect($html)->toContain('min-height: 68vh');

    // Isolate the shell → panel wrapper chain and reject absolute stretch.
    expect(preg_match(
        '/data-hero-overlay-flow[\s\S]{0,1200}?data-hero-variant="boxed-left"/',
        $html
    ))->toBe(1);

    $overlayChunk = null;
    if (preg_match(
        '/(<[^>]*data-hero-overlay-flow[^>]*>[\s\S]*?data-hero-variant="boxed-left")/',
        $html,
        $chunk
    )) {
        $overlayChunk = $chunk[1];
    }
    expect($overlayChunk)->not->toBeNull();
    expect(preg_match(
        '/\babsolute\b[\s\S]{0,200}(?:top\s*:\s*0|top-0)[\s\S]{0,200}(?:bottom\s*:\s*0|bottom-0)/',
        $overlayChunk
    ))->toBe(0);
});

it('scene video hero without showcase has no boxed-left marker', function () {
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'hero', 'title' => 'Welcome to Acme', 'subtitle' => 'Plumbing in Wigan', 'cta_label' => 'Get a quote'],
    ]);
    attachCompositeVideoHeroScene($site);
    // Classic (default) — scene mechanics only, no layout-preset panel.

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($html)->toContain('<video');
    expect($html)->not->toContain('data-hero-variant=');
    expect($html)->not->toContain('data-hero-variant="boxed-left"');
    expect($html)->not->toContain('data-hero-overlay-flow');

    // Non-boxed scene keeps the default 55vh fixed height + 280px min floor.
    expect(preg_match(
        '/class="relative z-\[2\][^"]*site-shell-container[^"]*"\s+style="([^"]*)"/',
        $html,
        $m
    ))->toBe(1);
    $style = $m[1];
    expect($style)->toContain('height: 55vh');
    expect($style)->toContain('min-height: 280px');
    expect($style)->not->toContain('68vh');
});

it('scene height override from home_hero_scene renders into the shell', function () {
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'hero', 'title' => 'Welcome to Acme', 'subtitle' => 'Plumbing in Wigan', 'cta_label' => 'Get a quote'],
    ]);
    attachCompositeVideoHeroScene($site, sceneMeta: ['height' => '75vh']);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect(preg_match(
        '/class="relative z-\[2\][^"]*site-shell-container[^"]*"\s+style="([^"]*)"/',
        $html,
        $m
    ))->toBe(1);
    $style = $m[1];
    expect($style)->toContain('height: 75vh');
    expect($style)->toContain('min-height: 280px');
    expect($style)->not->toContain('55vh');
});

it('boxed scene panel_width override renders into the review max-width', function () {
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'hero', 'title' => 'Welcome to Acme', 'subtitle' => 'Plumbing in Wigan', 'cta_label' => 'Get a quote'],
    ]);
    attachCompositeVideoHeroScene($site, sceneMeta: ['panel_width' => '38rem']);
    $site->update(['home_layout' => 'showcase']);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($html)->toContain('data-hero-variant="boxed-left"');
    expect($html)->toContain('max-width: 38rem');
    expect($html)->not->toContain('max-width: 44rem');
});

it('rejects malicious scene height and falls back to default', function () {
    $malicious = '55vh;background:url(x)';
    [$site, $page] = makeHomePageForLayoutTest([
        ['type' => 'hero', 'title' => 'Welcome to Acme', 'subtitle' => 'Plumbing in Wigan', 'cta_label' => 'Get a quote'],
    ]);
    attachCompositeVideoHeroScene($site, sceneMeta: ['height' => $malicious]);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');

    expect($html)->not->toContain($malicious);
    expect($html)->not->toContain('background:url(x)');

    expect(preg_match(
        '/class="relative z-\[2\][^"]*site-shell-container[^"]*"\s+style="([^"]*)"/',
        $html,
        $m
    ))->toBe(1);
    $style = $m[1];
    expect($style)->toContain('height: 55vh');
    expect($style)->toContain('min-height: 280px');
});
