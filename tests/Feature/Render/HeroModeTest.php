<?php

namespace Tests\Feature\Render;

use App\Models\GeneratedPage;
use App\Models\LayoutPreset;
use App\Models\Site;
use App\Services\Site\PageLayoutRegistry;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HeroModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_absent_hero_mode_is_valid_and_reads_force(): void
    {
        $recipe = config('site_home_layouts.editorial');
        $this->assertArrayNotHasKey('hero_mode', $recipe);
        $this->assertSame([], app(PageLayoutRegistry::class)->validate($recipe, 'home'));
    }

    public function test_invalid_value_is_a_hard_error(): void
    {
        $recipe = config('site_home_layouts.editorial') + ['hero_mode' => 'sometimes'];
        $this->assertContains('recipe.hero_mode must be force or default', app(PageLayoutRegistry::class)->hardErrors($recipe, 'home'));
    }

    public function test_hero_mode_on_service_kind_is_a_hard_error(): void
    {
        $recipe = config('site_service_layouts.editorial') + ['hero_mode' => 'default'];
        $this->assertContains('recipe.hero_mode is only valid for the home kind', app(PageLayoutRegistry::class)->hardErrors($recipe, 'service'));
    }

    public function test_absent_hero_section_variant_is_stamped_under_force_and_left_absent_under_default(): void
    {
        $site = Site::factory()->create(['home_layout' => 'tier1']);
        $base = config('site_home_layouts.editorial');
        $sections = [['type' => 'hero', 'title' => 'T']];

        $preset = LayoutPreset::factory()->for($site)->create([
            'page_kind' => 'home',
            'key' => 'tier1',
            'status' => 'active',
            'recipe' => $base + ['hero_mode' => 'force'],
        ]);
        $force = $this->callHome($site, $sections);
        $this->assertSame($base['variants']['hero'], $force[0]['variant']);

        $preset->update(['recipe' => $base + ['hero_mode' => 'default']]);
        $default = $this->callHome($site, $sections);
        $this->assertArrayNotHasKey('variant', $default[0]);
    }

    public function test_live_persisted_hero_variant_is_kept_under_both_modes(): void
    {
        $site = Site::factory()->create(['home_layout' => 'tier1']);
        $base = config('site_home_layouts.editorial');
        $sections = [['type' => 'hero', 'title' => 'T', 'variant' => 'boxed-left']];

        $preset = LayoutPreset::factory()->for($site)->create([
            'page_kind' => 'home',
            'key' => 'tier1',
            'status' => 'active',
            'recipe' => $base + ['hero_mode' => 'default'],
        ]);
        $default = $this->callHome($site, $sections);
        $this->assertSame('boxed-left', $default[0]['variant']);

        $preset->update(['recipe' => $base + ['hero_mode' => 'force']]);
        $force = $this->callHome($site, $sections);
        $this->assertSame('boxed-left', $force[0]['variant']);
    }

    public function test_dead_hero_token_passes_through_under_default_and_restamps_under_force(): void
    {
        $site = Site::factory()->create(['home_layout' => 'tier1']);
        $base = config('site_home_layouts.editorial');
        $sections = [['type' => 'hero', 'title' => 'T', 'variant' => 'dead-token']];

        $preset = LayoutPreset::factory()->for($site)->create([
            'page_kind' => 'home',
            'key' => 'tier1',
            'status' => 'active',
            'recipe' => $base + ['hero_mode' => 'default'],
        ]);
        $default = $this->callHome($site, $sections);
        $this->assertSame('dead-token', $default[0]['variant']);

        $preset->update(['recipe' => $base + ['hero_mode' => 'force']]);
        $force = $this->callHome($site, $sections);
        $this->assertSame($base['variants']['hero'], $force[0]['variant']);
    }

    public function test_absent_hero_mode_matches_force_on_the_same_input(): void
    {
        $site = Site::factory()->create(['home_layout' => 'tier1']);
        $base = config('site_home_layouts.editorial');
        $this->assertArrayNotHasKey('hero_mode', $base);
        $sections = [['type' => 'hero', 'title' => 'T']];

        $preset = LayoutPreset::factory()->for($site)->create([
            'page_kind' => 'home',
            'key' => 'tier1',
            'status' => 'active',
            'recipe' => $base,
        ]);
        $absent = $this->callHome($site, $sections);

        $preset->update(['recipe' => $base + ['hero_mode' => 'force']]);
        $force = $this->callHome($site, $sections);

        $this->assertSame($force, $absent);
        $this->assertSame($base['variants']['hero'], $absent[0]['variant']);
    }

    public function test_default_stamps_hero_options_and_surface_identically_to_force(): void
    {
        $site = Site::factory()->create(['home_layout' => 'tier1']);
        $base = config('site_home_layouts.editorial');
        $sections = [['type' => 'hero', 'title' => 'T']];

        $preset = LayoutPreset::factory()->for($site)->create([
            'page_kind' => 'home',
            'key' => 'tier1',
            'status' => 'active',
            'recipe' => $base + ['hero_mode' => 'force'],
        ]);
        $forceAbsent = $this->callHome($site, $sections);
        $forceLive = $this->callHome($site, [['type' => 'hero', 'title' => 'T', 'variant' => 'boxed-left']]);

        $preset->update(['recipe' => $base + ['hero_mode' => 'default']]);
        $defaultAbsent = $this->callHome($site, $sections);
        $defaultLive = $this->callHome($site, [['type' => 'hero', 'title' => 'T', 'variant' => 'boxed-left']]);

        $this->assertSame($forceAbsent[0]['__options'] ?? null, $defaultAbsent[0]['__options'] ?? null);
        $this->assertSame($forceLive[0]['__options'] ?? null, $defaultLive[0]['__options'] ?? null);
        $this->assertArrayHasKey('__options', $defaultAbsent[0]);
        $this->assertArrayHasKey('__options', $defaultLive[0]);
        $this->assertSame($forceAbsent[0]['__surface'] ?? 'absent', $defaultAbsent[0]['__surface'] ?? 'absent');
        $this->assertSame($forceLive[0]['__surface'] ?? 'absent', $defaultLive[0]['__surface'] ?? 'absent');
        if (! array_key_exists('__surface', $forceAbsent[0])) {
            $this->assertArrayNotHasKey('__surface', $defaultAbsent[0]);
        }
        if (! array_key_exists('__surface', $forceLive[0])) {
            $this->assertArrayNotHasKey('__surface', $defaultLive[0]);
        }
    }

    public function test_default_leaves_explicit_live_hero_variant_untouched(): void
    {
        $site = Site::factory()->create(['home_layout' => 'tier1']);
        $base = config('site_home_layouts.editorial');
        LayoutPreset::factory()->for($site)->create([
            'page_kind' => 'home',
            'key' => 'tier1',
            'status' => 'active',
            'recipe' => $base + ['hero_mode' => 'default'],
        ]);

        $out = $this->callHome($site, [['type' => 'hero', 'title' => 'T', 'variant' => 'boxed-left']]);
        $this->assertSame('boxed-left', $out[0]['variant']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array<string, mixed>>
     */
    private function callHome(Site $site, array $sections): array
    {
        $page = new GeneratedPage(['page_type' => 'home']);
        $renderer = app(PageRenderer::class);
        $method = new \ReflectionMethod($renderer, 'applyHomeLayout');
        $method->setAccessible(true);

        return $method->invoke($renderer, $site, $page, $sections, 'public');
    }
}
