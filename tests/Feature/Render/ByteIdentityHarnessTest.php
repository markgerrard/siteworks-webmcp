<?php

namespace Tests\Feature\Render;

use App\Enums\PageKind;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Shop\SnapshotBuilder;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ByteIdentityHarnessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array<string, string>>
     */
    private function demoThemes(): array
    {
        $path = base_path('tests/fixtures/home-themes/demo-site-themes.json');
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        $themes = [];
        foreach (['51-eden', '52-hunt', '54-nh', 'light-archetype'] as $key) {
            $this->assertIsArray($decoded[$key] ?? null, "missing committed theme [{$key}]");
            $themes[$key] = $decoded[$key];
        }

        return $themes;
    }

    public static function corpusMatrix(): array
    {
        $cases = [];
        $themes = ['51-eden', '52-hunt', '54-nh', 'light-archetype'];
        $recipes = ['classic', 'editorial', 'showcase', 'precision', 'banded'];
        $pages = ['home', 'service', 'about', 'projects'];

        foreach ($themes as $theme) {
            foreach ($recipes as $recipe) {
                foreach ($pages as $page) {
                    $cases["{$theme}-{$recipe}-{$page}"] = [$theme, $recipe, $page];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('corpusMatrix')]
    public function test_full_page_snapshots_are_byte_identical_to_baseline(string $themeKey, string $recipe, string $pageKind): void
    {
        $theme = $this->demoThemes()[$themeKey];
        [$site, $pages] = $this->makeCorpusSite($theme, $recipe);

        $page = $pages[$pageKind];
        $renderer = app(PageRenderer::class);
        $html = $this->normalise($renderer->render($site, $page->id, mode: 'public'));

        // Assert inertness: new motion / count-up script blocks must NEVER appear in unstamped classic/stock recipes
        $this->assertStringNotContainsString('stat-count-up', $html);
        $this->assertStringNotContainsString('split-heading-reveal', $html);
        $this->assertStringNotContainsString('marquee-band', $html);
        $this->assertStringNotContainsString('previous-', $html);

        // Hard assertion (b): each site's palette primary actually appears in its fixtures
        $this->assertStringContainsString(
            $theme['primary_color'],
            $html,
            "{$themeKey}-{$recipe}-{$pageKind} must contain primary colour {$theme['primary_color']}",
        );

        $fixturePath = base_path("tests/Fixtures/ByteIdentity/{$themeKey}-{$recipe}-{$pageKind}.html");

        if ($this->isSeedingEnabled()) {
            @mkdir(dirname($fixturePath), 0775, true);
            file_put_contents($fixturePath, $html);
            $this->assertFileExists($fixturePath);

            return;
        }

        $this->assertFileExists($fixturePath, "Missing fixture {$fixturePath} — baselines are frozen; re-seed only with BYTE_IDENTITY_SEED=1");
        $this->assertSame(file_get_contents($fixturePath), $html, "{$themeKey}-{$recipe}-{$pageKind} drifted from baseline snapshot");
    }

    public function test_corpus_fixtures_meet_distinctness_invariants(): void
    {
        $themes = $this->demoThemes();
        $recipes = ['classic', 'editorial', 'showcase', 'precision', 'banded'];
        $pages = ['home', 'service', 'about', 'projects'];

        $fixtures = [];

        foreach ($themes as $themeKey => $theme) {
            foreach ($recipes as $recipe) {
                foreach ($pages as $pageKind) {
                    $key = "{$themeKey}-{$recipe}-{$pageKind}";
                    $fixturePath = base_path("tests/Fixtures/ByteIdentity/{$key}.html");
                    $this->assertFileExists($fixturePath, "Fixture [{$fixturePath}] must exist");

                    $content = (string) file_get_contents($fixturePath);
                    $fixtures[$key] = $content;

                    // Hard assertion (b): each site's palette primary actually appears in its fixtures
                    $this->assertStringContainsString(
                        $theme['primary_color'],
                        $content,
                        "Fixture {$key} must contain its palette primary colour {$theme['primary_color']}",
                    );
                }
            }
        }

        $this->assertCount(80, $fixtures);

        // Hard assertion (a): minimum distinct document count across the fixture set (>=40)
        $distinctCount = count(array_unique($fixtures));
        $this->assertGreaterThanOrEqual(
            40,
            $distinctCount,
            "Expected at least 40 distinct documents across the 80 fixtures, found {$distinctCount}",
        );

        // Hard assertion (c): no two sites' same-page fixtures byte-identical
        $themeKeys = array_keys($themes);
        foreach ($recipes as $recipe) {
            foreach ($pages as $pageKind) {
                for ($i = 0; $i < count($themeKeys); $i++) {
                    for ($j = $i + 1; $j < count($themeKeys); $j++) {
                        $keyA = "{$themeKeys[$i]}-{$recipe}-{$pageKind}";
                        $keyB = "{$themeKeys[$j]}-{$recipe}-{$pageKind}";

                        $this->assertNotSame(
                            $fixtures[$keyA],
                            $fixtures[$keyB],
                            "Same-page fixture [{$recipe}-{$pageKind}] between sites {$themeKeys[$i]} and {$themeKeys[$j]} must not be byte-identical",
                        );
                    }
                }
            }
        }
    }

    public function test_featured_products_manual_grid_fixture_covers_the_section(): void
    {
        $fixture = base_path('tests/Fixtures/ByteIdentity/featured-products-manual-grid.html');
        $this->assertFileExists($fixture, 'featured_products manual+grid fixture must exist — the 80-page corpus has none');
        $html = (string) file_get_contents($fixture);
        $this->assertStringContainsString('data-featured-products-count="2"', $html);
        $this->assertStringContainsString('            <div class="grid grid-cols-2 gap-4 sm:gap-6 max-w-full">', $html);
        $this->assertStringNotContainsString('scroll-snap-type: x mandatory', $html);
    }

    public function test_no_shop_cart_gate_preserves_a_frozen_page_byte_for_byte(): void
    {
        [$site, $pages] = $this->makeCorpusSite($this->demoThemes()['51-eden'], 'classic');

        $html = $this->normalise(app(PageRenderer::class)->render($site, $pages['home']->id, mode: 'public'));
        $fixture = base_path('tests/Fixtures/ByteIdentity/51-eden-classic-home.html');

        $this->assertFalse($site->currentShopSnapshot()->exists());
        $this->assertSame(file_get_contents($fixture), $html);
    }

    public function test_flag_off_header_matches_pre_commerce_fixture_even_with_a_catalogue(): void
    {
        [$site, $pages] = $this->makeCorpusSite($this->demoThemes()['51-eden'], 'classic');
        $site->update(['shop_enabled' => false]);

        $product = \App\Models\Shop\Product::factory()->for($site)->create([
            'slug' => 'corpus-item',
            'name' => 'Corpus Item',
            'status' => \App\Enums\Shop\ProductStatus::Published,
        ]);
        $variant = \App\Models\Shop\ProductVariant::factory()->for($product)->create([
            'sku' => 'CORPUS-1',
            'price_cents' => 1200,
        ]);
        \App\Models\Shop\VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 3]);

        $html = $this->normalise(app(PageRenderer::class)->render($site->fresh(), $pages['home']->id, mode: 'public'));
        $fixture = base_path('tests/Fixtures/ByteIdentity/51-eden-classic-home.html');
        $expected = (string) file_get_contents($fixture);

        $this->assertFalse($site->fresh()->hasPurchasableShop());
        $this->assertStringNotContainsString('data-shop-cart-control', $html);
        $this->assertSame(
            $this->headerMarkup($expected),
            $this->headerMarkup($html),
            'Flag-off header must stay byte-identical to the pre-commerce fixture',
        );
        $this->assertSame($expected, $html);
    }

    public function test_shop_enabled_chrome_matches_its_committed_baseline(): void
    {
        [$site, $pages] = $this->makeCorpusSite($this->demoThemes()['51-eden'], 'classic');

        // The chrome gate is "has something to buy", not "has a snapshot row": a site
        // can have a snapshot row while its catalogue is empty, and row-existence
        // alone would advertise a shop on a storefront with nothing to sell. A
        // snapshot built from an empty catalogue therefore renders NO shop chrome,
        // and this fixture needs a product.
        $product = \App\Models\Shop\Product::factory()->for($site)->create([
            'slug' => 'corpus-item',
            'name' => 'Corpus Item',
            'status' => \App\Enums\Shop\ProductStatus::Published,
        ]);
        $variant = \App\Models\Shop\ProductVariant::factory()->for($product)->create([
            'sku' => 'CORPUS-1',
            'price_cents' => 1200,
        ]);
        \App\Models\Shop\VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 3]);

        (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));

        // The nav entry lands in the DRAFT — T19 stopped ensureShopNavEntry mutating a
        // published version, because doing so rewrote immutable history. This fixture
        // freezes the chrome of a PUBLISHED shop, so the site has to publish first.
        // Without this the test renders a published version that predates the entry and
        // reports no Shop link, which is correct behaviour, not a regression.
        app(\App\Services\Site\SitePublishService::class)->publishSite($site, publishNote: 'shop-enabled-chrome fixture');

        $html = $this->normalise(app(PageRenderer::class)->render($site, $pages['home']->id, mode: 'public'));
        $fixture = base_path('tests/Fixtures/ByteIdentity/shop-enabled-chrome.html');

        $this->assertStringContainsString('href="/shop"', $html);
        $this->assertStringContainsString('data-shop-cart-control', $html);
        $this->assertMatchesRegularExpression('/data-shop-cart-count[^>]*>\s*0\s*</', $html);

        if ($this->isSeedingEnabled()) {
            file_put_contents($fixture, $html);
            $this->assertFileExists($fixture);

            return;
        }

        $this->assertFileExists($fixture, "Missing fixture {$fixture} — seed only this dedicated case with BYTE_IDENTITY_SEED=1");
        $this->assertSame(file_get_contents($fixture), $html, 'Shop-enabled chrome drifted from its baseline snapshot');
    }

    public function test_disabling_a_published_shop_drops_stored_shop_nav_from_the_header(): void
    {
        [$site, $pages] = $this->makeCorpusSite($this->demoThemes()['51-eden'], 'classic');

        $product = \App\Models\Shop\Product::factory()->for($site)->create([
            'slug' => 'corpus-item',
            'name' => 'Corpus Item',
            'status' => \App\Enums\Shop\ProductStatus::Published,
        ]);
        $variant = \App\Models\Shop\ProductVariant::factory()->for($product)->create([
            'sku' => 'CORPUS-1',
            'price_cents' => 1200,
        ]);
        \App\Models\Shop\VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 3]);

        (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));
        app(\App\Services\Site\SitePublishService::class)->publishSite($site, publishNote: 'shop-enabled-chrome fixture');

        $onFixture = (string) file_get_contents(base_path('tests/Fixtures/ByteIdentity/shop-enabled-chrome.html'));
        $renderer = app(PageRenderer::class);

        $onHtml = $this->normalise($renderer->render($site->fresh(), $pages['home']->id, mode: 'public'));
        $this->assertSame($onFixture, $onHtml, 'ON render must match the shop-enabled fixture before the flag flips');

        $versionId = SiteVersionCurrent::query()->where('site_id', $site->id)->value('version_id');
        $publishedNav = SiteVersion::query()->whereKey($versionId)->value('composition')['nav']['items'] ?? [];
        $this->assertTrue(
            collect($publishedNav)->contains(fn (array $item): bool => ($item['type'] ?? null) === 'shop'),
            'published composition must contain a stored Shop nav item',
        );

        $site->update(['shop_enabled' => false]);

        $offHtml = $this->normalise($renderer->render($site->fresh(), $pages['home']->id, mode: 'public'));
        $offHeader = $this->headerMarkup($offHtml);
        $this->assertStringNotContainsString('href="/shop"', $offHtml);
        $this->assertStringNotContainsString('data-shop-cart-control', $offHeader);
        $this->assertStringNotContainsString('data-shop-search-toggle', $offHeader);
        $this->assertStringContainsString('href="/extensions"', $offHeader);
        $this->assertStringContainsString('href="/about"', $offHeader);
        $this->assertStringContainsString('href="/projects"', $offHeader);

        $navAfterDisable = SiteVersion::query()->whereKey($versionId)->value('composition')['nav']['items'] ?? [];
        $this->assertSame($publishedNav, $navAfterDisable, 'stored composition must not be mutated when the flag is off');

        $site->update(['shop_enabled' => true]);
        $onAgain = $this->normalise($renderer->render($site->fresh(), $pages['home']->id, mode: 'public'));
        $this->assertSame($onFixture, $onAgain, 're-enabling must restore the shop-enabled fixture');
    }

    /**
     * CSS gate: verifies compiled *public* CSS (`resources/css/site.css`) is
     * strictly additions-only against the baseline. It does not observe the
     * agents/customer CP bundle (`resources/css/app.css`); there is no
     * app.css baseline fixture.
     *
     * The bundle must be rebuilt (`npm run build` in the app container) before
     * running this test; the comparator guards against silently passing on
     * stale or unbuilt assets.
     */
    public function test_compiled_css_is_strictly_additions_only_against_baseline(): void
    {
        $baselinePath = base_path('tests/Fixtures/CssBaseline/site.css.baseline');
        $this->assertFileExists($baselinePath, 'Baseline site.css must be committed');
        $baselineCss = trim(file_get_contents($baselinePath));

        $manifestPath = public_path('build-agents/manifest.json');
        if (! file_exists($manifestPath)) {
            $manifestPath = public_path('build/manifest.json');
        }
        $this->assertFileExists($manifestPath, 'Build manifest must exist to resolve compiled CSS');

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('resources/css/site.css', $manifest);
        $this->assertArrayHasKey('resources/css/app.css', $manifest, 'CP app.css is a separate compiled entry this gate does not compare');

        $cssFile = $manifest['resources/css/site.css']['file'];
        $compiledCssPath = dirname($manifestPath).'/'.$cssFile;
        $this->assertFileExists($compiledCssPath);

        $currentCss = trim(file_get_contents($compiledCssPath));

        $baselineHash = sha1($baselineCss);
        $currentHash = sha1($currentCss);

        // Stale-asset guard: if current built asset is byte-identical to baseline,
        // emit a skipped-with-warning marker rather than a silent pass.
        if ($currentHash === $baselineHash) {
            $this->markTestSkipped(
                'STALE_ASSET_WARNING: Compiled site.css is byte-identical to baseline. Stage-5 gate must rebuild bundle (npm run build) before certifying CSS gate.',
            );
        }

        // Parse both CSS stylesheets into ordered rule sequences
        $baselineRules = $this->extractCssRules($baselineCss);
        $currentRules = $this->extractCssRules($currentCss);

        $this->assertNotEmpty($baselineRules, 'Baseline CSS must contain parsed rules');
        $this->assertNotEmpty($currentRules, 'Current compiled CSS must contain parsed rules');

        // Ordered subsequence assertion: every baseline rule must appear verbatim
        // in $currentRules in the exact relative sequence.
        $bIdx = 0;
        $cIdx = 0;
        $bCount = count($baselineRules);
        $cCount = count($currentRules);

        while ($bIdx < $bCount && $cIdx < $cCount) {
            if ($baselineRules[$bIdx] === $currentRules[$cIdx]) {
                $bIdx++;
            }
            $cIdx++;
        }

        if ($bIdx < $bCount) {
            $missingRule = $baselineRules[$bIdx];
            $preview = mb_substr($missingRule, 0, 160);
            $currPreview = isset($currentRules[$cIdx]) ? mb_substr($currentRules[$cIdx], 0, 160) : 'EOF';
            $this->fail(
                "Compiled site.css violates additions-only policy: baseline rule #{$bIdx} of {$bCount} was not matched (current checked {$cIdx} of {$cCount}).\nBaseline: [{$preview}]\nCurrent at cIdx: [{$currPreview}]\nBaseline rules count: {$bCount}, Current rules count: {$cCount}",
            );
        }

        $this->assertSame(
            $bCount,
            $bIdx,
            'All baseline CSS rules must appear in identical text and relative order in compiled site.css',
        );
    }

    public function test_there_is_no_agents_app_css_baseline(): void
    {
        $this->assertFileExists(base_path('tests/Fixtures/CssBaseline/site.css.baseline'));
        $this->assertFileDoesNotExist(base_path('tests/Fixtures/CssBaseline/app.css.baseline'));
        $this->assertFileDoesNotExist(base_path('tests/Fixtures/CssBaseline/agents-app.css.baseline'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function emptyCopyPresetVariants(): array
    {
        return [
            'boxed-left' => ['boxed-left'],
            'panel-left' => ['panel-left'],
        ];
    }

    #[DataProvider('emptyCopyPresetVariants')]
    public function test_empty_copy_stamped_preset_heroes_match_committed_baseline(string $variant): void
    {
        $html = \Illuminate\Support\Facades\View::make('site.sections.hero', [
            'section' => [
                'type' => 'hero',
                'title' => '',
                'subtitle' => '',
                'cta_label' => '',
                'eyebrow' => '',
                'variant' => $variant,
            ],
            'sectionIndex' => 0,
            'pageId' => 1,
            'emitMarkers' => false,
            'emitFormMarkers' => false,
            'profile' => ['watermark_enabled' => false],
            'heroImageUrl' => 'https://example.test/hero.jpg',
            'pageType' => 'home',
            'pagesBySlug' => ['contact' => '/contact'],
            'schema' => [],
            'theme' => [],
            'site' => new Site(['hero_copy_style' => null]),
        ])->render();

        $this->assertStringContainsString('data-hero-variant="'.$variant.'"', $html);
        $this->assertStringContainsString('background-color: color-mix(in srgb, var(--brand-primary)', $html);

        $fixturePath = base_path("tests/Fixtures/ByteIdentity/empty-copy-preset-{$variant}.html");
        if ($this->isSeedingEnabled()) {
            file_put_contents($fixturePath, $html);
            $this->assertFileExists($fixturePath);

            return;
        }

        $this->assertFileExists($fixturePath, "Missing fixture {$fixturePath} — seed with BYTE_IDENTITY_SEED=1");
        $this->assertSame(file_get_contents($fixturePath), $html, "empty-copy preset {$variant} drifted from baseline snapshot");
    }

    /**
     * Parse CSS into an ordered sequence of rules, unrolling `@layer` blocks so individual
     * utility and selector rules are compared independently.
     *
     * @return list<string>
     */
    private function extractCssRules(string $css, string $context = ''): array
    {
        $rules = [];
        $len = strlen($css);
        $i = 0;
        $ruleStart = 0;
        $depth = 0;
        $inString = false;
        $stringChar = '';
        $inComment = false;
        $header = '';
        $bodyStart = 0;

        while ($i < $len) {
            $char = $css[$i];

            if ($inComment) {
                if ($char === '*' && $i + 1 < $len && $css[$i + 1] === '/') {
                    $inComment = false;
                    $i += 2;
                    if ($depth === 0 && trim(substr($css, $ruleStart, $i - $ruleStart)) === '') {
                        $ruleStart = $i;
                    }

                    continue;
                }
                $i++;

                continue;
            }

            if ($inString) {
                if ($char === '\\' && $i + 1 < $len) {
                    $i += 2;

                    continue;
                }
                if ($char === $stringChar) {
                    $inString = false;
                }
                $i++;

                continue;
            }

            if ($char === '/' && $i + 1 < $len && $css[$i + 1] === '*') {
                $inComment = true;
                $i += 2;

                continue;
            }

            if ($char === '\\' && $i + 1 < $len) {
                $i += 2;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $inString = true;
                $stringChar = $char;
                $i++;

                continue;
            }

            if ($char === '{') {
                if ($depth === 0) {
                    $header = trim(substr($css, $ruleStart, $i - $ruleStart));
                    $bodyStart = $i + 1;
                }
                $depth++;
                $i++;

                continue;
            }

            if ($char === '}') {
                $depth--;
                $i++;
                if ($depth === 0) {
                    $full = trim(substr($css, $ruleStart, $i - $ruleStart));
                    $body = substr($css, $bodyStart, $i - 1 - $bodyStart);
                    // Recurse into grouping at-rules (@layer/@media/@supports/
                    // @container) so an insertion INSIDE an existing bucket is
                    // an addition, not a mutation of one atomic mega-rule.
                    // Leaf at-rules (@font-face/@keyframes/@property/@page)
                    // stay atomic — their bodies are declarations, not rules.
                    if (preg_match('/^@(layer\b|media\b|supports\b|container\b)/', $header)) {
                        $prefix = $context !== '' ? "{$context} " : '';
                        $nested = $this->extractCssRules($body, $prefix.$header);
                        foreach ($nested as $r) {
                            $rules[] = $r;
                        }
                    } else {
                        $prefix = $context !== '' ? "{$context} " : '';
                        $rules[] = $prefix.$full;
                    }
                    $ruleStart = $i;
                }

                continue;
            }

            if ($char === ';' && $depth === 0) {
                $i++;
                $rule = trim(substr($css, $ruleStart, $i - $ruleStart));
                if ($rule !== '') {
                    $prefix = $context !== '' ? "{$context} " : '';
                    $rules[] = $prefix.$rule;
                }
                $ruleStart = $i;

                continue;
            }

            $i++;
        }

        if ($ruleStart < $len) {
            $remainder = trim(substr($css, $ruleStart));
            if ($remainder !== '') {
                $prefix = $context !== '' ? "{$context} " : '';
                $rules[] = $prefix.$remainder;
            }
        }

        return $rules;
    }

    /**
     * @param  array<string, string>  $palette
     * @return array{0: Site, 1: array<string, GeneratedPage>}
     */
    private function makeCorpusSite(array $palette, string $recipe): array
    {
        $brief = [
            'mood' => 'refined-minimal',
            'display_font' => 'space-grotesk',
            'body_font' => 'inter',
            'heading_scale' => 'balanced',
            'spacing_density' => 'balanced',
            'corner_style' => 'soft',
            'palette' => [
                'primary' => $palette['primary_color'],
                'accent' => $palette['accent_color'],
                'tertiary' => $palette['tertiary_color'],
                'surface' => $palette['surface_color'],
                'surface_alt' => $palette['surface_alt_color'],
                'border' => $palette['border_color'],
                'text' => $palette['text_color'],
                'text_muted' => $palette['text_muted_color'],
            ],
        ];

        $site = Site::factory()->create([
            'business_name' => 'Apex Developments',
            'business_type' => 'Builder',
            'location' => 'Cheshire',
            'theme' => 'trades-bold',
            'home_layout' => $recipe,
            'services_layout' => $recipe,
            'about_layout' => $recipe === 'banded' ? 'precision' : $recipe,
            'design_brief' => $brief,
        ]);

        BusinessProfile::factory()->for($site)->create([
            'profile_data' => [
                'archetype' => 'local_service',
                'lead_form_policy' => 'all',
                'contact' => ['phones' => ['0161 555 0199'], 'emails' => ['info@apex.test']],
                'geo' => ['service_area' => 'Cheshire'],
            ],
        ]);

        $home = $this->makePage($site, 'home', [
            ['type' => 'hero', 'title' => 'Welcome to Apex'],
            ['type' => 'services', 'title' => 'Our Core Services', 'items' => [
                ['title' => 'Home Extensions', 'body' => 'High quality residential extensions.'],
                ['title' => 'Renovations', 'body' => 'Complete home transformations.'],
            ]],
            ['type' => 'trust', 'title' => 'Why Choose Us', 'items' => [
                ['title' => 'Guaranteed Work', 'body' => '10-year insurance backed guarantee.'],
                ['title' => 'Certified Master Builders', 'body' => 'Accredited and vetted.'],
            ]],
            ['type' => 'process', 'title' => 'Our 4-Step Process', 'items' => [
                ['title' => 'Initial Consultation', 'body' => 'We meet on site to discuss your vision.'],
                ['title' => 'Design & Planning', 'body' => 'Architectural plans and structural drawings.'],
            ]],
            ['type' => 'lead_form', 'title' => 'Request a Quote'],
            ['type' => 'cta', 'title' => 'Ready to build your dream home?'],
        ]);

        $service = $this->makePage($site, 'extensions', [
            ['type' => 'intro', 'title' => 'Bespoke Home Extensions', 'body' => 'We design and build premium extensions.'],
            ['type' => 'features', 'title' => "What's Included", 'items' => [
                ['icon' => 'hammer', 'title' => 'Full Project Management', 'body' => 'From planning through handover.'],
                ['icon' => 'check', 'title' => 'Building Regs Compliance', 'body' => 'Signed off by local authorities.'],
            ]],
            ['type' => 'cta', 'title' => 'Get in touch today'],
        ]);

        $about = $this->makePage($site, 'about', [
            ['type' => 'story', 'title' => 'Building Excellence Since 2008', 'body' => 'Founded with a dedication to craft and integrity.'],
            ['type' => 'values', 'title' => 'Our Values', 'items' => [
                ['title' => 'Craftsmanship', 'body' => 'We never cut corners.'],
                ['title' => 'Transparency', 'body' => 'Clear, fixed itemised pricing.'],
            ]],
            ['type' => 'cta', 'title' => 'Work with us'],
        ]);

        $projects = $this->makePage($site, 'projects', [
            ['type' => 'project_gallery', 'title' => 'Featured Projects', 'items' => [
                ['title' => 'Modern Kitchen Extension', 'body' => 'Open-plan living space.'],
            ]],
            ['type' => 'cta', 'title' => 'Start your project'],
        ]);

        $version = SiteVersion::create([
            'site_id' => $site->id,
            'version' => 1,
            'composition' => [
                'nav' => ['items' => []],
                'footer' => ['columns' => [], 'show_credit' => true],
                'theme' => [
                    'key' => 'trades-bold',
                    'primary_override' => $palette['primary_color'],
                    'accent_override' => $palette['accent_color'],
                    'tertiary_override' => $palette['tertiary_color'],
                    'surface_override' => $palette['surface_color'],
                    'surface_alt_override' => $palette['surface_alt_color'],
                    'border_override' => $palette['border_color'],
                    'text_override' => $palette['text_color'],
                    'text_muted_override' => $palette['text_muted_color'],
                ],
                'homepage_page_id' => $home->id,
            ],
            'page_revisions' => [
                ['page_id' => $home->id, 'revision_id' => $home->published_revision_id],
                ['page_id' => $service->id, 'revision_id' => $service->published_revision_id],
                ['page_id' => $about->id, 'revision_id' => $about->published_revision_id],
                ['page_id' => $projects->id, 'revision_id' => $projects->published_revision_id],
            ],
            'published_at' => now(),
        ]);
        SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

        return [$site, [
            'home' => $home,
            'service' => $service,
            'about' => $about,
            'projects' => $projects,
        ]];
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    private function makePage(Site $site, string $pageType, array $sections): GeneratedPage
    {
        $kind = match ($pageType) {
            'home', 'about', 'projects' => PageKind::Core,
            default => PageKind::Service,
        };

        $page = GeneratedPage::factory()->for($site)->create([
            'page_type' => $pageType,
            'kind' => $kind,
            'nav_label' => ucfirst($pageType),
        ]);
        $rev = PageRevision::factory()->for($page, 'page')->create([
            'content_data' => ['sections' => $sections],
        ]);
        $page->update(['published_revision_id' => $rev->id]);

        return $page->fresh();
    }

    private function headerMarkup(string $html): string
    {
        $this->assertSame(1, preg_match('/<header\b[^>]*>.*<\/header>/si', $html, $matches), 'rendered page must contain a header');

        return $matches[0];
    }

    private function normalise(string $html): string
    {
        $html = (string) preg_replace('/csrfToken:\s*"[^"]*"/', 'csrfToken: "__CSRF__"', $html);
        $html = (string) preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value="__CSRF__"', $html);
        $html = (string) preg_replace('/content="[^"]*"([^>]*name="csrf-token")/', 'content="__CSRF__"$1', $html);
        $html = (string) preg_replace('/(id|for)="([a-z]+-)\d+(-[a-z0-9_-]*)"/', '$1="$2PAGEID$3"', $html);
        $html = (string) preg_replace('/data-editable="page\.\d+\./', 'data-editable="page.PAGEID.', $html);
        $html = (string) preg_replace('/\/build(?:-[a-z0-9]+)?\/assets\/[A-Za-z0-9._-]+\.(css|js)/', '/build/assets/HASH.$1', $html);

        return $html;
    }

    private function isSeedingEnabled(): bool
    {
        $raw = getenv('BYTE_IDENTITY_SEED');
        if ($raw === false || $raw === '') {
            $raw = $_SERVER['BYTE_IDENTITY_SEED'] ?? $_ENV['BYTE_IDENTITY_SEED'] ?? '';
        }
        if ($raw === '' || $raw === false) {
            $raw = getenv('BYTE_IDENTITY_UPDATE_FIXTURES');
            if ($raw === false || $raw === '') {
                $raw = $_SERVER['BYTE_IDENTITY_UPDATE_FIXTURES'] ?? $_ENV['BYTE_IDENTITY_UPDATE_FIXTURES'] ?? '';
            }
        }

        return filter_var($raw, FILTER_VALIDATE_BOOL) === true || $raw === '1';
    }
}
