<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\GeneratedPage;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\PageRenderer;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function categoryRailCategory(string $slug, string $name, array $overrides = []): array
{
    $path = $overrides['path'] ?? $slug;

    return array_merge([
        'slug' => $slug,
        'name' => $name,
        'path' => $path,
        'hero_image_url' => null,
        'children' => [],
        'breadcrumb' => [['name' => $name, 'path' => $path]],
    ], $overrides);
}

/**
 * @param  list<array<string, mixed>>  $products
 * @param  array<string, array<string, mixed>>  $categories
 * @return array<string, mixed>
 */
function categoryRailSnapshotJson(array $products, array $categories = []): array
{
    $bySlug = [];
    foreach ($products as $product) {
        $bySlug[$product['slug']] = [
            'id' => $product['id'],
            'slug' => $product['slug'],
            'status' => $product['status'] ?? 'published',
            'primary_category_slug' => $product['primary_category_slug'] ?? null,
            'price_cents' => $product['price_cents'] ?? 4500,
            'price_display' => $product['price_display'] ?? '£45.00',
            'in_stock_any' => true,
            'image_urls' => $product['image_urls'] ?? ['card' => '/'.$product['slug'].'-card.jpg'],
            'product_card' => [
                'slug' => $product['slug'],
                'name' => $product['name'],
                'price_display' => $product['price_display'] ?? '£45.00',
            ],
        ];
    }

    return [
        'meta' => ['product_count' => count($bySlug)],
        'categories' => $categories,
        'products' => $bySlug,
        'featured_slugs' => [],
    ];
}

/**
 * @param  array<string, mixed>  $section
 * @param  array<string, mixed>  $snapshot
 * @return array{0: Site, 1: GeneratedPage}
 */
function seedCategoryRailSite(array $section = [], array $snapshot = []): array
{
    $site = Site::factory()->create([
        'business_name' => 'Camino',
        'theme' => 'trades-bold',
        'shop_mode' => 'cart',
        'custom_domain' => 'catrail-'.bin2hex(random_bytes(6)).'.example',
        'custom_domain_status' => 'active',
    ]);

    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Welcome'],
            array_merge([
                'type' => 'category_rail',
                'title' => 'Shop by occasion',
                'subtitle' => 'Flowers for every moment',
                'slugs' => [],
                'limit' => 8,
            ], $section),
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

    if ($snapshot !== []) {
        $snapshot['meta']['site_id'] = $site->id;
        $row = ShopSnapshot::create([
            'site_id' => $site->id,
            'version' => 1,
            'status' => ShopSnapshotStatus::Success,
            'json' => $snapshot,
            'built_at' => now(),
        ]);
        ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $row->id, 'updated_at' => now()]);
    }

    return [$site->fresh(), $page->fresh()];
}

function categoryRailHtml(string $html): string
{
    if (preg_match('/<ul\b[^>]*data-category-rail\b[^>]*>.*?<\/ul>/s', $html, $list)) {
        $start = strpos($html, 'data-category-rail');
        if ($start === false) {
            return $list[0];
        }
        $prefix = substr($html, 0, $start);
        $open = strrpos($prefix, '<div');
        $end = strpos($html, $list[0]);
        if ($open === false || $end === false) {
            return $list[0];
        }

        return substr($html, $open, $end + strlen($list[0]) - $open);
    }

    return '';
}

/**
 * @return list<string>
 */
function categoryRailHrefs(string $html): array
{
    preg_match_all('#href="(/collections/[^"]+)"#', categoryRailHtml($html), $matches);

    return $matches[1];
}

