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

class SurfaceContrastInertnessTest extends TestCase
{
    use \Tests\Support\MakesClassicRenderSite;

    use RefreshDatabase;

    /** @var list<string> */
    private const EXISTING_COLOR_KEYS = [
        'primary', 'primary_text', 'primary_text_on_alt',
        'accent', 'accent_text', 'accent_text_on_alt',
        'tertiary', 'surface', 'surface_alt', 'border',
        'text', 'text_on_alt', 'text_muted', 'text_muted_on_alt',
        'band', 'text_on_band', 'band_overlay', 'band_mode',
        'text_on_primary', 'text_on_accent',
    ];

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

    public static function themeKeys(): array
    {
        return [
            '51-eden' => ['51-eden'],
            '52-hunt' => ['52-hunt'],
            '54-nh' => ['54-nh'],
            'light-archetype' => ['light-archetype'],
        ];
    }

    #[DataProvider('themeKeys')]
    public function test_existing_render_token_colours_are_unchanged(string $key): void
    {
        $theme = $this->demoThemes()[$key];
        $tokens = app(ThemeResolver::class)->renderTokens($theme);
        $existing = [];
        foreach (self::EXISTING_COLOR_KEYS as $tokenKey) {
            $this->assertArrayHasKey($tokenKey, $tokens);
            $existing[$tokenKey] = $tokens[$tokenKey];
        }

        $path = base_path("tests/fixtures/home-themes/{$key}-existing-tokens.json");
        $json = json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        if (! file_exists($path)) {
            @mkdir(dirname($path), 0775, true);
            file_put_contents($path, $json);
            $this->markTestIncomplete("Snapshot created at {$path} — re-run to assert.");
        }
        $this->assertSame(file_get_contents($path), $json, "{$key} existing ThemeResolver colour tokens drifted");
    }

    #[DataProvider('themeKeys')]
    public function test_surface_contrast_token_is_polarity_aware_with_floor(string $key): void
    {
        $resolver = app(ThemeResolver::class);
        $theme = $this->demoThemes()[$key];
        $tokens = $resolver->renderTokens($theme);

        $this->assertArrayHasKey('surface_contrast', $tokens);
        $this->assertArrayHasKey('text_on_contrast', $tokens);
        $this->assertArrayHasKey('text_muted_on_contrast', $tokens);

        $surface = $tokens['surface'];
        $contrast = $tokens['surface_contrast'];
        $this->assertNotSame($surface, $contrast, "{$key} contrast token must differ from surface");

        $ratio = $resolver->contrastRatio($contrast, $surface);
        $this->assertGreaterThanOrEqual(1.3, $ratio, "{$key} contrast-vs-surface ratio {$ratio} below 1.3:1");

        $surfaceLum = $resolver->relativeLuminance($surface);
        $contrastLum = $resolver->relativeLuminance($contrast);
        if ($surfaceLum < 0.18) {
            $this->assertGreaterThan($surfaceLum, $contrastLum, "{$key} dark surface must get a lighter contrast band");
        } else {
            $this->assertLessThan($surfaceLum, $contrastLum, "{$key} light surface must get a darker contrast band");
        }

        $this->assertGreaterThanOrEqual(
            7.0,
            $resolver->contrastRatio($tokens['text_on_contrast'], $contrast),
            "{$key} text-on-contrast must reach confident display depth, not bare AA",
        );
        $this->assertGreaterThanOrEqual(
            4.5,
            $resolver->contrastRatio($tokens['text_muted_on_contrast'], $contrast),
            "{$key} muted-on-contrast must pass AA for body copy",
        );

        $this->assertArrayHasKey('accent_text_on_contrast', $tokens);
        $this->assertGreaterThanOrEqual(
            4.5,
            $resolver->contrastRatio($tokens['accent_text_on_contrast'], $contrast),
            "{$key} accent-text-on-contrast must pass WCAG AA against the contrast band",
        );
    }

    #[DataProvider('themeKeys')]
    public function test_token_is_confined_to_root_and_body_does_not_consume_it(string $key): void
    {
        $theme = $this->demoThemes()[$key];
        [$site, $home, $service, $about] = $this->makeClassicSite($theme);

        $renderer = app(PageRenderer::class);
        foreach (['home' => $home, 'service' => $service, 'about' => $about] as $kind => $page) {
            $html = $renderer->render($site, $page->id, mode: 'public');
            [$root, $body] = $this->splitRootAndBody($html);

            $this->assertStringContainsString('--color-surface-contrast:', $root, "{$key}/{$kind} :root missing contrast token");
            $this->assertStringContainsString('--color-text-on-contrast:', $root, "{$key}/{$kind} :root missing text-on-contrast");
            $this->assertStringContainsString('--color-text-muted-on-contrast:', $root, "{$key}/{$kind} :root missing muted-on-contrast");
            $this->assertStringContainsString('--brand-accent-text-on-contrast:', $root, "{$key}/{$kind} :root missing accent-text-on-contrast");

            $this->assertStringNotContainsString('--color-surface-contrast', $body, "{$key}/{$kind} body consumed the contrast token");
            $this->assertStringNotContainsString('--color-text-on-contrast', $body, "{$key}/{$kind} body consumed text-on-contrast");
            $this->assertStringNotContainsString('--color-text-muted-on-contrast', $body, "{$key}/{$kind} body consumed muted-on-contrast");
            $this->assertStringNotContainsString('--brand-accent-text-on-contrast', $body, "{$key}/{$kind} body consumed accent-text-on-contrast");
        }
    }

    public function test_unstamped_classic_variants_stay_on_their_literal_surface_tokens(): void
    {
        foreach (['services', 'trust', 'process'] as $family) {
            $html = View::make("site.sections.{$family}", $this->sectionVars($family))->render();
            $this->assertStringContainsString(
                'background-color: var(--color-surface-alt)',
                $html,
                "{$family}/classic default arm must keep the literal surface-alt token",
            );
            $this->assertStringNotContainsString('--color-surface-contrast', $html);
        }

        $portfolio = View::make('site.sections.portfolio_strip', $this->sectionVars('portfolio_strip'))->render();
        $this->assertStringContainsString('background-color: var(--color-surface)', $portfolio);
        $this->assertStringNotContainsString('--color-surface-contrast', $portfolio);
    }

    /**
     * @param  array<string, string>  $palette
     * @return array{0: Site, 1: GeneratedPage, 2: GeneratedPage, 3: GeneratedPage}
     */
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

    /**
     * @return array<string, mixed>
     */
    private function sectionVars(string $type): array
    {
        $items = [
            ['title' => 'One', 'body' => 'A'],
            ['title' => 'Two', 'body' => 'B'],
            ['title' => 'Three', 'body' => 'C'],
        ];

        $section = match ($type) {
            'portfolio_strip' => [
                'type' => 'portfolio_strip',
                'title' => 'Recent projects',
            ],
            default => [
                'type' => $type,
                'title' => 'Heading',
                'items' => $items,
            ],
        };

        return [
            'section' => $section,
            'sectionIndex' => 1,
            'pageId' => 42,
            'mode' => 'public',
            'emitMarkers' => false,
            'emitFormMarkers' => false,
            'schema' => [],
            'theme' => [],
            'profile' => [
                'watermark_enabled' => false,
                'portfolio_images' => ['https://example.test/p1.jpg'],
            ],
            'site' => null,
            'pagesBySlug' => [],
            'heroImages' => [],
            'itemsById' => collect(),
        ];
    }
}
