<?php

namespace Tests\Feature\Render;

use App\Models\GeneratedPage;
use App\Models\LayoutPreset;
use App\Models\Site;
use App\Services\Site\PageLayoutRegistry;
use App\Services\Site\PageRenderer;
use App\Services\Site\ServiceLayoutRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageLayoutRegistryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function serviceRecipe(array $overrides = []): array
    {
        return array_merge([
            'schema_version' => 1,
            'variants' => ['intro' => 'editorial', 'features' => 'numbered'],
            'eyebrow_policy' => 'first-only',
            'assigner_hints' => ['wants_imagery' => false, 'minimum_features' => 3],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function aboutRecipe(array $overrides = []): array
    {
        return array_merge([
            'schema_version' => 1,
            'variants' => ['story' => 'editorial', 'values' => 'ledger'],
            'eyebrow_policy' => 'first-only',
            'eyebrow_sections' => ['story', 'values'],
            'assigner_hints' => ['wants_imagery' => false, 'minimum_features' => 3],
        ], $overrides);
    }

    private function callPageKindStamp(Site $site, ?GeneratedPage $page, array $sections, string $kind, ?array $pageTypes = null): array
    {
        $renderer = app(PageRenderer::class);
        $method = new \ReflectionMethod($renderer, 'applyPageKindLayout');
        $method->setAccessible(true);

        return $method->invoke($renderer, $site, $page, $sections, $kind, $pageTypes);
    }

    public function test_invalid_option_values_fail_closed_end_to_end(): void
    {
        $site = \App\Models\Site::factory()->create();
        \App\Models\LayoutPreset::factory()->create([
            'site_id' => $site->id, 'page_kind' => 'about', 'key' => 'bad-opts', 'status' => 'active',
            'recipe' => ['schema_version' => 1,
                'variants' => ['story' => 'document', 'values' => 'markers'],
                'eyebrow_policy' => 'all', 'eyebrow_sections' => ['story', 'values'],
                'options' => ['image_alignment' => 'sideways']],
        ]);
        $registry = app(\App\Services\Site\PageLayoutRegistry::class);
        $this->assertNull($registry->resolve($site, 'about'), 'hard-invalid option value must fail closed at resolve');
        $this->assertArrayNotHasKey('bad-opts', $registry->optionsFor($site, 'about'), 'and be absent from options');
    }

    public function test_service_layout_registry_is_deprecated_subclass_alias(): void
    {
        $this->assertTrue(is_a(ServiceLayoutRegistry::class, PageLayoutRegistry::class, true));
        $this->assertInstanceOf(PageLayoutRegistry::class, app(ServiceLayoutRegistry::class));
    }

    public function test_colliding_keys_on_different_kinds_resolve_independently(): void
    {
        $site = Site::factory()->create([
            'services_layout' => 'editorial',
            'about_layout' => 'editorial',
        ]);

        LayoutPreset::factory()->for($site)->create([
            'key' => 'editorial',
            'page_kind' => 'about',
            'status' => 'active',
            'recipe' => $this->aboutRecipe([
                'variants' => ['story' => 'document', 'values' => 'markers'],
                'eyebrow_policy' => 'all',
            ]),
        ]);
        LayoutPreset::factory()->for($site)->create([
            'key' => 'editorial',
            'page_kind' => 'service',
            'status' => 'active',
            'recipe' => $this->serviceRecipe([
                'variants' => ['intro' => 'spec', 'features' => 'markers'],
            ]),
        ]);

        $registry = app(PageLayoutRegistry::class);

        $service = $registry->resolve($site, 'service');
        $about = $registry->resolve($site, 'about');

        $this->assertSame('spec', $service['variants']['intro']);
        $this->assertSame('markers', $service['variants']['features']);
        $this->assertSame('document', $about['variants']['story']);
        $this->assertSame('markers', $about['variants']['values']);
        $this->assertSame('all', $about['eyebrow_policy']);
    }

    public function test_options_for_does_not_shadow_across_kinds(): void
    {
        $site = Site::factory()->create();

        LayoutPreset::factory()->for($site)->create([
            'key' => 'roof-special',
            'page_kind' => 'service',
            'label' => 'Roof Service',
            'status' => 'active',
            'recipe' => $this->serviceRecipe(),
        ]);
        LayoutPreset::factory()->for($site)->create([
            'key' => 'roof-special',
            'page_kind' => 'about',
            'label' => 'Roof About',
            'status' => 'active',
            'recipe' => $this->aboutRecipe(),
        ]);

        $registry = app(PageLayoutRegistry::class);
        $service = $registry->optionsFor($site, 'service');
        $about = $registry->optionsFor($site, 'about');

        $this->assertSame('Roof Service', $service['roof-special']['label']);
        $this->assertSame('Roof About', $about['roof-special']['label']);
        $this->assertArrayNotHasKey('roof-special', $registry->optionsFor($site, 'home'));
    }

    public function test_hard_invalid_about_row_does_not_unset_service_config_key(): void
    {
        $site = Site::factory()->create([
            'services_layout' => 'editorial',
            'about_layout' => 'editorial',
        ]);

        LayoutPreset::factory()->for($site)->create([
            'key' => 'editorial',
            'page_kind' => 'about',
            'status' => 'active',
            'recipe' => $this->aboutRecipe(['schema_version' => '1']),
        ]);

        $registry = app(PageLayoutRegistry::class);

        $this->assertArrayHasKey('editorial', $registry->optionsFor($site, 'service'));
        $this->assertArrayNotHasKey('editorial', $registry->optionsFor($site, 'about'));
        $this->assertSame('editorial', $registry->resolve($site, 'service')['variants']['intro']);
        $this->assertNull($registry->resolve($site, 'about'));
    }

    public function test_resolves_about_stock_editorial_recipe(): void
    {
        $site = Site::factory()->create(['about_layout' => 'editorial']);

        $recipe = app(PageLayoutRegistry::class)->resolve($site, 'about');

        $this->assertIsArray($recipe);
        $this->assertSame('editorial', $recipe['variants']['story']);
        $this->assertSame('ledger', $recipe['variants']['values']);
        $this->assertSame('first-only', $recipe['eyebrow_policy']);
        $this->assertSame(['story', 'values'], $recipe['eyebrow_sections']);
        $this->assertArrayNotHasKey('hero', $recipe['variants']);
    }

    public function test_about_classic_and_unknown_resolve_null(): void
    {
        $classic = Site::factory()->create(['about_layout' => 'classic']);
        $unknown = Site::factory()->create(['about_layout' => 'no-such-preset']);
        $registry = app(PageLayoutRegistry::class);

        $this->assertNull($registry->resolve($classic, 'about'));
        $this->assertNull($registry->resolve($unknown, 'about'));
    }

    public function test_about_stamping_including_stacked_page_type(): void
    {
        $site = new Site(['about_layout' => 'editorial']);
        $aboutPage = new GeneratedPage(['page_type' => 'about']);
        $in = [['type' => 'story'], ['type' => 'values']];
        $out = $this->callPageKindStamp($site, $aboutPage, $in, 'about', ['about']);

        $this->assertSame('editorial', $out[0]['variant']);
        $this->assertSame('ledger', $out[1]['variant']);
        $this->assertArrayNotHasKey('__suppress_eyebrow', $out[0]);
        $this->assertTrue($out[1]['__suppress_eyebrow']);

        $home = new GeneratedPage(['page_type' => 'home']);
        $stacked = [
            ['type' => 'story', '__page_type' => 'home'],
            ['type' => 'story', '__page_type' => 'about'],
            ['type' => 'values', '__page_type' => 'about'],
        ];
        $stackedOut = $this->callPageKindStamp($site, $home, $stacked, 'about', ['about']);
        $this->assertArrayNotHasKey('variant', $stackedOut[0]);
        $this->assertSame('editorial', $stackedOut[1]['variant']);
        $this->assertSame('ledger', $stackedOut[2]['variant']);
    }

    public function test_explicit_null_variant_is_not_stamped(): void
    {
        $site = new Site(['about_layout' => 'editorial']);
        $about = new GeneratedPage(['page_type' => 'about']);
        $in = [
            ['type' => 'story', 'variant' => null],
            ['type' => 'values'],
        ];
        $out = $this->callPageKindStamp($site, $about, $in, 'about', ['about']);

        $this->assertArrayHasKey('variant', $out[0]);
        $this->assertNull($out[0]['variant']);
        $this->assertSame('ledger', $out[1]['variant']);
    }

    public function test_unknown_about_key_is_identity(): void
    {
        $site = new Site(['about_layout' => 'no-such-preset']);
        $in = [['type' => 'story'], ['type' => 'values']];
        $this->assertSame($in, $this->callPageKindStamp($site, new GeneratedPage(['page_type' => 'about']), $in, 'about', ['about']));
    }

    public function test_home_tagged_values_is_never_stamped_by_about_recipe(): void
    {
        $site = new Site(['about_layout' => 'editorial']);
        $home = new GeneratedPage(['page_type' => 'home']);
        $in = [
            ['type' => 'values', '__page_type' => 'home'],
            ['type' => 'values', '__page_type' => 'about'],
        ];
        $out = $this->callPageKindStamp($site, $home, $in, 'about', ['about']);

        $this->assertArrayNotHasKey('variant', $out[0]);
        $this->assertSame('ledger', $out[1]['variant']);
    }

    public function test_first_only_eyebrow_counter_walks_only_eyebrow_sections(): void
    {
        $site = new Site(['about_layout' => 'editorial']);
        $about = new GeneratedPage(['page_type' => 'about']);
        $in = [
            ['type' => 'hero'],
            ['type' => 'story'],
            ['type' => 'values'],
        ];
        $out = $this->callPageKindStamp($site, $about, $in, 'about', ['about']);

        $this->assertArrayNotHasKey('variant', $out[0]);
        $this->assertArrayNotHasKey('__suppress_eyebrow', $out[0]);
        $this->assertSame('editorial', $out[1]['variant']);
        $this->assertArrayNotHasKey('__suppress_eyebrow', $out[1]);
        $this->assertTrue($out[2]['__suppress_eyebrow']);
    }

    public function test_apply_services_layout_wrapper_still_delegates(): void
    {
        $site = new Site(['services_layout' => 'editorial']);
        $renderer = app(PageRenderer::class);
        $method = new \ReflectionMethod($renderer, 'applyServicesLayout');
        $method->setAccessible(true);
        $out = $method->invoke(
            $renderer,
            $site,
            new GeneratedPage(['page_type' => 'service']),
            [['type' => 'intro'], ['type' => 'features']],
            ['service'],
        );

        $this->assertSame('editorial', $out[0]['variant']);
        $this->assertSame('numbered', $out[1]['variant']);
    }

    public function test_missing_partial_errors_only_for_file_backed_families(): void
    {
        $registry = app(PageLayoutRegistry::class);

        $fileBacked = $this->aboutRecipe([
            'variants' => ['story' => 'magazine', 'values' => 'ledger'],
        ]);
        $fileErrors = $registry->validate($fileBacked, 'about');
        $this->assertTrue(
            collect($fileErrors)->contains(fn (string $e): bool => str_contains($e, 'magazine') && ! str_starts_with($e, 'Warning:')),
        );
        $this->assertTrue($registry->isUsable($fileBacked, 'about'));

        $inline = [
            'schema_version' => 1,
            'label' => 'Showcase',
            'description' => 'Photo-led',
            'variants' => ['hero' => 'boxed-left', 'services' => 'photo-cards'],
            'eyebrow_policy' => 'all',
            'insert_sections' => ['portfolio_strip'],
        ];
        $inlineErrors = $registry->validate($inline, 'home');
        $this->assertFalse(
            collect($inlineErrors)->contains(fn (string $e): bool => str_contains($e, 'site.sections.variants.hero')),
        );
        $this->assertTrue($registry->isUsable($inline, 'home'));

        $badInline = [
            'schema_version' => 1,
            'variants' => ['hero' => 'not-a-real-token'],
            'eyebrow_policy' => 'all',
        ];
        $badErrors = $registry->validate($badInline, 'home');
        $this->assertTrue(
            collect($badErrors)->contains(fn (string $e): bool => str_contains($e, 'hero') && str_contains($e, 'not-a-real-token')),
        );
    }

    public function test_home_insert_sections_is_live_not_warned_and_allowlisted(): void
    {
        $registry = app(PageLayoutRegistry::class);

        $ok = [
            'schema_version' => 1,
            'variants' => ['hero' => 'boxed-left'],
            'eyebrow_policy' => 'all',
            'insert_sections' => ['portfolio_strip'],
        ];
        $okErrors = $registry->validate($ok, 'home');
        $this->assertFalse(
            collect($okErrors)->contains(fn (string $e): bool => str_contains($e, 'insert_sections') && str_starts_with($e, 'Warning:')),
        );

        $bad = [
            'schema_version' => 1,
            'variants' => ['hero' => 'boxed-left'],
            'eyebrow_policy' => 'all',
            'insert_sections' => ['mystery_band'],
        ];
        $badErrors = $registry->validate($bad, 'home');
        $this->assertTrue(
            collect($badErrors)->contains(fn (string $e): bool => str_contains($e, 'mystery_band') && ! str_starts_with($e, 'Warning:')),
        );

        $service = $registry->validate($this->serviceRecipe([
            'insert_sections' => ['portfolio_strip'],
        ]), 'service');
        $this->assertTrue(
            collect($service)->contains(fn (string $e): bool => str_starts_with($e, 'Warning:') && str_contains($e, 'insert_sections')),
        );
    }

    public function test_same_site_id_and_key_can_exist_per_kind(): void
    {
        $site = Site::factory()->create();

        LayoutPreset::factory()->for($site)->create([
            'key' => 'editorial',
            'page_kind' => 'service',
            'status' => 'active',
            'recipe' => $this->serviceRecipe(),
        ]);
        LayoutPreset::factory()->for($site)->create([
            'key' => 'editorial',
            'page_kind' => 'about',
            'status' => 'active',
            'recipe' => $this->aboutRecipe(),
        ]);

        $this->assertSame(2, LayoutPreset::query()->where('site_id', $site->id)->where('key', 'editorial')->count());
    }

    public function test_about_layout_defaults_to_classic_and_is_fillable(): void
    {
        $site = Site::factory()->create();
        $this->assertSame('classic', $site->fresh()->about_layout);

        $site->update(['about_layout' => 'editorial']);
        $this->assertSame('editorial', $site->fresh()->about_layout);
    }

    public function test_about_recipe_stamping_hero_is_unusable_and_absent_from_options(): void
    {
        $site = Site::factory()->create(['about_layout' => 'hero-bespoke']);
        LayoutPreset::factory()->for($site)->active()->create([
            'page_kind' => 'about',
            'key' => 'hero-bespoke',
            'recipe' => $this->aboutRecipe([
                'variants' => ['hero' => 'boxed-left', 'story' => 'editorial', 'values' => 'ledger'],
            ]),
        ]);

        $registry = app(PageLayoutRegistry::class);
        $recipe = $this->aboutRecipe([
            'variants' => ['hero' => 'boxed-left', 'story' => 'editorial', 'values' => 'ledger'],
        ]);

        $this->assertFalse($registry->isUsable($recipe, 'about'));
        $this->assertTrue(
            collect($registry->validate($recipe, 'about'))
                ->contains(fn (string $e): bool => str_contains($e, 'hero') && ! str_starts_with($e, 'Warning:')),
        );
        $this->assertNull($registry->resolve($site, 'about'));
        $this->assertArrayNotHasKey('hero-bespoke', $registry->optionsFor($site, 'about'));
    }

    public function test_unknown_variant_family_is_unusable_and_absent_from_options(): void
    {
        $site = Site::factory()->create(['about_layout' => 'typo-bespoke']);
        LayoutPreset::factory()->for($site)->active()->create([
            'page_kind' => 'about',
            'key' => 'typo-bespoke',
            'recipe' => $this->aboutRecipe([
                'variants' => ['storys' => 'editorial', 'values' => 'ledger'],
            ]),
        ]);

        $registry = app(PageLayoutRegistry::class);
        $recipe = $this->aboutRecipe([
            'variants' => ['storys' => 'editorial', 'values' => 'ledger'],
        ]);

        $this->assertFalse($registry->isUsable($recipe, 'about'));
        $this->assertTrue(
            collect($registry->validate($recipe, 'about'))
                ->contains(fn (string $e): bool => str_contains($e, 'storys') && ! str_starts_with($e, 'Warning:')),
        );
        $this->assertNull($registry->resolve($site, 'about'));
        $this->assertArrayNotHasKey('typo-bespoke', $registry->optionsFor($site, 'about'));
    }

    public function test_eyebrow_sections_containing_hero_is_a_hard_error(): void
    {
        $registry = app(PageLayoutRegistry::class);
        $recipe = $this->aboutRecipe([
            'eyebrow_sections' => ['hero', 'story', 'values'],
        ]);

        $errors = $registry->validate($recipe, 'about');
        $this->assertTrue(
            collect($errors)->contains(fn (string $e): bool => str_contains($e, 'hero') && str_contains($e, 'eyebrow_sections') && ! str_starts_with($e, 'Warning:')),
        );
        $this->assertFalse($registry->isUsable($recipe, 'about'));
    }

    public function test_artisan_validate_scopes_layout_preset_rows_by_kind(): void
    {
        $site = Site::factory()->create(['services_layout' => 'editorial']);

        LayoutPreset::factory()->for($site)->create([
            'key' => 'editorial',
            'page_kind' => 'about',
            'status' => 'active',
            'recipe' => $this->aboutRecipe([
                'variants' => ['story' => 'Not Valid'],
            ]),
        ]);

        $this->artisan('site:layout', ['site' => (string) $site->id, '--validate' => true])
            ->expectsOutputToContain('valid')
            ->assertSuccessful();
    }

    public function test_the_stock_recipes_carrying_form_treatments_stay_usable(): void
    {
        $r = app(PageLayoutRegistry::class);
        foreach ([['home', 'banded'], ['home', 'showcase'], ['home', 'precision'], ['home', 'editorial'], ['service', 'showcase'], ['service', 'precision'], ['service', 'editorial']] as [$kind, $key]) {
            $recipe = config(($kind === 'home' ? 'site_home_layouts' : 'site_service_layouts').".{$key}");
            // validate() returns the error list itself (list<string>) — never read an 'errors' key off it
            $this->assertSame([], $r->validate($recipe, $kind), "{$kind}.{$key}");
            $this->assertTrue($r->isUsable($recipe, $kind), "{$kind}.{$key}");
            $this->assertNotNull($recipe['variants']['lead_form'] ?? null, "{$kind}.{$key} names lead_form");
        }
    }

    public function test_the_stock_projects_recipes_stay_usable(): void
    {
        $r = app(PageLayoutRegistry::class);
        foreach (['classic', 'editorial', 'showcase', 'precision', 'banded'] as $key) {
            $recipe = config("site_projects_layouts.{$key}");
            $this->assertIsArray($recipe, "projects.{$key}");
            $this->assertSame([], $r->validate($recipe, 'projects'), "projects.{$key}");
            $this->assertTrue($r->isUsable($recipe, 'projects'), "projects.{$key}");
        }
    }
}