function threeOccasionCategories(): array
{
    return [
        'birthday' => categoryRailCategory('birthday', 'Birthday', [
            'hero_image_url' => '/heroes/birthday.jpg',
            'children' => ['roses'],
        ]),
        'sympathy' => categoryRailCategory('sympathy', 'Sympathy', [
            'hero_image_url' => '/heroes/sympathy.jpg',
        ]),
        'thank-you' => categoryRailCategory('thank-you', 'Thank you', [
            'hero_image_url' => '/heroes/thank-you.jpg',
        ]),
        'roses' => categoryRailCategory('roses', 'Roses', [
            'path' => 'birthday/roses',
            'hero_image_url' => '/heroes/roses.jpg',
            'breadcrumb' => [
                ['name' => 'Birthday', 'path' => 'birthday'],
                ['name' => 'Roses', 'path' => 'birthday/roses'],
            ],
        ]),
    ];
}

test('renders one tile per top-level category with collection href, name, and hero image', function () {
    [$site, $page] = seedCategoryRailSite(
        snapshot: categoryRailSnapshotJson([], threeOccasionCategories()),
    );

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    $rail = categoryRailHtml($html);

    expect($html)->toContain('data-category-rail')
        ->and($rail)->toContain('Shop by occasion')
        ->and($rail)->toContain('Flowers for every moment')
        ->and($rail)->toContain('Birthday')
        ->and($rail)->toContain('Sympathy')
        ->and($rail)->toContain('Thank you')
        ->and($rail)->toContain('/heroes/birthday.jpg')
        ->and($rail)->toContain('/heroes/sympathy.jpg')
        ->and($rail)->toContain('/heroes/thank-you.jpg')
        ->and($rail)->not->toContain('Roses')
        ->and($rail)->not->toContain('/heroes/roses.jpg')
        ->and(categoryRailHrefs($html))->toBe([
            '/collections/birthday',
            '/collections/sympathy',
            '/collections/thank-you',
        ]);
});

test('falls back to the first published product card image, then a placeholder, and never a draft product', function () {
    [$site, $page] = seedCategoryRailSite(
        snapshot: categoryRailSnapshotJson([
            [
                'id' => 1,
                'slug' => 'draft-wreath',
                'name' => 'Draft wreath',
                'status' => 'draft',
                'primary_category_slug' => 'sympathy',
                'image_urls' => ['card' => '/draft-wreath-card.jpg'],
            ],
            [
                'id' => 2,
                'slug' => 'birthday-bouquet',
                'name' => 'Birthday bouquet',
                'status' => 'published',
                'primary_category_slug' => 'birthday',
                'image_urls' => ['card' => '/birthday-bouquet-card.jpg'],
            ],
            [
                'id' => 3,
                'slug' => 'new-baby-posy',
                'name' => 'New baby posy',
                'status' => 'published',
                'primary_category_slug' => 'new-baby',
                'image_urls' => ['card' => '/new-baby-posy-card.jpg'],
            ],
        ], [
            'birthday' => categoryRailCategory('birthday', 'Birthday'),
            'sympathy' => categoryRailCategory('sympathy', 'Sympathy'),
            'thank-you' => categoryRailCategory('thank-you', 'Thank you', [
                'children' => ['new-baby'],
            ]),
            'new-baby' => categoryRailCategory('new-baby', 'New baby', [
                'path' => 'thank-you/new-baby',
                'breadcrumb' => [
                    ['name' => 'Thank you', 'path' => 'thank-you'],
                    ['name' => 'New baby', 'path' => 'thank-you/new-baby'],
                ],
            ]),
        ]),
    );

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    $rail = categoryRailHtml($html);

    expect($html)->toContain('data-category-rail')
        ->and($rail)->toContain('Birthday')
        ->and($rail)->toContain('/birthday-bouquet-card.jpg')
        ->and($rail)->toContain('Thank you')
        ->and($rail)->toContain('/new-baby-posy-card.jpg')
        ->and($rail)->toContain('Sympathy')
        ->and($rail)->not->toContain('/draft-wreath-card.jpg')
        ->and($rail)->toContain('>S</');
});

