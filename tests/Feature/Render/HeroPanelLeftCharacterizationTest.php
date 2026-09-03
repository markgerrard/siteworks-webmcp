<?php

namespace Tests\Feature\Render;

use App\Models\GeneratedPage;
use App\Models\LayoutPreset;
use App\Models\Site;
use App\Services\Site\PageLayoutRegistry;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HeroPanelLeftCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function nonSceneVars(?string $variant): array
    {
        $section = [
            'type' => 'hero',
            'title' => 'Welcome to Acme',
            'subtitle' => 'Plumbing in Wigan',
            'cta_label' => 'Get a quote',
            'eyebrow' => 'Local experts',
        ];
        if ($variant !== null) {
            $section['variant'] = $variant;
        }

        return [
            'section' => $section,
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
    }

    /**
     * @return array<string, mixed>
     */
    private function sceneVars(?string $variant): array
    {
        $section = ['type' => 'hero'];
        if ($variant !== null) {
            $section['variant'] = $variant;
        }

        return [
            'section' => $section,
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
    }

    public static function legacySnapshotCases(): array
    {
        return [
            'non-scene-none' => ['nonSceneVars', null, 'hero-non-scene-none'],
            'non-scene-boxed-left' => ['nonSceneVars', 'boxed-left', 'hero-non-scene-boxed-left'],
            'non-scene-venue' => ['nonSceneVars', 'venue', 'hero-non-scene-venue'],
            'scene-none' => ['sceneVars', null, 'hero-scene-none'],
            'scene-boxed-left' => ['sceneVars', 'boxed-left', 'hero-scene-boxed-left'],
            'scene-venue' => ['sceneVars', 'venue', 'hero-scene-venue'],
        ];
    }

    #[DataProvider('legacySnapshotCases')]
    public function test_legacy_hero_paths_match_snapshot(string $varsFn, ?string $variant, string $name): void
    {
        $view = str_starts_with($name, 'hero-scene')
            ? 'site.sections._hero_scene'
            : 'site.sections.hero';
        $normalize = fn (string $h): string => trim(preg_replace(['/>\s+</', '/\s+/'], ['><', ' '], $h));
        $html = $normalize(View::make($view, $this->{$varsFn}($variant))->render());
        $path = base_path("tests/fixtures/home-sections/{$name}.html");
        if (! file_exists($path)) {
            @mkdir(dirname($path), 0775, true);
            file_put_contents($path, $html);
            $this->markTestIncomplete("Snapshot created at {$path} — re-run to assert.");
        }
        $this->assertSame(file_get_contents($path), $html, "{$name} drifted from snapshot");
    }

    public function test_dead_venue_token_matches_absent_variant_on_both_paths(): void
    {
        $normalize = fn (string $h): string => trim(preg_replace(['/>\s+</', '/\s+/'], ['><', ' '], $h));

        $this->assertSame(
            $normalize(View::make('site.sections.hero', $this->nonSceneVars(null))->render()),
            $normalize(View::make('site.sections.hero', $this->nonSceneVars('venue'))->render()),
            'non-scene hero: venue must render as absent',
        );
        $this->assertSame(
            $normalize(View::make('site.sections._hero_scene', $this->sceneVars(null))->render()),
            $normalize(View::make('site.sections._hero_scene', $this->sceneVars('venue'))->render()),
            'scene hero: venue must render as absent',
        );
    }

    public function test_panel_left_forces_panel_on_non_scene_hero(): void
    {
        $html = View::make('site.sections.hero', $this->nonSceneVars('panel-left'))->render();
        $this->assertStringContainsString('data-hero-variant="panel-left"', $html);
        $this->assertStringContainsString('color-mix(in srgb, var(--brand-primary)', $html);
        $this->assertStringNotContainsString('data-hero-variant="boxed-left"', $html);
    }

    public function test_panel_left_forces_panel_on_scene_hero_even_when_overlay_is_gradient(): void
    {
        $html = View::make('site.sections._hero_scene', $this->sceneVars('panel-left'))->render();
        $this->assertStringContainsString('data-hero-variant="panel-left"', $html);
        $this->assertStringContainsString('color-mix(in srgb, var(--brand-primary)', $html);
        $this->assertStringNotContainsString('data-hero-overlay-style="gradient"', $html);
    }

    public function test_dead_venue_token_is_unlocked_by_recipe_stamp(): void
    {
        $site = Site::factory()->create(['home_layout' => 'editorial']);
        LayoutPreset::factory()->for($site)->active()->create([
            'page_kind' => 'home',
            'key' => 'editorial',
            'recipe' => [
                'schema_version' => 1,
                'variants' => ['hero' => 'panel-left'],
                'eyebrow_policy' => 'all',
                'insert_sections' => [],
            ],
        ]);
        $page = new GeneratedPage(['page_type' => 'home']);
        $renderer = app(PageRenderer::class);
        $method = new \ReflectionMethod($renderer, 'applyHomeLayout');
        $method->setAccessible(true);

        $out = $method->invoke($renderer, $site, $page, [
            ['type' => 'hero', 'variant' => 'venue'],
        ], 'public');

        $this->assertSame('panel-left', $out[0]['variant']);
    }

    public function test_explicit_null_variant_is_not_unlocked(): void
    {
        $site = Site::factory()->create(['home_layout' => 'editorial']);
        LayoutPreset::factory()->for($site)->active()->create([
            'page_kind' => 'home',
            'key' => 'editorial',
            'recipe' => [
                'schema_version' => 1,
                'variants' => ['hero' => 'panel-left', 'services' => 'photo-cards'],
                'eyebrow_policy' => 'all',
                'insert_sections' => [],
            ],
        ]);
        $page = new GeneratedPage(['page_type' => 'home']);
        $renderer = app(PageRenderer::class);
        $method = new \ReflectionMethod($renderer, 'applyHomeLayout');
        $method->setAccessible(true);

        $out = $method->invoke($renderer, $site, $page, [
            ['type' => 'hero', 'variant' => null],
            ['type' => 'services', 'variant' => 'photo-cards'],
        ], 'public');

        $this->assertNull($out[0]['variant']);
        $this->assertSame('photo-cards', $out[1]['variant']);
    }
}
