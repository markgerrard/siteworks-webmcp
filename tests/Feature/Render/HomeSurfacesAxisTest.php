<?php

namespace Tests\Feature\Render;

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Services\Site\PageLayoutRegistry;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class HomeSurfacesAxisTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function homeRecipe(array $overrides = []): array
    {
        return array_merge([
            'schema_version' => 1,
            'variants' => ['hero' => 'boxed-left', 'services' => 'photo-cards'],
            'eyebrow_policy' => 'all',
            'insert_sections' => [],
        ], $overrides);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array<string, mixed>>
     */
    private function callHome(Site $site, GeneratedPage $page, array $sections): array
    {
        $renderer = app(PageRenderer::class);
        $method = new \ReflectionMethod($renderer, 'applyHomeLayout');
        $method->setAccessible(true);

        return $method->invoke($renderer, $site, $page, $sections, 'public');
    }

    public function test_surfaces_contrast_on_stamped_family_is_usable_for_home(): void
    {
        $recipe = $this->homeRecipe([
            'surfaces' => ['services' => 'contrast'],
        ]);
        $registry = app(PageLayoutRegistry::class);

        $this->assertTrue($registry->isUsable($recipe, 'home'));
        $this->assertSame([], array_values(array_filter(
            $registry->validate($recipe, 'home'),
            fn (string $e): bool => ! str_starts_with($e, 'Warning:'),
        )));
    }

    public function test_surfaces_unknown_value_is_a_hard_error(): void
    {
        $recipe = $this->homeRecipe([
            'surfaces' => ['services' => 'default'],
        ]);
        $errors = app(PageLayoutRegistry::class)->validate($recipe, 'home');

        $this->assertTrue(
            collect($errors)->contains(fn (string $e): bool => str_contains($e, 'surfaces') && str_contains($e, 'default')),
        );
        $this->assertFalse(app(PageLayoutRegistry::class)->isUsable($recipe, 'home'));
    }

    public function test_surfaces_key_not_in_variants_is_a_hard_error(): void
    {
        $recipe = $this->homeRecipe([
            'surfaces' => ['trust' => 'contrast'],
        ]);
        $errors = app(PageLayoutRegistry::class)->validate($recipe, 'home');

        $this->assertTrue(
            collect($errors)->contains(fn (string $e): bool => str_contains($e, 'surfaces') && str_contains($e, 'trust')),
        );
        $this->assertFalse(app(PageLayoutRegistry::class)->isUsable($recipe, 'home'));
    }

    public function test_surfaces_on_non_home_kind_is_a_hard_error(): void
    {
        $recipe = [
            'schema_version' => 1,
            'variants' => ['intro' => 'editorial', 'features' => 'numbered'],
            'eyebrow_policy' => 'first-only',
            'surfaces' => ['intro' => 'contrast'],
        ];
        $errors = app(PageLayoutRegistry::class)->validate($recipe, 'service');

        $this->assertTrue(
            collect($errors)->contains(fn (string $e): bool => str_contains($e, 'surfaces')),
        );
        $this->assertFalse(app(PageLayoutRegistry::class)->isUsable($recipe, 'service'));
    }

    public function test_apply_home_layout_stamps_surface_verbatim_on_home_sections(): void
    {
        $site = Site::factory()->create(['home_layout' => 'editorial']);
        \App\Models\LayoutPreset::factory()->for($site)->active()->create([
            'page_kind' => 'home',
            'key' => 'editorial',
            'recipe' => $this->homeRecipe([
                'variants' => ['services' => 'photo-cards'],
                'surfaces' => ['services' => 'contrast'],
            ]),
        ]);
        $page = new GeneratedPage(['page_type' => 'home']);

        $out = $this->callHome($site, $page, [
            ['type' => 'hero'],
            ['type' => 'services'],
            ['type' => 'trust'],
            ['type' => 'services', '__page_type' => 'extensions'],
        ]);

        $this->assertSame('contrast', $out[1]['__surface']);
        $this->assertArrayNotHasKey('__surface', $out[0]);
        $this->assertArrayNotHasKey('__surface', $out[2]);
        $this->assertArrayNotHasKey('__surface', $out[3], 'stacked non-home services must not receive home surfaces');
    }

    public function test_persisted_dark_band_never_receives_an_inert_surface_stamp(): void
    {
        $site = Site::factory()->create(['home_layout' => 'bespoke']);
        \App\Models\LayoutPreset::factory()->for($site)->active()->create([
            'page_kind' => 'home',
            'key' => 'bespoke',
            'recipe' => $this->homeRecipe([
                'variants' => ['portfolio_strip' => 'classic'],
                'surfaces' => ['portfolio_strip' => 'contrast'],
            ]),
        ]);
        $page = new GeneratedPage(['page_type' => 'home']);

        $out = $this->callHome($site, $page, [
            ['type' => 'portfolio_strip', 'variant' => 'dark-band', 'item_ids' => [11, 12]],
            ['type' => 'portfolio_strip'],
        ]);

        $this->assertSame('dark-band', $out[0]['variant'], 'explicit persisted variant must win');
        $this->assertArrayNotHasKey('__surface', $out[0], 'a fixed-surface effective variant must not be stamped with a surface no wrapper reads');
        $this->assertSame('contrast', $out[1]['__surface'], 'the classic-effective sibling still takes the stamp');
    }

    public function test_surface_stamps_on_a_family_the_recipe_does_not_name_as_a_variant(): void
    {
        $renderer = app(PageRenderer::class);
        $section = ['type' => 'services'];
        \Closure::bind(function () use (&$section) {
            $this->stampSection(
                $section,
                'services',
                ['hero' => 'boxed-left'],
                [],
                ['services' => 'contrast'],
            );
        }, $renderer, PageRenderer::class)();

        $this->assertSame('contrast', $section['__surface']);
        $this->assertArrayNotHasKey('variant', $section);
    }

    public function test_empty_dark_band_portfolio_strip_still_carries_options(): void
    {
        $site = Site::factory()->create(['home_layout' => 'tier1']);
        \App\Models\LayoutPreset::factory()->for($site)->active()->create([
            'page_kind' => 'home',
            'key' => 'tier1',
            'recipe' => $this->homeRecipe([
                'variants' => [
                    'hero' => 'boxed-left',
                    'portfolio_strip' => 'dark-band',
                ],
                'options' => ['featured_count' => 4],
            ]),
        ]);
        $page = new GeneratedPage(['page_type' => 'home']);

        $out = $this->callHome($site, $page, [
            ['type' => 'portfolio_strip'],
        ]);

        $this->assertArrayNotHasKey('variant', $out[0]);
        $this->assertSame(['featured_count' => 4], $out[0]['__options']);
    }

    public function test_surfaces_on_non_consuming_family_is_a_hard_error(): void
    {
        $registry = app(PageLayoutRegistry::class);

        $recipe = $this->homeRecipe([
            'variants' => ['hero' => 'boxed-left', 'cta' => 'accent-band'],
            'surfaces' => ['cta' => 'contrast'],
        ]);

        $this->assertTrue(
            collect($registry->validate($recipe, 'home'))->contains(
                fn (string $e): bool => str_contains($e, 'surfaces') && str_contains($e, 'accent-band'),
            ),
            'surfaces over a family with no __surface consumer must be rejected, not accepted as a no-op',
        );
        $this->assertFalse($registry->isUsable($recipe, 'home'));
    }

    public function test_featured_count_option_validates_as_bounded_int(): void
    {
        $registry = app(PageLayoutRegistry::class);

        $ok = $this->homeRecipe(['options' => ['featured_count' => 4]]);
        $this->assertTrue($registry->isUsable($ok, 'home'));

        foreach ([0, 9, '4', 4.5] as $bad) {
            $recipe = $this->homeRecipe(['options' => ['featured_count' => $bad]]);
            $this->assertTrue(
                collect($registry->validate($recipe, 'home'))->contains(
                    fn (string $e): bool => str_contains($e, 'featured_count'),
                ),
                'featured_count '.var_export($bad, true).' must be rejected',
            );
        }
    }

    public function test_surfaces_explicit_null_is_a_hard_error(): void
    {
        $registry = app(PageLayoutRegistry::class);
        $recipe = $this->homeRecipe(['surfaces' => null]);

        $this->assertTrue(
            collect($registry->validate($recipe, 'home'))->contains(fn (string $e): bool => str_contains($e, 'surfaces')),
            'explicit surfaces: null must be a hard error, not a second empty state',
        );
        $this->assertFalse($registry->isUsable($recipe, 'home'));
    }

    public function test_surfaces_cannot_target_fixed_surface_dark_band(): void
    {
        $registry = app(PageLayoutRegistry::class);

        $rejected = $this->homeRecipe([
            'variants' => ['hero' => 'boxed-left', 'portfolio_strip' => 'dark-band'],
            'surfaces' => ['portfolio_strip' => 'contrast'],
        ]);
        $this->assertTrue(
            collect($registry->validate($rejected, 'home'))->contains(
                fn (string $e): bool => str_contains($e, 'surfaces') && str_contains($e, 'dark-band'),
            ),
            'surfaces.portfolio_strip over the fixed-surface dark-band variant must be rejected, not accepted as a no-op',
        );
        $this->assertFalse($registry->isUsable($rejected, 'home'));

        $accepted = $this->homeRecipe([
            'variants' => ['hero' => 'boxed-left', 'portfolio_strip' => 'classic'],
            'surfaces' => ['portfolio_strip' => 'contrast'],
        ]);
        $this->assertTrue($registry->isUsable($accepted, 'home'));
    }

    public function test_brand_value_is_rejected_for_contrast_only_variants(): void
    {
        $registry = app(PageLayoutRegistry::class);
        $recipe = $this->homeRecipe([
            'variants' => ['hero' => 'boxed-left', 'services' => 'photo-cards'],
            'surfaces' => ['services' => 'brand'],
        ]);

        $this->assertTrue(
            collect($registry->validate($recipe, 'home'))->contains(
                fn (string $e): bool => str_contains($e, 'surfaces') && str_contains($e, 'brand'),
            ),
            'brand must fail closed on a variant that only consumes contrast',
        );
        $this->assertFalse($registry->isUsable($recipe, 'home'));
    }

    public function test_unknown_surface_value_names_the_enum(): void
    {
        $registry = app(PageLayoutRegistry::class);
        $recipe = $this->homeRecipe(['surfaces' => ['services' => 'neon']]);

        $this->assertTrue(
            collect($registry->validate($recipe, 'home'))->contains(
                fn (string $e): bool => str_contains($e, 'neon') && str_contains($e, 'contrast'),
            ),
        );
    }

    public function test_contrast_band_swaps_accent_and_hairline_chrome(): void
    {
        // Every contrast-consuming variant in SURFACE_CONSUMING_VARIANTS.
        // Brand-only consumers (trust/brand-manifesto) fail closed on a
        // contrast stamp, so they are skipped here. checklist-steps keeps
        // one deliberate base-accent label INSIDE its surface-alt card, so it
        // is exempt from the no-base-accent assertion only.
        $cardInternalBaseAccent = ['process/checklist-steps'];

        foreach (PageLayoutRegistry::SURFACE_CONSUMING_VARIANTS as $family => $variants) {
            foreach ($variants as $variant => $values) {
                if (! in_array('contrast', $values, true)) {
                    continue;
                }
                $key = "{$family}/{$variant}";
                $vars = $this->surfaceChromeVars($family, $variant);
                $vars['section']['__surface'] = 'contrast';
                $html = View::make("site.sections.{$family}", $vars)->render();

                $this->assertStringContainsString(
                    'var(--brand-accent-text-on-contrast)',
                    $html,
                    "{$key} contrast band must swap accent chrome to the on-contrast token",
                );
                if (! in_array($key, $cardInternalBaseAccent, true)) {
                    $this->assertStringNotContainsString(
                        'var(--brand-accent-text)',
                        $html,
                        "{$key} contrast band must not paint base-surface accent chrome",
                    );
                    $this->assertStringNotContainsString(
                        'var(--brand-accent-text-on-alt)',
                        $html,
                        "{$key} contrast band must not paint alt-surface accent chrome",
                    );
                }
                $this->assertStringNotContainsString(
                    'color-mix(in oklab, var(--color-text) ',
                    $html,
                    "{$key} contrast band hairlines must not mix from the base-surface text token",
                );

                // Unstamped path keeps the pre-round bytes.
                $vars = $this->surfaceChromeVars($family, $variant);
                $html = View::make("site.sections.{$family}", $vars)->render();
                $this->assertStringNotContainsString(
                    'var(--brand-accent-text-on-contrast)',
                    $html,
                    "{$key} unstamped must not use the on-contrast token",
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function surfaceChromeVars(string $family, string $variant): array
    {
        $vars = $this->sectionVars($family);
        $vars['section']['variant'] = $variant;
        if ($family === 'portfolio_strip') {
            $vars['profile'] = ['portfolio_images' => ['https://example.test/a.jpg', 'https://example.test/b.jpg']];
        }

        return $vars;
    }

    public function test_portfolio_classic_contrast_gets_full_section_spacing(): void
    {
        $vars = $this->sectionVars('portfolio_strip');
        $vars['section']['variant'] = 'classic';
        $vars['profile'] = ['portfolio_images' => ['https://example.test/a.jpg', 'https://example.test/b.jpg']];
        $vars['section']['__surface'] = 'contrast';

        $html = View::make('site.sections.portfolio_strip', $vars)->render();

        $this->assertStringContainsString('site-section-spacing', $html, 'contrast portfolio classic must take full section spacing on the surface boundary');
        $this->assertStringNotContainsString('py-12 md:py-16', $html);
    }

    public function test_dark_band_honors_suppress_eyebrow(): void
    {
        $vars = $this->sectionVars('portfolio_strip');
        $vars['section'] = [
            'type' => 'portfolio_strip',
            'variant' => 'dark-band',
            'title' => 'Featured projects',
            'eyebrow' => 'Our work',
            'item_ids' => [11],
            '__suppress_eyebrow' => true,
        ];
        $vars['itemsById'] = collect([
            11 => (object) [
                'title' => 'Loft conversion',
                'category' => null,
                'image' => (object) ['url' => 'https://example.test/p-11.jpg', 'id' => 11],
            ],
        ]);

        $html = View::make('site.sections.portfolio_strip', $vars)->render();

        $this->assertStringContainsString('Featured projects', $html);
        $this->assertStringNotContainsString('Our work', $html, 'dark-band must honor the first-only eyebrow suppression stamp');
    }

    public function test_stamped_classic_wrappers_swap_to_contrast_tokens(): void
    {
        foreach (['services', 'trust', 'process'] as $family) {
            $vars = $this->sectionVars($family);
            $vars['section']['__surface'] = 'contrast';
            $html = View::make("site.sections.{$family}", $vars)->render();

            $this->assertStringContainsString(
                'background-color: var(--color-surface-contrast)',
                $html,
                "{$family} stamped __surface must paint the contrast band",
            );
            $this->assertStringContainsString('var(--color-text-on-contrast)', $html);
            if ($family !== 'trust') {
                $this->assertStringContainsString('var(--color-text-muted-on-contrast)', $html);
            }
            $this->assertStringNotContainsString(
                'background-color: var(--color-surface-alt)',
                $html,
                "{$family} stamped wrapper must leave the alt token",
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionVars(string $type): array
    {
        return [
            'section' => [
                'type' => $type,
                'title' => 'Heading',
                'intro' => 'Scope intro line.',
                'items' => [
                    ['title' => 'One', 'body' => 'A'],
                    ['title' => 'Two', 'body' => 'B'],
                    ['title' => 'Three', 'body' => 'C'],
                ],
            ],
            'sectionIndex' => 1,
            'pageId' => 42,
            'mode' => 'public',
            'emitMarkers' => false,
            'emitFormMarkers' => false,
            'schema' => [],
            'theme' => [],
            'profile' => ['watermark_enabled' => false],
            'site' => null,
            'pagesBySlug' => [],
            'heroImages' => [],
            'itemsById' => collect(),
        ];
    }
}