test('slugs restricts and orders tiles, unknown slugs are skipped, and limit clamps to 3..12', function () {
    $categories = [
        'alpha' => categoryRailCategory('alpha', 'Alpha blooms', ['hero_image_url' => '/heroes/alpha.jpg']),
        'bravo' => categoryRailCategory('bravo', 'Bravo blooms', ['hero_image_url' => '/heroes/bravo.jpg']),
        'charlie' => categoryRailCategory('charlie', 'Charlie blooms', ['hero_image_url' => '/heroes/charlie.jpg']),
        'delta' => categoryRailCategory('delta', 'Delta blooms', ['hero_image_url' => '/heroes/delta.jpg']),
    ];

    [$site, $page] = seedCategoryRailSite(
        ['slugs' => ['charlie', 'missing', 'alpha', 'delta']],
        categoryRailSnapshotJson([], $categories),
    );

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect(categoryRailHrefs($html))->toBe([
        '/collections/charlie',
        '/collections/alpha',
        '/collections/delta',
    ])->and($html)->not->toContain('Bravo blooms');

    $many = [];
    for ($i = 1; $i <= 15; $i++) {
        $slug = 'occasion-'.$i;
        $many[$slug] = categoryRailCategory($slug, 'Occasion '.$i, [
            'hero_image_url' => '/heroes/'.$slug.'.jpg',
        ]);
    }

    [$maxSite, $maxPage] = seedCategoryRailSite(
        ['limit' => 99],
        categoryRailSnapshotJson([], $many),
    );
    $maxHtml = app(PageRenderer::class)->render($maxSite, $maxPage->id, mode: 'public');

    expect(categoryRailHrefs($maxHtml))->toHaveCount(12)
        ->and($maxHtml)->toContain('Occasion 12')
        ->and($maxHtml)->not->toContain('Occasion 13');

    [$minSite, $minPage] = seedCategoryRailSite(
        ['limit' => 1],
        categoryRailSnapshotJson([], $many),
    );
    $minHtml = app(PageRenderer::class)->render($minSite, $minPage->id, mode: 'public');

    expect(categoryRailHrefs($minHtml))->toHaveCount(3)
        ->and($minHtml)->toContain('Occasion 3')
        ->and($minHtml)->not->toContain('Occasion 4');
});

test('omitted entirely with fewer than three tiles and with an empty or missing snapshot', function () {
    [$twoSite, $twoPage] = seedCategoryRailSite(
        ['title' => 'Lonely category rail'],
        categoryRailSnapshotJson([], [
            'alpha' => categoryRailCategory('alpha', 'Alpha blooms', ['hero_image_url' => '/heroes/alpha.jpg']),
            'bravo' => categoryRailCategory('bravo', 'Bravo blooms', ['hero_image_url' => '/heroes/bravo.jpg']),
        ]),
    );

    $twoHtml = app(PageRenderer::class)->render($twoSite, $twoPage->id, mode: 'public');

    expect($twoHtml)->not->toContain('data-category-rail')
        ->and($twoHtml)->not->toContain('Lonely category rail')
        ->and($twoHtml)->toContain('Welcome');

    [$emptySite, $emptyPage] = seedCategoryRailSite(
        ['title' => 'Empty snapshot rail'],
        categoryRailSnapshotJson([], []),
    );

    $emptyHtml = app(PageRenderer::class)->render($emptySite, $emptyPage->id, mode: 'public');

    expect($emptyHtml)->not->toContain('data-category-rail')
        ->and($emptyHtml)->not->toContain('Empty snapshot rail')
        ->and($emptyHtml)->toContain('Welcome');

    [$missingSite, $missingPage] = seedCategoryRailSite(['title' => 'Missing snapshot rail']);

    $missingHtml = app(PageRenderer::class)->render($missingSite, $missingPage->id, mode: 'public');

    expect($missingHtml)->not->toContain('data-category-rail')
        ->and($missingHtml)->not->toContain('Missing snapshot rail')
        ->and($missingHtml)->toContain('Welcome');
});

