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

function featuredProductsSnapshotJson(array $products, array $featuredSlugs = [], array $categories = []): array
{
    $bySlug = [];
    foreach ($products as $product) {
        $bySlug[$product['slug']] = [
            'id' => $product['id'],
            'slug' => $product['slug'],
            'status' => $product['status'] ?? 'published',
            'price_cents' => $product['price_cents'] ?? 4500,
            'price_display' => $product['price_display'] ?? '£45.00',
            'in_stock_any' => true,
            'image_urls' => ['card' => '/'.$product['slug'].'-card.jpg'],
            'product_card' => [
                'slug' => $product['slug'],
                'name' => $product['name'],
                'price_display' => $product['price_display'] ?? '£45.00',
            ],
            'tags' => $product['tags'] ?? [],
        ];
    }

    return [
        'meta' => ['product_count' => count($bySlug)],
        'categories' => $categories,
        'products' => $bySlug,
        'featured_slugs' => $featuredSlugs,
    ];
}

function seedFeaturedProductsSite(array $section = [], array $snapshot = [], string $shopMode = 'cart'): array
{
    $site = Site::factory()->create([
        'business_name' => 'Camino',
        'theme' => 'trades-bold',
        'shop_mode' => $shopMode,
        'custom_domain' => 'camino-featured.example',
        'custom_domain_status' => 'active',
    ]);

    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Welcome'],
            array_merge([
                'type' => 'featured_products',
                'title' => 'Featured products',
                'subtitle' => 'This week',
                'source' => 'featured',
                'count' => 4,
                'cta_label' => 'Browse the shop',
                'cta_url' => '/shop',
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

test('featured source renders featured product cards and the shop CTA', function () {
    [$site, $page] = seedFeaturedProductsSite(
        snapshot: featuredProductsSnapshotJson([
            ['id' => 10, 'slug' => 'alpha', 'name' => 'Alpha item'],
            ['id' => 11, 'slug' => 'bravo', 'name' => 'Bravo item'],
            ['id' => 12, 'slug' => 'delta', 'name' => 'Delta item'],
        ], ['alpha', 'bravo']),
    );

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('Featured products')
        ->and($html)->toContain('.shop-product-card__pill-slot')
        ->and($html)->toContain('This week')
        ->and($html)->toContain('Alpha item')
        ->and($html)->toContain('Bravo item')
        ->and($html)->not->toContain('Delta item')
        ->and($html)->toContain('Browse the shop')
        ->and($html)->toContain('href="/shop"')
        ->and($html)->toContain('/products/alpha');
});

test('newest fallback is used when featured_slugs is empty', function () {
    [$site, $page] = seedFeaturedProductsSite(
        ['source' => 'featured', 'count' => 4],
        featuredProductsSnapshotJson([
            ['id' => 1, 'slug' => 'old-item', 'name' => 'Old item'],
            ['id' => 9, 'slug' => 'new-item', 'name' => 'New item'],
        ], []),
    );

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('New item')
        ->and($html)->toContain('Old item');
});

test('count is clamped so at most eight cards render', function () {
    $products = [];
    for ($i = 1; $i <= 10; $i++) {
        $products[] = ['id' => $i, 'slug' => 'item-'.$i, 'name' => 'Item '.$i];
    }

    [$site, $page] = seedFeaturedProductsSite(
        ['source' => 'newest', 'count' => 99],
        featuredProductsSnapshotJson($products, []),
    );

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('data-featured-products-count="8"')
        ->and($html)->toContain('Item 10')
        ->and($html)->toContain('Item 3');
});

test('home section is omitted for auto sources when fewer than two products match', function (string $source) {
    [$site, $page] = seedFeaturedProductsSite(
        ['source' => $source, 'title' => 'Lonely home row'],
        featuredProductsSnapshotJson([
            [
                'id' => 1,
                'slug' => 'alpha',
                'name' => 'Alpha item',
                'tags' => [['slug' => 'gift', 'label' => 'Gift', 'badge' => true, 'tone' => 'neutral']],
            ],
        ], ['alpha'], ['range' => ['slug' => 'range', 'name' => 'Range', 'product_slugs' => ['alpha']]]),
    );

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->not->toContain('Lonely home row')
        ->and($html)->toContain('Welcome');
})->with([
    'newest' => 'newest',
    'tag' => 'tag:gift',
    'category' => 'category:range',
]);

test('home section renders a single product for manual and featured sources', function (string $source) {
    [$site, $page] = seedFeaturedProductsSite(
        ['source' => $source, 'title' => 'Lonely home row'],
        featuredProductsSnapshotJson([
            [
                'id' => 1,
                'slug' => 'alpha',
                'name' => 'Alpha item',
            ],
        ], ['alpha']),
    );

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('Lonely home row')
        ->and($html)->toContain('Alpha item')
        ->and($html)->toContain('data-featured-products-count="1"');
})->with([
    'manual' => 'manual',
    'featured' => 'featured',
]);

test('empty snapshot renders nothing for the section', function () {
    [$site, $page] = seedFeaturedProductsSite();

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->not->toContain('Featured products')
        ->and($html)->not->toContain('Browse the shop')
        ->and($html)->toContain('Welcome');
});

test('enquire-mode cards still link to product pages', function () {
    [$site, $page] = seedFeaturedProductsSite(
        [],
        featuredProductsSnapshotJson([
            ['id' => 4, 'slug' => 'alpha', 'name' => 'Alpha item'],
            ['id' => 5, 'slug' => 'bravo', 'name' => 'Bravo item'],
        ], ['alpha', 'bravo']),
        shopMode: 'enquire',
    );

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('Alpha item')
        ->and($html)->toContain('/products/alpha')
        ->and($html)->not->toContain('Add to cart');
});

test('featured_products manual grid is byte-identical to pre-T28 head', function () {
    [$site, $page] = seedFeaturedProductsSite(
        ['source' => 'manual', 'layout' => 'grid'],
        featuredProductsSnapshotJson([
            ['id' => 1, 'slug' => 'alpha', 'name' => 'Alpha item'],
            ['id' => 2, 'slug' => 'bravo', 'name' => 'Bravo item'],
        ], ['alpha', 'bravo']),
    );

    $section = [
        'type' => 'featured_products',
        'title' => 'Featured products',
        'subtitle' => 'This week',
        'source' => 'manual',
        'layout' => 'grid',
        'count' => 4,
        'cta_label' => 'Browse the shop',
        'cta_url' => '/shop',
    ];
    $data = [
        'pageId' => $page->id,
        'sectionIndex' => 1,
        'emitMarkers' => false,
        'mode' => 'public',
        'site' => $site,
        'section' => $section,
    ];

    $head = \Illuminate\Support\Facades\Blade::render(
        (string) file_get_contents(base_path('tests/Fixtures/ByteIdentity/featured-products-grid-head.blade.php')),
        $data,
        deleteCachedView: true,
    );
    $current = view('site.sections.featured_products', $data)->render();
    $fixture = base_path('tests/Fixtures/ByteIdentity/featured-products-manual-grid.html');

    expect($current)->toBe($head);

    if (getenv('BYTE_IDENTITY_SEED') || getenv('BYTE_IDENTITY_UPDATE_FIXTURES')) {
        file_put_contents($fixture, $current);
    }

    expect(file_exists($fixture))->toBeTrue()
        ->and((string) file_get_contents($fixture))->toBe($current);
});

test('carousel layout emits a scroll-snap row with prev next controls', function () {
    [$site, $page] = seedFeaturedProductsSite(
        ['layout' => 'carousel', 'source' => 'newest', 'count' => 4],
        featuredProductsSnapshotJson([
            ['id' => 1, 'slug' => 'alpha', 'name' => 'Alpha item'],
            ['id' => 2, 'slug' => 'bravo', 'name' => 'Bravo item'],
        ], ['alpha', 'bravo']),
    );

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('scroll-snap-type: x mandatory')
        ->and($html)->toContain('aria-label="Previous products"')
        ->and($html)->toContain('prefers-reduced-motion')
        ->and($html)->toContain('Alpha item');
});

test('admin-edit render emits editor markers on featured_products fields', function () {
    [$site, $page] = seedFeaturedProductsSite(
        [],
        featuredProductsSnapshotJson([
            ['id' => 1, 'slug' => 'alpha', 'name' => 'Alpha item'],
            ['id' => 2, 'slug' => 'bravo', 'name' => 'Bravo item'],
        ], ['alpha', 'bravo']),
    );

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'admin-edit');

    expect($html)->toContain('data-editable-section-type="featured_products"')
        ->and($html)->toContain('data-editable-field="title"')
        ->and($html)->toContain('data-editable-field="subtitle"')
        ->and($html)->toContain('data-editable-field="cta_label"')
        ->and($html)->toContain('data-editable-field="cta_url"');
});

test('add_section featured_products works with documented fields and is rejected off home', function () {
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
        'type' => 'featured_products',
        'position' => 1,
        'revision_base' => $home->published_revision_id,
        'structure_epoch' => (int) $home->structure_epoch,
        'fields' => [
            'title' => 'Our picks',
            'subtitle' => 'Fresh this morning',
            'source' => 'featured',
            'count' => 4,
            'cta_label' => 'Browse the shop',
            'cta_url' => '/shop',
        ],
    ]);

    expect($added->ok)->toBeTrue();


    $draft = PageRevision::query()->findOrFail($home->fresh()->draft_revision_id);
    $section = collect($draft->content_data['sections'])->firstWhere('type', 'featured_products');

    expect($section)->toMatchArray([
        'type' => 'featured_products',
        'title' => 'Our picks',
        'subtitle' => 'Fresh this morning',
        'source' => 'featured',
        'count' => 4,
        'cta_label' => 'Browse the shop',
        'cta_url' => '/shop',
    ]);

    $second = $ops->run($ctx, 'add_section', [
        'page_id' => $home->id,
        'type' => 'featured_products',
        'position' => 2,
        'revision_base' => $home->fresh()->draft_revision_id,
        'structure_epoch' => (int) $home->fresh()->structure_epoch,
    ]);
    expect($second->ok)->toBeFalse()->and($second->error['code'])->toBe('validation');

    $onService = $ops->run($ctx, 'add_section', [
        'page_id' => $service->id,
        'type' => 'featured_products',
        'position' => 1,
        'revision_base' => $service->published_revision_id,
        'structure_epoch' => (int) $service->structure_epoch,
    ]);
    expect($onService->ok)->toBeFalse()->and($onService->error['code'])->toBe('validation');
});

