<?php

namespace Tests\Feature\Render;

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use App\Services\Site\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * T12 QA matrix for home presets. Failures are fixed in recipes/tokens,
 * not by weakening these assertions. Fold is measured, not gated.
 */
class HomeLayoutQaMatrixTest extends TestCase
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

    public static function realThemeKeys(): array
    {
        return [
            '51-eden' => ['51-eden'],
            '52-hunt' => ['52-hunt'],
            '54-nh' => ['54-nh'],
        ];
    }

    public static function floorThemeKeys(): array
    {
        return [
            '51-eden' => ['51-eden'],
            '52-hunt' => ['52-hunt'],
            '54-nh' => ['54-nh'],
            'light-archetype' => ['light-archetype'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function personalityThemeMatrix(): array
    {
        $cases = [];
        foreach (['classic', 'showcase', 'editorial', 'precision'] as $personality) {
            foreach (['51-eden', '52-hunt', '54-nh'] as $theme) {
                $cases["{$personality}/{$theme}"] = [$personality, $theme];
            }
        }

        return $cases;
    }

    #[DataProvider('personalityThemeMatrix')]
    public function test_personality_renders_stamped_variants_on_real_themes(string $personality, string $themeKey): void
    {
        $theme = $this->demoThemes()[$themeKey];
        [$site, $home] = $this->makeHomeSite($theme, $personality);
        $html = app(PageRenderer::class)->render($site, $home->id, mode: 'public');

        $this->assertNotSame('', trim($html), "{$personality}/{$themeKey} rendered empty");
        $this->assertStringContainsString('Welcome', $html);
        $this->assertStringContainsString('What We Do', $html);
        $this->assertStringContainsString('Boiler repair', $html);
        $this->assertStringContainsString('Quality Craftsmanship', $html);
        $this->assertStringContainsString('Book a survey', $html);

        $recipe = config("site_home_layouts.{$personality}");
        $this->assertIsArray($recipe);

        [$root, $body] = $this->splitRootAndBody($html);
        $this->assertStringContainsString('--color-surface-contrast:', $root);

        $stamped = $this->applyHome($site, $home, $this->homeSections());
        foreach (['hero', 'services', 'trust', 'process'] as $family) {
            $expected = $recipe['variants'][$family] ?? null;
            $actual = $stamped[$this->sectionIndex($family)]['variant'] ?? null;
            if (is_string($expected) && $expected !== '') {
                $this->assertSame($expected, $actual, "{$personality}/{$themeKey} {$family} stamp");
            } else {
                $this->assertNull($actual, "{$personality}/{$themeKey} {$family} must stay unstamped");
            }
        }

        match ($personality) {
            'editorial' => $this->assertEditorialHtml($html, $body),
            'precision' => $this->assertPrecisionHtml($html, $body),
            'showcase' => $this->assertShowcaseHtml($html, $body),
            default => $this->assertClassicHtml($html, $body),
        };
    }

    #[DataProvider('floorThemeKeys')]
    public function test_rhythm_floor_covers_surface_and_surface_alt_adjacency(string $key): void
    {
        $resolver = app(ThemeResolver::class);
        $tokens = $resolver->renderTokens($this->demoThemes()[$key]);

        $this->assertArrayHasKey('surface_contrast', $tokens);
        $surfaceRatio = $resolver->contrastRatio($tokens['surface_contrast'], $tokens['surface']);
        $altRatio = $resolver->contrastRatio($tokens['surface_contrast'], $tokens['surface_alt']);

        $this->assertGreaterThanOrEqual(
            1.3,
            $surfaceRatio,
            "{$key} contrast-vs-surface {$surfaceRatio} below 1.3:1",
        );
        $this->assertGreaterThanOrEqual(
            1.3,
            $altRatio,
            "{$key} contrast-vs-surface-alt {$altRatio} below 1.3:1",
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function overridePersonalityThemeMatrix(): array
    {
        $cases = [];
        foreach (['showcase', 'editorial', 'precision'] as $personality) {
            foreach (['51-eden', '52-hunt', '54-nh'] as $theme) {
                $cases["override-{$personality}/{$theme}"] = [$personality, $theme];
            }
        }

        return $cases;
    }

    #[DataProvider('overridePersonalityThemeMatrix')]
    public function test_per_page_override_stamps_personality_when_site_column_is_classic(string $personality, string $themeKey): void
    {
        $theme = $this->demoThemes()[$themeKey];
        [$site, $home] = $this->makeHomeSite($theme, 'classic');
        $home->update(['layout_preset_key' => $personality]);

        $html = app(PageRenderer::class)->render($site, $home->id, mode: 'public');
        $this->assertNotSame('', trim($html), "override {$personality}/{$themeKey} rendered empty");
        $this->assertStringContainsString('Welcome', $html);

        $recipe = config("site_home_layouts.{$personality}");
        $this->assertIsArray($recipe);

        $stamped = $this->applyHome($site, $home->fresh(), $this->homeSections());
        foreach (['hero', 'services', 'trust', 'process'] as $family) {
            $expected = $recipe['variants'][$family] ?? null;
            $actual = $stamped[$this->sectionIndex($family)]['variant'] ?? null;
            if (is_string($expected) && $expected !== '') {
                $this->assertSame($expected, $actual, "override {$personality}/{$themeKey} {$family} stamp");
            } else {
                $this->assertNull($actual, "override {$personality}/{$themeKey} {$family} must stay unstamped");
            }
        }

        [$root, $body] = $this->splitRootAndBody($html);
        match ($personality) {
            'editorial' => $this->assertEditorialHtml($html, $body),
            'precision' => $this->assertPrecisionHtml($html, $body),
            'showcase' => $this->assertShowcaseHtml($html, $body),
            default => $this->fail("unexpected personality {$personality}"),
        };
        $this->assertStringContainsString('--color-surface-contrast:', $root);
    }

    public function test_classic_and_showcase_recipes_remain_deep_equal_to_the_reviewed_arrays(): void
    {
        $this->assertSame([
            'label' => 'Classic',
            'description' => 'The standard layout — centred hero, icon service cards, reviews carousel.',
            'schema_version' => 1,
            'variants' => [],
            'insert_sections' => [],
            'eyebrow_policy' => 'all',
            'eyebrow_sections' => [],
        ], config('site_home_layouts.classic'));

        // T13 amendment: showcase now carries lead_form=centered and the three form_* options.
        $this->assertSame([
            'label' => 'Showcase',
            'description' => 'Photo-led layout — boxed hero panel, photo service cards, a featured-projects band, and a bold accent CTA.',
            'schema_version' => 1,
            'variants' => [
                'hero' => 'boxed-left',
                'services' => 'photo-cards',
                'reviews_summary' => 'grid',
                'portfolio_strip' => 'dark-band',
                'lead_form' => 'centered',
            ],
            'options' => [
                'form_input_style' => 'boxed',
                'form_surface' => 'flat-cream',
                'form_trust_style' => 'chips-under-button',
            ],
            'insert_sections' => ['portfolio_strip'],
            'eyebrow_policy' => 'all',
            'eyebrow_sections' => [],
        ], config('site_home_layouts.showcase'));

        $this->assertArrayNotHasKey('surfaces', config('site_home_layouts.classic'));
        $this->assertArrayNotHasKey('surfaces', config('site_home_layouts.showcase'));
    }

    #[DataProvider('realThemeKeys')]
    public function test_classic_inertness_body_does_not_consume_contrast_tokens(string $key): void
    {
        [$site, $home] = $this->makeHomeSite($this->demoThemes()[$key], 'classic');
        $html = app(PageRenderer::class)->render($site, $home->id, mode: 'public');
        [$root, $body] = $this->splitRootAndBody($html);

        $this->assertStringContainsString('--color-surface-contrast:', $root);
        $this->assertStringNotContainsString('--color-surface-contrast', $body);
        $this->assertStringNotContainsString('--color-text-on-contrast', $body);
        $this->assertStringNotContainsString('--color-text-muted-on-contrast', $body);
    }

    public function test_dead_venue_token_still_matches_absent_on_both_hero_paths(): void
    {
        $normalize = fn (string $h): string => trim(preg_replace(['/>\s+</', '/\s+/'], ['><', ' '], $h));

        $nonScene = [
            'section' => ['type' => 'hero', 'title' => 'Welcome to Acme', 'subtitle' => 'Plumbing in Wigan', 'cta_label' => 'Get a quote', 'eyebrow' => 'Local experts'],
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
        ];
        $nonSceneVenue = $nonScene;
        $nonSceneVenue['section']['variant'] = 'venue';
        $this->assertSame(
            $normalize(View::make('site.sections.hero', $nonScene)->render()),
            $normalize(View::make('site.sections.hero', $nonSceneVenue)->render()),
            'non-scene hero: venue must render as absent',
        );

        $scene = [
            'section' => ['type' => 'hero'],
            'scene' => [
                'kind' => 'image',
                'slides' => [
                    [
                        'heading' => 'Slide 1',
                        'subheading' => 'First view',
                        'cta_label' => 'Get a quote',
                        'asset_url' => 'https://example.test/slide-1.webp',
                        'text_zone' => 'middle-left',
                        'dwell_secs' => 6,
                    ],
                    [
                        'heading' => 'Slide 2',
                        'subheading' => 'Second view',
                        'cta_label' => 'Get a quote',
                        'asset_url' => 'https://example.test/slide-2.webp',
                        'text_zone' => 'middle-left',
                        'dwell_secs' => 6,
                    ],
                ],
                'transitions' => [['type' => 'fade', 'duration_secs' => 1.0]],
                'overlay_style' => 'gradient',
            ],
            'heroH' => '55vh',
            'heroMinH' => '280px',
            'profile' => [],
            'pagesBySlug' => ['contact' => '/contact'],
            'site' => new Site,
            'pageId' => 1,
            'sectionIndex' => 0,
            'emitMarkers' => false,
            'sceneEyebrowOverride' => 'Local experts',
            'sceneAccentWord' => null,
        ];
        $sceneVenue = $scene;
        $sceneVenue['section']['variant'] = 'venue';
        $this->assertSame(
            $normalize(View::make('site.sections._hero_scene', $scene)->render()),
            $normalize(View::make('site.sections._hero_scene', $sceneVenue)->render()),
            'scene hero: venue must render as absent',
        );
    }

    public function test_classic_editorial_classic_render_round_trips(): void
    {
        [$site, $home] = $this->makeHomeSite($this->demoThemes()['51-eden'], 'classic');
        $renderer = app(PageRenderer::class);

        $classic = $renderer->render($site, $home->id, mode: 'public');
        $site->update(['home_layout' => 'editorial']);
        $editorial = $renderer->render($site->fresh(), $home->id, mode: 'public');
        $this->assertNotSame($classic, $editorial);
        $this->assertStringContainsString('data-svc-variant="featured-ledger"', $editorial);
        $this->assertStringContainsString('data-svc-variant="brand-manifesto"', $editorial);
        $this->assertStringContainsString('background-color: var(--brand-primary)', $editorial);
        $this->assertStringContainsString('data-svc-variant="stepper"', $editorial);
        $this->assertStringNotContainsString('data-svc-variant="numbered-rows"', $editorial);

        $site->update(['home_layout' => 'classic']);
        $again = $renderer->render($site->fresh(), $home->id, mode: 'public');
        $this->assertSame($classic, $again);
    }

    /**
     * Fold is a measurement, not a gate. Hero heights are vh-driven;
     * first-band position at 1440x900 is recorded for reference.
     */
    public function test_fold_smoke_measures_first_band_hero_vh_without_gating(): void
    {
        $measurements = [];
        foreach (['classic', 'showcase', 'editorial', 'precision'] as $personality) {
            [$site, $home] = $this->makeHomeSite($this->demoThemes()['51-eden'], $personality);
            $html = app(PageRenderer::class)->render($site, $home->id, mode: 'public');
            $vh = $this->extractHeroVh($html);
            $this->assertNotNull($vh, "{$personality} hero height is not vh-driven");
            $firstBandPx = (int) round($vh / 100 * 900);
            $measurements[$personality] = [
                'hero_vh' => $vh,
                'first_band_at_1440x900_px' => $firstBandPx,
                'inside_900' => $firstBandPx < 900,
            ];
        }

        $json = json_encode($measurements, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $path = storage_path('logs/home-fold-smoke.json');
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, $json);
        @mkdir('/tmp/homeround-run', 0775, true);
        file_put_contents('/tmp/homeround-run/home-fold-smoke.json', $json);
        $this->assertFileExists($path);
        $this->assertFileExists('/tmp/homeround-run/home-fold-smoke.json');
    }

    private function assertEditorialHtml(string $html, string $body): void
    {
        $this->assertStringContainsString('data-hero-variant="panel-left"', $html);
        $this->assertStringContainsString('data-svc-variant="featured-ledger"', $html);
        $this->assertStringContainsString('data-svc-variant="brand-manifesto"', $html);
        $this->assertStringContainsString('data-svc-variant="stepper"', $html);
        $this->assertStringContainsString('--color-surface-contrast', $body);
        $this->assertStringContainsString('site-section-spacing', $html);
    }

    private function assertPrecisionHtml(string $html, string $body): void
    {
        $this->assertStringContainsString('data-hero-variant="panel-left"', $html);
        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertStringContainsString('--color-surface-contrast', $body);
    }

    private function assertShowcaseHtml(string $html, string $body): void
    {
        $this->assertStringContainsString('data-hero-variant="boxed-left"', $html);
        $this->assertStringNotContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringNotContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertStringNotContainsString('--color-surface-contrast', $body);
    }

    private function assertClassicHtml(string $html, string $body): void
    {
        $this->assertStringNotContainsString('data-hero-variant="panel-left"', $html);
        $this->assertStringNotContainsString('data-hero-variant="boxed-left"', $html);
        $this->assertStringNotContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringNotContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertStringNotContainsString('--color-surface-contrast', $body);
    }

    /**
     * @param  array<string, string>  $palette
     * @return array{0: Site, 1: GeneratedPage}
     */
    private function makeHomeSite(array $palette, string $homeLayout): array
    {
        $brief = [
            'mood' => 'refined-minimal',
            'display_font' => 'inter',
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
            'business_name' => 'Acme',
            'theme' => 'trades-bold',
            'home_layout' => $homeLayout,
            'services_layout' => 'classic',
            'about_layout' => 'classic',
            'design_brief' => $brief,
        ]);

        $home = $this->makePage($site, 'home', $this->homeSections());
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
                ['page_id' => $home->id, 'revision_id' => $home->published_revision_id],
            ],
            'published_at' => now(),
        ]);
        SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

        return [$site, $home];
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    private function makePage(Site $site, string $pageType, array $sections): GeneratedPage
    {
        $page = GeneratedPage::factory()->for($site)->create(['page_type' => $pageType]);
        $rev = PageRevision::factory()->for($page, 'page')->create([
            'content_data' => ['sections' => $sections],
        ]);
        $page->update(['published_revision_id' => $rev->id]);

        return $page->fresh();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function homeSections(): array
    {
        return [
            ['type' => 'hero', 'title' => 'Welcome', 'subtitle' => 'Local trade'],
            [
                'type' => 'services',
                'title' => 'What We Do',
                'eyebrow' => 'Our Services',
                'items' => [
                    ['icon' => 'wrench', 'title' => 'Boiler repair', 'body' => 'Fast boiler fixes.'],
                    ['icon' => 'bath', 'title' => 'Bathroom fitting', 'body' => 'Full bathroom refits.'],
                ],
            ],
            [
                'type' => 'trust',
                'title' => 'Why homeowners pick us',
                'eyebrow' => 'Why Choose Us',
                'items' => [
                    ['title' => 'Quality Craftsmanship', 'body' => 'Every project completed to an exceptional standard.'],
                    ['title' => 'Honest & Transparent', 'body' => 'Clear upfront quotes with no hidden surprises.'],
                    ['title' => 'London Specialists', 'body' => 'We know the local property landscape.'],
                ],
            ],
            [
                'type' => 'process',
                'title' => 'How it works',
                'eyebrow' => 'Our Process',
                'items' => [
                    ['step' => 1, 'title' => 'Book a survey', 'body' => 'We visit at a time that suits you.'],
                    ['step' => 2, 'title' => 'Receive your quote', 'body' => 'Detailed quote within 24h.'],
                    ['step' => 3, 'title' => 'Work is scheduled', 'body' => 'We confirm dates that work.'],
                ],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    private function applyHome(Site $site, GeneratedPage $page, array $sections): array
    {
        $renderer = app(PageRenderer::class);
        $method = new \ReflectionMethod($renderer, 'applyHomeLayout');
        $method->setAccessible(true);

        return $method->invoke($renderer, $site, $page, $sections, 'public');
    }

    private function sectionIndex(string $type): int
    {
        foreach ($this->homeSections() as $i => $section) {
            if ($section['type'] === $type) {
                return $i;
            }
        }

        $this->fail("missing section {$type}");
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitRootAndBody(string $html): array
    {
        $this->assertMatchesRegularExpression('/:root\s*\{/', $html);
        preg_match_all('/:root\s*\{([^}]+)\}/', $html, $roots);
        $root = implode("\n", $roots[1] ?? []);
        preg_match('/<body\b[^>]*>(.*)<\/body>/is', $html, $body);

        return [$root, $body[1] ?? ''];
    }

    private function extractHeroVh(string $html): ?int
    {
        if (preg_match('/(?:min-)?height:\s*(\d+)vh/', $html, $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
