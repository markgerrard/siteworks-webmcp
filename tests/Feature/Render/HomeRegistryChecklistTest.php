<?php

namespace Tests\Feature\Render;

use App\Models\GeneratedPage;
use App\Models\LayoutPreset;
use App\Models\Site;
use App\Services\Site\ArchetypeRecipe;
use App\Services\Site\PageLayoutRegistry;
use App\Services\Site\PageRenderer;
use App\Enums\Archetype;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeRegistryChecklistTest extends TestCase
{
    use RefreshDatabase;

    public function test_hero_inline_allowlist_includes_panel_left(): void
    {
        $this->assertContains('panel-left', PageLayoutRegistry::INLINE_VARIANT_FAMILIES['hero']);
        $this->assertContains('boxed-left', PageLayoutRegistry::INLINE_VARIANT_FAMILIES['hero']);
    }

    public function test_surface_consuming_variants_constant_matches_the_files_that_read_the_stamp(): void
    {
        $found = [];
        foreach (glob(resource_path('views/site/sections/variants/*/*.blade.php')) as $path) {
            if (! str_contains((string) file_get_contents($path), '__surface')) {
                continue;
            }
            $family = basename(dirname($path));
            $found[$family][] = basename($path, '.blade.php');
        }
        foreach ($found as &$variants) {
            sort($variants);
        }
        unset($variants);
        ksort($found);

        $declared = [];
        foreach (PageLayoutRegistry::SURFACE_CONSUMING_VARIANTS as $family => $variants) {
            $declared[$family] = array_keys($variants);
            sort($declared[$family]);
        }
        ksort($declared);
        $this->assertSame($found, $declared, 'SURFACE_CONSUMING_VARIANTS must exactly match the blades that read __surface');
    }

    public function test_dead_token_bypass_is_licensed_to_hero_only_among_inline_families(): void
    {
        // Absent-vs-dead byte-identity is characterized for hero (venue) and
        // holds by construction for file-backed families (missing file falls
        // back to classic). cta echoes its token into the DOM and
        // reviews_summary lets it suppress display_style, so dead != absent
        // there — unknown tokens on those families must stay fail-closed.
        $registry = app(PageLayoutRegistry::class);

        $this->assertTrue($registry->isDeadPersistedVariant('hero', 'venue'));
        $this->assertFalse($registry->isDeadPersistedVariant('cta', 'sparkle'));
        $this->assertFalse($registry->isDeadPersistedVariant('reviews_summary', 'masonry'));

        $this->assertFalse($registry->shouldStampVariant(['type' => 'cta', 'variant' => 'sparkle'], 'cta'));
        $this->assertFalse($registry->shouldStampVariant(['type' => 'reviews_summary', 'variant' => 'masonry'], 'reviews_summary'));
        $this->assertTrue($registry->shouldStampVariant(['type' => 'hero', 'variant' => 'venue'], 'hero'));
    }

    public function test_services_photo_cards_moves_to_file_backed(): void
    {
        $this->assertArrayNotHasKey('services', PageLayoutRegistry::INLINE_VARIANT_FAMILIES);
        $registry = app(PageLayoutRegistry::class);
        $ok = [
            'schema_version' => 1,
            'variants' => ['services' => 'photo-cards'],
            'eyebrow_policy' => 'all',
        ];
        $this->assertTrue($registry->isUsable($ok, 'home'));
        $this->assertFalse(
            collect($registry->validate($ok, 'home'))->contains(
                fn (string $e): bool => str_contains($e, 'missing partial'),
            ),
        );

        $missing = [
            'schema_version' => 1,
            'variants' => ['services' => 'ghost-cards'],
            'eyebrow_policy' => 'all',
        ];
        $this->assertTrue(
            collect($registry->validate($missing, 'home'))->contains(
                fn (string $e): bool => str_contains($e, 'ghost-cards') && str_contains($e, 'missing partial'),
            ),
        );
    }

    public function test_portfolio_strip_leaves_the_inline_allowlist(): void
    {
        $this->assertArrayNotHasKey('portfolio_strip', PageLayoutRegistry::INLINE_VARIANT_FAMILIES);
        $registry = app(PageLayoutRegistry::class);
        $ok = [
            'schema_version' => 1,
            'variants' => ['portfolio_strip' => 'dark-band'],
            'eyebrow_policy' => 'all',
        ];
        $this->assertTrue($registry->isUsable($ok, 'home'));
        $this->assertFalse(
            collect($registry->validate($ok, 'home'))->contains(
                fn (string $e): bool => str_contains($e, 'missing partial'),
            ),
        );
    }

    public function test_allowed_home_families_include_extracted_sections_and_features(): void
    {
        foreach (['services', 'trust', 'process', 'portfolio_strip', 'features', 'hero'] as $family) {
            $this->assertContains($family, PageLayoutRegistry::ALLOWED_FAMILIES['home']);
        }
    }

    public function test_home_eyebrow_sections_accept_extracted_families_only(): void
    {
        $registry = app(PageLayoutRegistry::class);
        $ok = [
            'schema_version' => 1,
            'variants' => ['services' => 'photo-cards'],
            'eyebrow_policy' => 'first-only',
            'eyebrow_sections' => ['services', 'trust', 'process', 'portfolio_strip', 'features'],
        ];
        $this->assertTrue($registry->isUsable($ok, 'home'), implode('; ', $registry->validate($ok, 'home')));

        $bad = [
            'schema_version' => 1,
            'variants' => ['hero' => 'boxed-left'],
            'eyebrow_policy' => 'first-only',
            'eyebrow_sections' => ['hero'],
        ];
        $this->assertFalse($registry->isUsable($bad, 'home'));
        $this->assertTrue(
            collect($registry->validate($bad, 'home'))->contains(
                fn (string $e): bool => str_contains($e, 'hero'),
            ),
        );
    }

    public function test_apply_home_layout_wires_first_only_eyebrows_on_extracted_families(): void
    {
        $site = Site::factory()->create(['home_layout' => 'editorial']);
        LayoutPreset::factory()->for($site)->active()->create([
            'page_kind' => 'home',
            'key' => 'editorial',
            'recipe' => [
                'schema_version' => 1,
                'variants' => ['services' => 'photo-cards', 'trust' => 'classic'],
                'eyebrow_policy' => 'first-only',
                'eyebrow_sections' => ['services', 'trust'],
                'insert_sections' => [],
            ],
        ]);
        $page = new GeneratedPage(['page_type' => 'home']);
        $renderer = app(PageRenderer::class);
        $method = new \ReflectionMethod($renderer, 'applyHomeLayout');
        $method->setAccessible(true);

        $out = $method->invoke($renderer, $site, $page, [
            ['type' => 'hero'],
            ['type' => 'services'],
            ['type' => 'trust'],
        ], 'public');

        $this->assertArrayNotHasKey('__suppress_eyebrow', $out[0]);
        $this->assertArrayNotHasKey('__suppress_eyebrow', $out[1]);
        $this->assertTrue($out[2]['__suppress_eyebrow']);
    }

    public function test_retail_venue_weights_stop_emitting_hero_venue(): void
    {
        $recipe = (new ArchetypeRecipe)->for(Archetype::RetailVenue);
        $this->assertNotSame('venue', $recipe['weights']['hero']['variant'] ?? null);
        $this->assertArrayNotHasKey('variant', $recipe['weights']['hero'] ?? []);
    }
}