test('add_section featured_products accepts tag source, limit and carousel layout', function () {
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
        'type' => 'featured_products',
        'position' => 1,
        'revision_base' => $home->published_revision_id,
        'structure_epoch' => (int) $home->structure_epoch,
        'fields' => [
            'title' => 'Gift picks',
            'source' => 'tag:gift',
            'limit' => 8,
            'layout' => 'carousel',
        ],
    ]);

    expect($added->ok)->toBeTrue();
    $section = collect(PageRevision::query()->findOrFail($home->fresh()->draft_revision_id)->content_data['sections'])
        ->firstWhere('type', 'featured_products');
    expect($section)->toMatchArray([
        'source' => 'tag:gift',
        'limit' => 8,
        'layout' => 'carousel',
    ]);
});

test('add_section featured_products rejects executable cta_url schemes and accepts site paths and http(s)', function () {
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

    $attempt = fn (string $url) => $ops->run($ctx, 'add_section', [
        'page_id' => $home->id,
        'type' => 'featured_products',
        'position' => 1,
        'revision_base' => $home->fresh()->draft_revision_id ?? $home->published_revision_id,
        'structure_epoch' => (int) $home->fresh()->structure_epoch,
        'fields' => ['cta_url' => $url],
    ]);

    foreach (['javascript:alert(document.domain)', 'data:text/html,hi', '//evil.example/shop', '/\\evil.example', '\\\\evil.example', 'https://evil.example\\@good.example/', 'vbscript:x', "/shop\nfoo"] as $bad) {
        $r = $attempt($bad);
        expect($r->ok)->toBeFalse("{$bad} must be rejected")
            ->and($r->error['code'])->toBe('validation')
            ->and(json_encode($r->error))->toContain('cta_url');
    }

    $ok = $attempt('https://example.com/shop');
    expect($ok->ok)->toBeTrue();
});

test('a stored executable cta_url never reaches the rendered href', function () {
    [$site, $page] = seedFeaturedProductsSite(
        section: ['cta_url' => 'javascript:alert(1)'],
        snapshot: featuredProductsSnapshotJson([
            ['id' => 10, 'slug' => 'alpha', 'name' => 'Alpha item'],
            ['id' => 11, 'slug' => 'bravo', 'name' => 'Bravo item'],
        ], ['alpha', 'bravo']),
    );

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->not->toContain('javascript:')
        ->and($html)->toContain('href="/shop"');
});