test('add_section category_rail works with documented fields and is a singleton', function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);

    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $ops = app(EditorOperations::class);

    $homeContent = ['sections' => [
        ['type' => 'hero', 'title' => 'A'],
        ['type' => 'cta', 'title' => 'B'],
    ]];
    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'content_data' => $homeContent,
        'status' => PageStatus::Published,
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create(['content_data' => $homeContent]);
    $home->update(['published_revision_id' => $homeRev->id]);

    $serviceContent = ['sections' => [['type' => 'intro', 'title' => 'Plumbing']]];
    $service = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'extensions',
        'content_data' => $serviceContent,
        'status' => PageStatus::Published,
    ]);
    $serviceRev = PageRevision::factory()->for($service, 'page')->create(['content_data' => $serviceContent]);
    $service->update(['published_revision_id' => $serviceRev->id]);

    $ctx = new EditorContext($user, $site, ActorChannel::Webmcp);

    $added = $ops->run($ctx, 'add_section', [
        'page_id' => $home->id,
        'type' => 'category_rail',
        'position' => 1,
        'revision_base' => $home->published_revision_id,
        'structure_epoch' => (int) $home->structure_epoch,
        'fields' => [
            'title' => 'Shop by occasion',
            'subtitle' => 'Pick a moment',
            'slugs' => ['birthday', 'sympathy'],
            'limit' => 8,
        ],
    ]);

    expect($added->ok)->toBeTrue();

    $draft = PageRevision::query()->findOrFail($home->fresh()->draft_revision_id);
    $section = collect($draft->content_data['sections'])->firstWhere('type', 'category_rail');

    expect($section)->toMatchArray([
        'type' => 'category_rail',
        'title' => 'Shop by occasion',
        'subtitle' => 'Pick a moment',
        'slugs' => ['birthday', 'sympathy'],
        'limit' => 8,
    ]);

    $second = $ops->run($ctx, 'add_section', [
        'page_id' => $home->id,
        'type' => 'category_rail',
        'position' => 2,
        'revision_base' => $home->fresh()->draft_revision_id,
        'structure_epoch' => (int) $home->fresh()->structure_epoch,
    ]);
    expect($second->ok)->toBeFalse()->and($second->error['code'])->toBe('validation');

    $onService = $ops->run($ctx, 'add_section', [
        'page_id' => $service->id,
        'type' => 'category_rail',
        'position' => 1,
        'revision_base' => $service->published_revision_id,
        'structure_epoch' => (int) $service->structure_epoch,
    ]);
    expect($onService->ok)->toBeFalse()->and($onService->error['code'])->toBe('validation');
});

test('add_section category_rail accepts empty slugs and a custom limit', function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);

    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $ops = app(EditorOperations::class);
    $homeContent = ['sections' => [['type' => 'hero', 'title' => 'A']]];
    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'content_data' => $homeContent,
        'status' => PageStatus::Published,
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create(['content_data' => $homeContent]);
    $home->update(['published_revision_id' => $homeRev->id]);
    $ctx = new EditorContext($user, $site, ActorChannel::Webmcp);

    $added = $ops->run($ctx, 'add_section', [
        'page_id' => $home->id,
        'type' => 'category_rail',
        'position' => 1,
        'revision_base' => $home->published_revision_id,
        'structure_epoch' => (int) $home->structure_epoch,
        'fields' => [
            'title' => 'Shop the range',
            'slugs' => [],
            'limit' => 12,
        ],
    ]);

    expect($added->ok)->toBeTrue();
    $section = collect(PageRevision::query()->findOrFail($home->fresh()->draft_revision_id)->content_data['sections'])
        ->firstWhere('type', 'category_rail');
    expect($section)->toMatchArray([
        'slugs' => [],
        'limit' => 12,
    ]);
});

test('admin-edit render emits editor markers on category_rail title and subtitle', function () {
    [$site, $page] = seedCategoryRailSite(
        snapshot: categoryRailSnapshotJson([], threeOccasionCategories()),
    );

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'admin-edit');

    expect($html)->toContain('data-editable-section-type="category_rail"')
        ->and($html)->toContain('data-editable-field="title"')
        ->and($html)->toContain('data-editable-field="subtitle"');
});
