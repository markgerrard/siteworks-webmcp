<?php

use App\Enums\PageKind;
use App\Enums\PageOrigin;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use Illuminate\Support\Facades\DB;

/**
 * @param  array<string, mixed>  $footer
 * @param  array<int, array{page: GeneratedPage, revision: PageRevision, pin?: bool}>  $pages
 */
function footerColumnsPublish(Site $site, GeneratedPage $home, PageRevision $homeRev, array $pages, array $footer): SiteVersion
{
    $pins = [['page_id' => $home->id, 'revision_id' => $homeRev->id]];

    foreach ($pages as $entry) {
        if (($entry['pin'] ?? true) === false) {
            continue;
        }

        $pins[] = [
            'page_id' => $entry['page']->id,
            'revision_id' => $entry['revision']->id,
        ];
    }

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => $footer,
            'theme' => ['key' => 'trades-bold'],
            'homepage_page_id' => $home->id,
        ],
        'page_revisions' => $pins,
        'published_at' => now(),
    ]);

    SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);

    return $version;
}

/**
 * @param  array<string, mixed>  $attrs
 * @return array{page: GeneratedPage, revision: PageRevision}
 */
function footerColumnsMakePage(Site $site, array $attrs = []): array
{
    $page = GeneratedPage::factory()->for($site)->create(array_merge([
        'status' => PageStatus::Published,
        'kind' => PageKind::Guide,
        'origin' => PageOrigin::Managed,
    ], $attrs));

    $revision = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => $page->nav_label ?: $page->page_type],
        ]],
    ]);
    $page->update(['published_revision_id' => $revision->id]);

    return ['page' => $page->fresh(), 'revision' => $revision];
}

function footerColumnsExtractFooter(string $html): string
{
    expect($html)->toMatch('/<footer\b/i');

    preg_match('/<footer\b.*<\/footer>/is', $html, $matches);

    return $matches[0];
}

test('footer column renders only the pinned page_id with its current nav_label and url', function () {
    $site = Site::factory()->create(['business_name' => 'Acme', 'theme' => 'trades-bold']);
    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
        'nav_label' => 'Home',
        'status' => PageStatus::Published,
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Welcome']]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);

    $pinned = footerColumnsMakePage($site, [
        'page_type' => 'planning-permission-guide',
        'nav_label' => 'Planning Permission Guide',
    ]);
    $unpinned = footerColumnsMakePage($site, [
        'page_type' => 'ghost-loft-guide',
        'nav_label' => 'Ghost Loft Guide',
    ]);

    footerColumnsPublish($site, $home, $homeRev, [
        [...$pinned, 'pin' => true],
        [...$unpinned, 'pin' => false],
    ], [
        'columns' => [[
            'title' => 'Guides & Advice',
            'items' => [
                ['page_id' => $pinned['page']->id],
                ['page_id' => $unpinned['page']->id],
            ],
        ]],
        'show_credit' => true,
    ]);

    $html = app(PageRenderer::class)->render($site, $home->id, mode: 'public');
    $footer = footerColumnsExtractFooter($html);

    expect($footer)->toContain('data-footer-column="Guides &amp; Advice"')
        ->and($footer)->toContain('Planning Permission Guide')
        ->and($footer)->toContain('href="/planning-permission-guide"')
        ->and($footer)->not->toContain('Ghost Loft Guide')
        ->and($footer)->not->toContain('href="/ghost-loft-guide"');
});

test('footer silently skips an unpinned page_id and never emits its link', function () {
    $site = Site::factory()->create(['business_name' => 'Acme', 'theme' => 'trades-bold']);
    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
        'nav_label' => 'Home',
        'status' => PageStatus::Published,
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Welcome']]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);

    $unpinned = footerColumnsMakePage($site, [
        'page_type' => 'unpublished-advice',
        'nav_label' => 'Unpinned Advice Page',
    ]);

    footerColumnsPublish($site, $home, $homeRev, [
        [...$unpinned, 'pin' => false],
    ], [
        'columns' => [[
            'title' => 'Guides & Advice',
            'items' => [
                ['page_id' => $unpinned['page']->id],
            ],
        ]],
        'show_credit' => true,
    ]);

    $html = app(PageRenderer::class)->render($site, $home->id, mode: 'public');
    $footer = footerColumnsExtractFooter($html);

    expect($footer)->not->toContain('Unpinned Advice Page')
        ->and($footer)->not->toContain('href="/unpublished-advice"')
        ->and($footer)->not->toContain('data-footer-column="Guides &amp; Advice"');
});

test('empty or absent footer columns render a byte-identical footer to the no-columns fixture', function () {
    $render = function (array $footer): string {
        $site = Site::factory()->create(['business_name' => 'Acme', 'theme' => 'trades-bold']);
        $home = GeneratedPage::factory()->for($site)->create([
            'page_type' => 'home',
            'kind' => PageKind::Core,
            'nav_label' => 'Home',
            'status' => PageStatus::Published,
        ]);
        $homeRev = PageRevision::factory()->for($home, 'page')->create([
            'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Welcome']]],
        ]);
        $home->update(['published_revision_id' => $homeRev->id]);

        footerColumnsPublish($site, $home, $homeRev, [], $footer);

        return footerColumnsExtractFooter(
            app(PageRenderer::class)->render($site, $home->id, mode: 'public'),
        );
    };

    $fixture = $render(['show_credit' => true]);
    $empty = $render(['columns' => [], 'show_credit' => true]);
    $absent = $render(['show_credit' => true]);

    expect($empty)->toBe($fixture)
        ->and($absent)->toBe($fixture)
        ->and($fixture)->not->toContain('data-footer-column=');
});

