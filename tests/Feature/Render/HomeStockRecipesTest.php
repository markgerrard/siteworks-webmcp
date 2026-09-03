<?php

namespace Tests\Feature\Render;

use App\Models\Site;
use App\Services\Site\PageLayoutRegistry;
use Tests\TestCase;

/**
 * T10: stock home recipes. Classic/showcase are frozen (frozen).
 * Editorial/precision follow the spec personality table.
 */
class HomeStockRecipesTest extends TestCase
{
    /**
     * Pre-round classic array. Any added key, stamp, or copy change is a
     * D0 / frozen regression.
     *
     * @return array<string, mixed>
     */
    private function frozenClassic(): array
    {
        return [
            'label' => 'Classic',
            'description' => 'The standard layout — centred hero, icon service cards, reviews carousel.',
            'schema_version' => 1,
            'variants' => [],
            'insert_sections' => [],
            'eyebrow_policy' => 'all',
            'eyebrow_sections' => [],
        ];
    }

    /**
     * Reviewed (T13-amended) showcase array. The live showcase sites restyle if this
     * drifts (frozen).
     *
     * @return array<string, mixed>
     */
    private function frozenShowcase(): array
    {
        // T13 amendment: showcase now carries lead_form=centered and the three form_* options.
        return [
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
        ];
    }

    public function test_classic_and_showcase_recipes_are_byte_pinned_to_the_reviewed_arrays(): void
    {
        $this->assertSame($this->frozenClassic(), config('site_home_layouts.classic'));
        $this->assertSame($this->frozenShowcase(), config('site_home_layouts.showcase'));
        $this->assertArrayNotHasKey('surfaces', config('site_home_layouts.classic'));
        $this->assertArrayNotHasKey('surfaces', config('site_home_layouts.showcase'));
    }

    public function test_editorial_recipe_matches_spec_table(): void
    {
        $recipe = config('site_home_layouts.editorial');
        $this->assertIsArray($recipe);
        $this->assertSame([
            'hero' => 'panel-left',
            'services' => 'featured-ledger',
            'trust' => 'brand-manifesto',
            'process' => 'stepper',
            'lead_form' => 'inline-editorial',
        ], $recipe['variants']);
        $this->assertSame(['services' => 'contrast', 'trust' => 'brand'], $recipe['surfaces']);
        $this->assertSame([
            'featured_count' => 4,
            'form_input_style' => 'underline',
            'form_surface' => 'panel-inverted',
            'form_trust_style' => 'inline-piped',
            'form_submit_style' => 'auto-arrow',
        ], $recipe['options']);
        $this->assertSame([], $recipe['insert_sections']);
        $this->assertSame('first-only', $recipe['eyebrow_policy']);
        $this->assertSame(['services', 'trust', 'process'], $recipe['eyebrow_sections']);
        $this->assertNotSame('', $recipe['label'] ?? '');
        $this->assertNotSame('', $recipe['description'] ?? '');
    }

    public function test_banded_recipe_matches_spec_table(): void
    {
        $recipe = config('site_home_layouts.banded');
        $this->assertIsArray($recipe);
        $this->assertSame([
            'services' => 'split-bands',
            'trust' => 'checklist-band',
            'process' => 'checklist-steps',
            'lead_form' => 'centered',
        ], $recipe['variants']);
        $this->assertArrayNotHasKey('hero', $recipe['variants'], 'banded deliberately stamps no hero (native hero, D5 direction)');
        $this->assertArrayNotHasKey('surfaces', $recipe);
        $this->assertSame([
            'form_input_style' => 'boxed',
            'form_surface' => 'flat-cream',
            'form_trust_style' => 'chips-under-button',
        ], $recipe['options']);
        $this->assertSame('all', $recipe['eyebrow_policy']);
        $this->assertTrue(app(\App\Services\Site\PageLayoutRegistry::class)->isUsable($recipe, 'home'));
    }

    public function test_precision_recipe_matches_spec_table(): void
    {
        $recipe = config('site_home_layouts.precision');
        $this->assertIsArray($recipe);
        $this->assertSame([
            'hero' => 'panel-left',
            'services' => 'marker-columns',
            'trust' => 'marker-columns',
            'process' => 'marker-columns',
            'lead_form' => 'phone-ledger',
        ], $recipe['variants']);
        $this->assertSame(['process' => 'contrast'], $recipe['surfaces']);
        $this->assertSame([
            'form_input_style' => 'boxed',
            'form_surface' => 'card-on-dark',
            'form_trust_style' => 'tick-list',
        ], $recipe['options']);
        $this->assertSame([], $recipe['insert_sections']);
        $this->assertSame('all', $recipe['eyebrow_policy']);
        $this->assertSame(['services', 'trust', 'process'], $recipe['eyebrow_sections']);
        $this->assertNotSame('', $recipe['label'] ?? '');
        $this->assertNotSame('', $recipe['description'] ?? '');
    }

    public function test_stock_editorial_and_precision_are_usable_home_recipes(): void
    {
        $registry = app(PageLayoutRegistry::class);

        foreach (['editorial', 'precision'] as $key) {
            $recipe = config("site_home_layouts.{$key}");
            $this->assertIsArray($recipe, $key);
            $this->assertTrue(
                $registry->isUsable($recipe, 'home'),
                $key.' hard errors: '.implode('; ', $registry->validate($recipe, 'home')),
            );

            $hard = array_values(array_filter(
                $registry->validate($recipe, 'home'),
                fn (string $e): bool => ! str_starts_with($e, 'Warning:') && ! str_contains($e, 'missing partial'),
            ));
            $this->assertSame([], $hard, $key.' must have no hard validation errors');
        }
    }

    public function test_new_home_families_and_surfaces_validate_on_stock_recipes(): void
    {
        $registry = app(PageLayoutRegistry::class);

        foreach (['editorial', 'precision'] as $key) {
            $recipe = config("site_home_layouts.{$key}");
            $this->assertIsArray($recipe, $key);

            foreach (['services', 'trust', 'process'] as $family) {
                $this->assertArrayHasKey($family, $recipe['variants'], "{$key} must stamp {$family}");
                $this->assertContains($family, PageLayoutRegistry::ALLOWED_FAMILIES['home']);
            }

            $this->assertArrayHasKey('surfaces', $recipe);
            $this->assertFalse(
                collect($registry->validate($recipe, 'home'))->contains(
                    fn (string $e): bool => str_contains($e, 'surfaces') && ! str_starts_with($e, 'Warning:'),
                ),
                $key.' surfaces must pass registry validation',
            );
        }

        $this->assertContains('portfolio_strip', PageLayoutRegistry::ALLOWED_FAMILIES['home']);
    }

    public function test_options_for_home_lists_editorial_and_precision(): void
    {
        $options = app(PageLayoutRegistry::class)->optionsFor(new Site, 'home');

        $this->assertArrayHasKey('classic', $options);
        $this->assertArrayHasKey('showcase', $options);
        $this->assertArrayHasKey('editorial', $options);
        $this->assertArrayHasKey('precision', $options);
        $this->assertNotSame('', $options['editorial']['label']);
        $this->assertNotSame('', $options['editorial']['description']);
        $this->assertNotSame('', $options['precision']['label']);
        $this->assertNotSame('', $options['precision']['description']);
    }
}
