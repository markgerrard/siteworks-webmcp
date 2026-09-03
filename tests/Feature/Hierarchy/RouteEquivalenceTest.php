<?php

namespace Tests\Feature\Hierarchy;

use App\Enums\PageStatus;
use App\Enums\PreviewLayout;
use App\Http\Middleware\ResolvePreviewHost;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SeedsHierarchyCorpus;
use Tests\TestCase;

class RouteEquivalenceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsHierarchyCorpus;

    private const PREVIEW_HOST = 'route-equivalence.d.brand-a.example';

    private const CUSTOM_HOST = 'route-equivalence.example';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 8, 24)->setTime(6, 0));
        config([
            'services.cloudflare.token' => 'route-equivalence-test-token',
            'services.cloudflare.brands.a.subdomain' => 'd',
            'site.public_cache_enabled' => false,
        ]);
        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        SupportAutoInjectedAssets::$forceAssetInjection = false;
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public static function renderLegs(): array
    {
        return [
            'legacy PreviewController' => [false],
            'versioned PublicSiteController' => [true],
        ];
    }

    #[DataProvider('renderLegs')]
    public function test_flat_preview_host_slugs_have_get_and_head_parity(bool $versionedRenderer): void
    {
        $this->seedRouteFixture();
        config(['site.use_versioned_renderer' => $versionedRenderer]);

        $get = $this->get('http://'.self::PREVIEW_HOST.'/roofing')->assertSuccessful();
        $head = $this->call('HEAD', 'http://'.self::PREVIEW_HOST.'/roofing')->assertSuccessful();

        $get->assertSee('Route Flat Page');
        $this->assertSame(
            $get->headers->get('Content-Type'),
            $head->headers->get('Content-Type'),
            'GET and HEAD must select the same render leg and representation metadata.',
        );
        $this->assertSame(
            $this->normaliseRouteHtml((string) $get->getContent()),
            $this->normaliseRouteHtml((string) $head->getContent()),
            'The test kernel must resolve GET and HEAD to byte-identical renderer output.',
        );
    }

    #[DataProvider('renderLegs')]
    public function test_flat_custom_host_slugs_render_the_page_on_both_legs(bool $versionedRenderer): void
    {
        $this->seedRouteFixture();
        config(['site.use_versioned_renderer' => $versionedRenderer]);

        $get = $this->get('http://'.self::CUSTOM_HOST.'/roofing')->assertSuccessful();
        $head = $this->call('HEAD', 'http://'.self::CUSTOM_HOST.'/roofing')->assertSuccessful();

        $get->assertSee('Route Flat Page');
        $this->assertSame($get->headers->get('Content-Type'), $head->headers->get('Content-Type'));
        $this->assertSame(
            $this->normaliseRouteHtml((string) $get->getContent()),
            $this->normaliseRouteHtml((string) $head->getContent()),
        );
    }

    #[DataProvider('renderLegs')]
    public function test_preview_hosts_render_multi_segment_paths(bool $versionedRenderer): void
    {
        $this->seedRouteFixture(withNestedPages: true);
        config(['site.use_versioned_renderer' => $versionedRenderer]);

        $this->get('http://'.self::PREVIEW_HOST.'/roofing/repairs')
            ->assertSuccessful()
            ->assertSee('Route Nested Page');
        $this->call('HEAD', 'http://'.self::PREVIEW_HOST.'/roofing/repairs')->assertSuccessful();
    }

    public function test_namespaced_legacy_preview_route_renders_multi_segment_paths(): void
    {
        $this->seedRouteFixture(withNestedPages: true);

        $this->get('http://localhost/preview/route-equivalence/roofing/repairs')
            ->assertSuccessful()
            ->assertSee('Route Nested Page');
    }

    public static function invalidPathShapes(): array
    {
        return [
            'encoded dot-dot segment' => ['/roofing/%2e%2e', false],
            'dot segment' => ['/roofing/.', false],
            'uppercase segment' => ['/Roofing', false],
            'trailing slash accepted today' => ['/roofing/', true],
            'empty segment' => ['/roofing//repairs', false],
        ];
    }

    #[DataProvider('invalidPathShapes')]
    public function test_invalid_and_edge_path_shapes_pin_todays_host_routing(string $path, bool $isSuccessfulToday): void
    {
        $this->seedRouteFixture();
        config(['site.use_versioned_renderer' => true]);

        foreach ([self::PREVIEW_HOST, self::CUSTOM_HOST] as $host) {
            $response = $this->get("http://{$host}{$path}");

            $isSuccessfulToday
                ? $response->assertSuccessful()->assertSee('Route Flat Page')
                : $response->assertNotFound();
        }
    }

    public static function pinnedDepthBounds(): array
    {
        return [
            'four segments (H-T3 flips to render)' => ['one/two/three/four'],
            'five segments (H-T3 keeps beyond-bound rejection)' => ['one/two/three/four/five'],
        ];
    }

    #[DataProvider('pinnedDepthBounds')]
    public function test_host_routing_enforces_the_four_segment_depth_bound(string $path): void
    {
        $this->seedRouteFixture(withNestedPages: true);
        config(['site.use_versioned_renderer' => true]);

        foreach ([self::PREVIEW_HOST, self::CUSTOM_HOST] as $host) {
            $get = $this->get("http://{$host}/{$path}");
            $head = $this->call('HEAD', "http://{$host}/{$path}");

            if ($path === 'one/two/three/four') {
                $get->assertSuccessful()->assertSee('Route Four Segment Page');
                $head->assertSuccessful();
            } else {
                $get->assertNotFound();
                $head->assertNotFound();
            }
        }
    }

    public static function unanchoredPassthroughCollisions(): array
    {
        return [
            'up prefix' => ['upvc-windows'],
            'shop prefix' => ['shopping-guides'],
            'news prefix' => ['newsletter'],
        ];
    }

    #[DataProvider('unanchoredPassthroughCollisions')]
    public function test_passthrough_prefixes_are_anchored_to_segment_boundaries(string $pageType): void
    {
        $this->seedRouteFixture();
        config(['site.use_versioned_renderer' => true]);

        $this->assertDatabaseHas('generated_pages', ['page_type' => $pageType]);

        $this->get('http://'.self::PREVIEW_HOST."/{$pageType}")->assertSuccessful();
        $this->call('HEAD', 'http://'.self::PREVIEW_HOST."/{$pageType}")->assertSuccessful();
    }

    public static function exactPassthroughPrefixes(): array
    {
        return collect(self::resolvePreviewHostPassthroughPrefixes())
            ->mapWithKeys(fn (string $prefix): array => [$prefix => [$prefix]])
            ->all();
    }

    #[DataProvider('exactPassthroughPrefixes')]
    public function test_every_passthrough_prefix_reaches_its_application_route_target(string $prefix): void
    {
        $this->seedRouteFixture();
        config(['site.use_versioned_renderer' => true]);
        $target = rtrim($prefix, '/').'/hierarchy-passthrough-target';
        $marker = "PASSTHROUGH TARGET {$prefix}";
        Route::get($target, fn () => response($marker));

        $this->get('http://'.self::PREVIEW_HOST."/{$target}")
            ->assertSuccessful()
            ->assertSeeText($marker);
        $this->call('HEAD', 'http://'.self::PREVIEW_HOST."/{$target}")
            ->assertSuccessful();
    }

    public function test_corpus_has_no_page_affected_by_the_unanchored_passthrough_deviation(): void
    {
        foreach ([51, 52, 53, 54] as $corpusId) {
            $this->seedHierarchyCorpusSite($corpusId);
        }

        $pageTypes = GeneratedPage::query()->pluck('page_type');
        $affectedPrefixes = self::resolvePreviewHostPassthroughPrefixes();
        $affected = $pageTypes->filter(
            fn (string $pageType): bool => collect($affectedPrefixes)
                ->contains(fn (string $prefix): bool => str_starts_with($pageType, $prefix)),
        );

        $this->assertSame([], $affected->values()->all());
    }

    public static function publicHosts(): array
    {
        return [
            'preview host' => [self::PREVIEW_HOST],
            'custom host' => [self::CUSTOM_HOST],
        ];
    }

    #[DataProvider('publicHosts')]
    public function test_one_page_site_page_urls_redirect_to_the_home_anchor(string $host): void
    {
        $site = $this->seedRouteFixture();
        $site->update(['preview_layout' => PreviewLayout::OnePage]);
        config(['site.use_versioned_renderer' => true]);

        $this->get("http://{$host}/roofing")->assertRedirect('/#roofing');
        $this->call('HEAD', "http://{$host}/roofing")->assertRedirect('/#roofing');

        // H-T3-r2 pin: UNKNOWN flat slugs also redirect (pre-hierarchy
        // behaviour preserved — resolve-order change had made these 404).
        $this->get("http://{$host}/no-such-page")->assertRedirect('/#no-such-page');

        // Nested unknown slugs on a one-page site 404 (new surface, no
        // legacy behaviour to preserve).
        $this->get("http://{$host}/roofing/nope")->assertNotFound();
    }

    #[DataProvider('publicHosts')]
    public function test_one_page_site_nested_pages_render_standalone(string $host): void
    {
        $site = $this->seedRouteFixture(withNestedPages: true);
        $site->update(['preview_layout' => PreviewLayout::OnePage]);
        config(['site.use_versioned_renderer' => true]);

        $this->get("http://{$host}/roofing/repairs")
            ->assertSuccessful()
            ->assertSee('Route Nested Page')
            ->assertDontSee('Route Flat Page');
        $this->call('HEAD', "http://{$host}/roofing/repairs")->assertSuccessful();
        $this->get("http://{$host}/")
            ->assertSuccessful()
            ->assertDontSee('Route Nested Page');

        config(['site.use_versioned_renderer' => false]);
        $this->get("http://{$host}/roofing/repairs")
            ->assertSuccessful()
            ->assertSee('Route Nested Page')
            ->assertDontSee('Route Flat Page');
    }

    public function test_one_page_sitemap_includes_nested_pages_but_excludes_flat_stacked_pages(): void
    {
        $site = $this->seedRouteFixture(withNestedPages: true);
        $site->update(['preview_layout' => PreviewLayout::OnePage]);
        config(['site.use_versioned_renderer' => true]);

        $this->get('http://'.self::CUSTOM_HOST.'/sitemap.xml')
            ->assertSuccessful()
            ->assertSee(self::CUSTOM_HOST.'/roofing/repairs', escape: false)
            ->assertDontSee(self::CUSTOM_HOST.'/roofing</loc>', escape: false);
    }

    public function test_site_v2_staging_route_renders_multi_segment_paths(): void
    {
        $this->seedRouteFixture(withNestedPages: true);
        config(['site.use_versioned_renderer' => true]);
        $this->withoutMiddleware(ResolvePreviewHost::class);

        $this->get('http://'.self::CUSTOM_HOST.'/__site_v2/roofing/repairs')
            ->assertSuccessful()
            ->assertSee('Route Nested Page');
    }

    #[DataProvider('publicHosts')]
    public function test_archived_page_pinned_by_the_current_version_still_renders(string $host): void
    {
        $site = $this->seedRouteFixture();
        $page = GeneratedPage::query()
            ->whereBelongsTo($site)
            ->where('page_type', 'roofing')
            ->sole();
        $page->update(['status' => PageStatus::Archived]);
        config(['site.use_versioned_renderer' => true]);

        $this->get("http://{$host}/roofing")
            ->assertSuccessful()
            ->assertSee('Route Flat Page');
        $this->call('HEAD', "http://{$host}/roofing")->assertSuccessful();
    }

    public static function crawlerFiles(): array
    {
        return [
            'sitemap' => ['sitemap.xml', 'sitemap.xml'],
            'robots' => ['robots.txt', 'robots.txt'],
        ];
    }

    #[DataProvider('crawlerFiles')]
    public function test_crawler_routes_are_byte_identical_to_the_committed_baseline(string $requestPath, string $fixtureName): void
    {
        $this->seedRouteFixture();
        config(['site.use_versioned_renderer' => true]);

        $body = (string) $this->get('http://'.self::CUSTOM_HOST."/{$requestPath}")
            ->assertSuccessful()
            ->getContent();
        $fixture = base_path("tests/Fixtures/HierarchyRoutes/{$fixtureName}");

        if ($this->shouldUpdateRouteFixtures()) {
            @mkdir(dirname($fixture), 0775, true);
            file_put_contents($fixture, $body);
            $this->assertFileExists($fixture);

            return;
        }

        $this->assertFileExists($fixture, "Missing crawler route fixture [{$fixture}].");
        $this->assertSame(file_get_contents($fixture), $body, "{$requestPath} bytes drifted.");
    }

    private function seedRouteFixture(bool $withNestedPages = false): Site
    {
        $site = Site::factory()->create([
            'business_name' => 'Route Equivalence Roofing',
            'business_type' => 'Roofing',
            'location' => 'Wigan',
            'preview_domain' => 'route-equivalence',
            'preview_brand' => 'a',
            'custom_domain' => self::CUSTOM_HOST,
            'custom_domain_status' => 'active',
            'preview_layout' => PreviewLayout::MultiPage,
        ]);

        $pageTitles = [
            'home' => 'Route Home Page',
            'roofing' => 'Route Flat Page',
            'upvc-windows' => 'UPVC Windows Page',
            'shopping-guides' => 'Shopping Guides Page',
            'newsletter' => 'Newsletter Page',
        ];
        if ($withNestedPages) {
            $pageTitles = array_merge($pageTitles, [
                'roofing/repairs' => 'Route Nested Page',
                'one' => 'Route One Page',
                'one/two' => 'Route Two Segment Page',
                'one/two/three' => 'Route Three Segment Page',
                'one/two/three/four' => 'Route Four Segment Page',
            ]);
        }
        $pins = [];
        $home = null;
        $pagesByType = [];

        foreach ($pageTitles as $pageType => $title) {
            $parentPath = str_contains($pageType, '/')
                ? substr($pageType, 0, (int) strrpos($pageType, '/'))
                : null;
            $page = GeneratedPage::factory()->for($site)->create([
                'page_type' => $pageType,
                'parent_id' => $parentPath === null ? null : $pagesByType[$parentPath]->id,
            ]);
            $revision = PageRevision::factory()->for($page, 'page')->create([
                'content_data' => ['sections' => [['type' => 'hero', 'title' => $title]]],
            ]);
            $page->update(['published_revision_id' => $revision->id]);
            $pagesByType[$pageType] = $page;
            $pins[] = ['page_id' => $page->id, 'revision_id' => $revision->id];
            if ($pageType === 'home') {
                $home = $page;
            }
        }

        $this->assertInstanceOf(GeneratedPage::class, $home);
        $version = SiteVersion::create([
            'site_id' => $site->id,
            'version' => 1,
            'composition' => [
                'nav' => ['items' => []],
                'footer' => ['columns' => [], 'show_credit' => true],
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

        Preview::factory()->for($site)->create([
            'slug' => 'route-equivalence',
            'snapshot' => [
                'layout' => 'multi_page',
                'pages' => collect($pageTitles)->map(
                    fn (string $title): array => ['seo' => ['meta_title' => $title]],
                )->all(),
                'profile' => ['name' => 'Route Equivalence Roofing'],
                'theme' => ['primary_color' => '#123456', 'accent_color' => '#e39822'],
            ],
            'is_active' => true,
            'published_at' => now(),
        ]);

        return $site;
    }

    private function shouldUpdateRouteFixtures(): bool
    {
        $raw = getenv('HIERARCHY_UPDATE_FIXTURES');
        if ($raw === false) {
            $raw = $_SERVER['HIERARCHY_UPDATE_FIXTURES'] ?? $_ENV['HIERARCHY_UPDATE_FIXTURES'] ?? '';
        }

        return filter_var($raw, FILTER_VALIDATE_BOOL) === true;
    }

    /**
     * @return list<string>
     */
    private static function resolvePreviewHostPassthroughPrefixes(): array
    {
        $constant = (new \ReflectionClass(ResolvePreviewHost::class))
            ->getReflectionConstant('PASSTHROUGH_PREFIXES');

        if ($constant === false || ! is_array($constant->getValue())) {
            throw new \LogicException('ResolvePreviewHost::PASSTHROUGH_PREFIXES must remain an array.');
        }

        return array_values($constant->getValue());
    }

    private function normaliseRouteHtml(string $html): string
    {
        $html = (string) preg_replace('/csrfToken:\s*"[^"]*"/', 'csrfToken: "__CSRF__"', $html);
        $html = (string) preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value="__CSRF__"', $html);

        return (string) preg_replace(
            '/\/build(?:-[a-z0-9]+)?\/assets\/[A-Za-z0-9._-]+\.(css|js)/',
            '/build/assets/HASH.$1',
            $html,
        );
    }
}