test('render with empty footer columns and no related sections skips the pinned-revision whereIn', function () {
    $site = Site::factory()->create(['business_name' => 'Acme', 'theme' => 'trades-bold']);
    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
        'nav_label' => 'Home',
        'status' => PageStatus::Published,
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Welcome']]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);

    $extra = footerColumnsMakePage($site, [
        'page_type' => 'planning-permission-guide',
        'nav_label' => 'Planning Permission Guide',
    ]);

    footerColumnsPublish($site, $home, $homeRev, [
        [...$extra, 'pin' => true],
    ], [
        'columns' => [],
        'show_credit' => true,
    ]);

    $whereInQueries = [];
    DB::listen(function (object $query) use (&$whereInQueries): void {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'generated_page_revisions') && preg_match('/\bin\s*\(/', $sql) === 1) {
            $whereInQueries[] = $query->sql;
        }
    });

    app(PageRenderer::class)->render($site, $home->id, mode: 'public');

    expect($whereInQueries)->toBe([]);
});

test('resolvePinnedPages ignores a memoised version from another site', function () {
    $siteA = Site::factory()->create(['business_name' => 'Alpha', 'theme' => 'trades-bold']);
    $homeA = GeneratedPage::factory()->for($siteA)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
        'nav_label' => 'Home',
        'status' => PageStatus::Published,
    ]);
    $homeARev = PageRevision::factory()->for($homeA, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Alpha home']]],
    ]);
    $homeA->update(['published_revision_id' => $homeARev->id]);
    $guideA = footerColumnsMakePage($siteA, [
        'page_type' => 'alpha-secret-guide',
        'nav_label' => 'Alpha Secret Guide',
    ]);
    footerColumnsPublish($siteA, $homeA, $homeARev, [
        [...$guideA, 'pin' => true],
    ], [
        'columns' => [[
            'title' => 'Guides',
            'items' => [['page_id' => $guideA['page']->id]],
        ]],
        'show_credit' => true,
    ]);

    $siteB = Site::factory()->create(['business_name' => 'Beta', 'theme' => 'trades-bold']);
    $homeB = GeneratedPage::factory()->for($siteB)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
        'nav_label' => 'Home',
        'status' => PageStatus::Published,
    ]);
    $homeBRev = PageRevision::factory()->for($homeB, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Beta home']]],
    ]);
    $homeB->update(['published_revision_id' => $homeBRev->id]);
    $guideB = footerColumnsMakePage($siteB, [
        'page_type' => 'beta-public-guide',
        'nav_label' => 'Beta Public Guide',
    ]);
    footerColumnsPublish($siteB, $homeB, $homeBRev, [
        [...$guideB, 'pin' => true],
    ], [
        'columns' => [[
            'title' => 'Guides',
            'items' => [['page_id' => $guideB['page']->id]],
        ]],
        'show_credit' => true,
    ]);

    SiteDraft::query()->updateOrCreate(
        ['site_id' => $siteB->id],
        [
            'composition' => [
                'nav' => ['items' => []],
                'footer' => [
                    'columns' => [[
                        'title' => 'Guides',
                        'items' => [['page_id' => $guideB['page']->id]],
                    ]],
                    'show_credit' => true,
                ],
                'theme' => ['key' => 'trades-bold'],
                'homepage_page_id' => $homeB->id,
            ],
            'updated_at' => now(),
        ],
    );

    $renderer = app(PageRenderer::class);
    $renderer->render($siteA, $homeA->id, mode: 'public');
    $html = $renderer->render($siteB, $homeB->id, mode: 'admin-preview');

    expect($html)->toContain('Beta Public Guide')
        ->and($html)->not->toContain('Alpha Secret Guide');
});

test('footer columns cap resolved links at 8', function () {
    $site = Site::factory()->create(['business_name' => 'Acme', 'theme' => 'trades-bold']);
    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
        'nav_label' => 'Home',
        'status' => PageStatus::Published,
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Welcome']]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);

    $pages = [];
    $items = [];
    foreach (range(1, 10) as $n) {
        $slug = sprintf('covered-town-%02d', $n);
        $label = sprintf('Covered Town %02d', $n);
        $pages[] = footerColumnsMakePage($site, [
            'page_type' => $slug,
            'nav_label' => $label,
            'kind' => PageKind::Service,
            'origin' => PageOrigin::Managed,
        ]);
        $items[] = ['page_id' => $pages[$n - 1]['page']->id];
    }

    footerColumnsPublish($site, $home, $homeRev, $pages, [
        'columns' => [[
            'title' => 'Areas We Cover',
            'items' => $items,
        ]],
        'show_credit' => true,
    ]);

    $html = app(PageRenderer::class)->render($site, $home->id, mode: 'public');
    $footer = footerColumnsExtractFooter($html);

    expect($footer)->toContain('data-footer-column="Areas We Cover"');

    foreach (range(1, 8) as $n) {
        expect($footer)->toContain(sprintf('Covered Town %02d', $n))
            ->and($footer)->toContain(sprintf('href="/covered-town-%02d"', $n));
    }

    expect($footer)->not->toContain('Covered Town 09')
        ->and($footer)->not->toContain('Covered Town 10')
        ->and($footer)->not->toContain('href="/covered-town-09"')
        ->and($footer)->not->toContain('href="/covered-town-10"');
});
