<?php

namespace Tests\Feature\Render;

use App\Services\Site\PageLayoutRegistry;
use Tests\TestCase;

class MotionTierRegistryTest extends TestCase
{
    private PageLayoutRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = app(PageLayoutRegistry::class);
    }

    public function test_new_section_families_are_registered_in_file_backed_and_allowed_families(): void
    {
        $this->assertContains('team', PageLayoutRegistry::FILE_BACKED_FAMILIES);
        $this->assertContains('statistics', PageLayoutRegistry::FILE_BACKED_FAMILIES);

        $this->assertContains('team', PageLayoutRegistry::ALLOWED_FAMILIES['home']);
        $this->assertContains('statistics', PageLayoutRegistry::ALLOWED_FAMILIES['home']);

        $this->assertContains('team', PageLayoutRegistry::ALLOWED_FAMILIES['about']);
        $this->assertContains('statistics', PageLayoutRegistry::ALLOWED_FAMILIES['about']);

        $this->assertContains('team', PageLayoutRegistry::ALLOWED_FAMILIES['service']);
        $this->assertContains('statistics', PageLayoutRegistry::ALLOWED_FAMILIES['service']);
    }

    public function test_cta_inline_variant_family_includes_marquee_slots(): void
    {
        $this->assertArrayHasKey('cta', PageLayoutRegistry::INLINE_VARIANT_FAMILIES);
        $this->assertContains('marquee-band', PageLayoutRegistry::INLINE_VARIANT_FAMILIES['cta']);
        $this->assertNotContains('marquee', PageLayoutRegistry::INLINE_VARIANT_FAMILIES['cta']); // dead alias removed (validated but never branched)
    }

    public function test_motion_tier_options_are_known_in_registry(): void
    {
        $recipeWithValidOpts = [
            'schema_version' => 1,
            'variants' => ['services' => 'classic'],
            'options' => [
                'motion_tier' => 'expressive',
                'marquee_band' => true,
                'split_heading_reveal' => false,
                'stat_count_up' => true,
                'logo_tile_hover' => true,
            ],
            'eyebrow_policy' => 'all',
        ];

        $this->assertTrue(
            $this->registry->isUsable($recipeWithValidOpts, 'home'),
            implode('; ', $this->registry->hardErrors($recipeWithValidOpts, 'home')),
        );
    }

    public function test_invalid_motion_tier_value_fails_hard_errors(): void
    {
        $badOption = [
            'schema_version' => 1,
            'variants' => ['services' => 'classic'],
            'options' => [
                'motion_tier' => 'hyperactive',
            ],
            'eyebrow_policy' => 'all',
        ];

        $this->assertFalse($this->registry->isUsable($badOption, 'home'));
        $errors = $this->registry->hardErrors($badOption, 'home');
        $this->assertTrue(
            collect($errors)->contains(fn ($e) => str_contains($e, 'motion_tier')),
            'Expected error containing motion_tier',
        );

        $badRoot = [
            'schema_version' => 1,
            'variants' => ['services' => 'classic'],
            'motion_tier' => 'hyperactive',
            'eyebrow_policy' => 'all',
        ];

        $this->assertFalse($this->registry->isUsable($badRoot, 'home'));
        $errorsRoot = $this->registry->hardErrors($badRoot, 'home');
        $this->assertTrue(
            collect($errorsRoot)->contains(fn ($e) => str_contains($e, 'motion_tier')),
            'Expected error containing motion_tier',
        );
    }

    public function test_absent_motion_tier_uses_early_return_and_does_not_force_keys(): void
    {
        $recipe = [
            'schema_version' => 1,
            'variants' => ['services' => 'classic'],
            'options' => ['featured_count' => 3],
            'eyebrow_policy' => 'all',
        ];

        $this->assertTrue($this->registry->isUsable($recipe, 'home'));

        $expanded = $this->registry->expandMotionTier($recipe);
        $this->assertSame($recipe, $expanded, 'Recipe without motion_tier must not be modified');
    }

    public function test_motion_tier_macro_expands_to_expected_per_device_options(): void
    {
        $subtleRecipe = [
            'schema_version' => 1,
            'variants' => ['services' => 'classic'],
            'options' => [
                'motion_tier' => 'subtle',
            ],
            'eyebrow_policy' => 'all',
        ];

        $expandedSubtle = $this->registry->expandMotionTier($subtleRecipe);
        $this->assertFalse($expandedSubtle['options']['marquee_band']);
        $this->assertFalse($expandedSubtle['options']['split_heading_reveal']);
        $this->assertTrue($expandedSubtle['options']['stat_count_up']);
        $this->assertTrue($expandedSubtle['options']['logo_tile_hover']);

        $expressiveRecipe = [
            'schema_version' => 1,
            'variants' => ['services' => 'classic'],
            'motion_tier' => 'expressive',
            'eyebrow_policy' => 'all',
        ];

        $expandedExpressive = $this->registry->expandMotionTier($expressiveRecipe);
        $this->assertTrue($expandedExpressive['options']['marquee_band']);
        $this->assertTrue($expandedExpressive['options']['split_heading_reveal']);
        $this->assertTrue($expandedExpressive['options']['stat_count_up']);
        $this->assertTrue($expandedExpressive['options']['logo_tile_hover']);
    }

    public function test_explicit_device_options_override_motion_tier_macro_defaults(): void
    {
        $recipe = [
            'schema_version' => 1,
            'variants' => ['services' => 'classic'],
            'options' => [
                'motion_tier' => 'expressive',
                'marquee_band' => false,
            ],
            'eyebrow_policy' => 'all',
        ];

        $expanded = $this->registry->expandMotionTier($recipe);
        $this->assertFalse($expanded['options']['marquee_band'], 'Explicit marquee_band=false must override expressive macro default');
        $this->assertTrue($expanded['options']['stat_count_up']);
        $this->assertTrue($expanded['options']['split_heading_reveal']);
        $this->assertTrue($expanded['options']['logo_tile_hover']);
    }
}
