<?php

namespace Tests\Feature\Render;

use App\Enums\PageKind;
use App\Models\GeneratedPage;
use App\Models\LayoutPreset;
use App\Models\Site;
use App\Services\Site\PageRenderer;
use App\Services\Site\PublicPageCache;
use App\Services\Site\ServiceLayoutRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceLayoutRegistryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function recipe(array $overrides = []): array
    {
        return array_merge([
            'schema_version' => 1,
            'variants' => ['intro' => 'editorial', 'features' => 'numbered'],
            'eyebrow_policy' => 'first-only',
            'assigner_hints' => ['wants_imagery' => false, 'minimum_features' => 3],
        ], $overrides);
    }

    public function test_resolves_config_preset(): void
    {
        $site = Site::factory()->create(['services_layout' => 'editorial']);

        $recipe = app(ServiceLayoutRegistry::class)->resolve($site);

        $this->assertIsArray($recipe);
        $this->assertSame('editorial', $recipe['variants']['intro']);
        $this->assertSame('numbered', $recipe['variants']['features']);
        $this->assertSame('first-only', $recipe['eyebrow_policy']);
    }

    public function test_classic_resolves_to_null(): void
    {
        $site = Site::factory()->create(['services_layout' => 'classic']);

        $this->assertNull(app(ServiceLayoutRegistry::class)->resolve($site));
    }

    public function test_unknown_key_resolves_to_null(): void
    {
        $site = Site::factory()->create(['services_layout' => 'no-such-preset']);

        $this->assertNull(app(ServiceLayoutRegistry::class)->resolve($site));
    }

    public function test_bespoke_row_overrides_config_for_its_site_only(): void
    {
        $owner = Site::factory()->create(['services_layout' => 'editorial']);
        $other = Site::factory()->create(['services_layout' => 'editorial']);

        LayoutPreset::factory()->for($owner)->create([
            'key' => 'editorial',
            'status' => 'active',
            'recipe' => $this->recipe([
                'variants' => ['intro' => 'spec', 'features' => 'markers'],
                'eyebrow_policy' => 'all',
            ]),
        ]);

        $registry = app(ServiceLayoutRegistry::class);

        $owned = $registry->resolve($owner);
        $this->assertSame('spec', $owned['variants']['intro']);
        $this->assertSame('markers', $owned['variants']['features']);
        $this->assertSame('all', $owned['eyebrow_policy']);

        $unrelated = $registry->resolve($other);
        $this->assertSame('editorial', $unrelated['variants']['intro']);
        $this->assertSame('numbered', $unrelated['variants']['features']);
    }

    public function test_draft_and_retired_rows_do_not_resolve(): void
    {
        $site = Site::factory()->create(['services_layout' => 'roof-special']);

        LayoutPreset::factory()->for($site)->create([
            'key' => 'roof-special',
            'status' => 'draft',
            'recipe' => $this->recipe(),
        ]);

        $this->assertNull(app(ServiceLayoutRegistry::class)->resolve($site));

        LayoutPreset::query()->where('site_id', $site->id)->update(['status' => 'retired']);

        $this->assertNull(app(ServiceLayoutRegistry::class)->resolve($site->fresh()));
    }

    public function test_validate_rejects_non_array_variants(): void
    {
        $errors = app(ServiceLayoutRegistry::class)->validate($this->recipe([
            'variants' => 'editorial',
        ]));

        $this->assertNotEmpty($errors);
        $this->assertTrue(
            collect($errors)->contains(fn (string $e): bool => str_contains(strtolower($e), 'variants')),
        );
    }

    public function test_validate_rejects_invalid_variant_names(): void
    {
        $errors = app(ServiceLayoutRegistry::class)->validate($this->recipe([
            'variants' => ['intro' => 'Editorial Split', 'features' => 'cards'],
        ]));

        $this->assertNotEmpty($errors);
        $this->assertTrue(
            collect($errors)->contains(fn (string $e): bool => str_contains($e, 'intro')),
        );
    }

    public function test_validate_rejects_non_integer_schema_version(): void
    {
        $errors = app(ServiceLayoutRegistry::class)->validate($this->recipe([
            'schema_version' => '1',
        ]));

        $this->assertNotEmpty($errors);
        $this->assertTrue(
            collect($errors)->contains(fn (string $e): bool => str_contains($e, 'schema_version')),
        );
    }

    public function test_validate_accepts_a_stock_recipe(): void
    {
        $this->assertSame([], app(ServiceLayoutRegistry::class)->validate($this->recipe()));
    }

    public function test_validate_errors_when_named_variant_partial_is_missing(): void
    {
        $recipe = $this->recipe([
            'variants' => ['intro' => 'magazine', 'features' => 'cards'],
        ]);
        $registry = app(ServiceLayoutRegistry::class);
        $errors = $registry->validate($recipe);

        $this->assertTrue(
            collect($errors)->contains(fn (string $e): bool => str_contains($e, 'magazine') && ! str_starts_with($e, 'Warning:')),
        );
        $this->assertTrue($registry->isUsable($recipe));
    }

    public function test_missing_variant_partial_still_resolves_for_dispatcher_fallback(): void
    {
        $site = Site::factory()->create(['services_layout' => 'magazine-bespoke']);
        LayoutPreset::factory()->for($site)->create([
            'key' => 'magazine-bespoke',
            'status' => 'active',
            'recipe' => $this->recipe([
                'variants' => ['intro' => 'magazine', 'features' => 'cards'],
            ]),
        ]);

        $resolved = app(ServiceLayoutRegistry::class)->resolve($site);

        $this->assertIsArray($resolved);
        $this->assertSame('magazine', $resolved['variants']['intro']);
    }

    public function test_validate_warns_on_non_empty_composition_keys(): void
    {
        $errors = app(ServiceLayoutRegistry::class)->validate($this->recipe([
            'section_order' => ['intro', 'features'],
            'omit_sections' => ['faqs'],
            'insert_sections' => ['portfolio_strip'],
        ]));

        $joined = implode("\n", $errors);
        $this->assertStringContainsString('section_order', $joined);
        $this->assertStringContainsString('omit_sections', $joined);
        $this->assertStringContainsString('insert_sections', $joined);
        $this->assertTrue(
            collect($errors)->every(fn (string $e): bool => str_starts_with($e, 'Warning:')),
            'composition-key messages must be non-fatal warnings',
        );
    }

    public function test_options_for_lists_built_ins_plus_sites_bespoke_only(): void
    {
        $site = Site::factory()->create();
        $other = Site::factory()->create();

        LayoutPreset::factory()->for($site)->create([
            'key' => 'roof-special',
            'label' => 'Roof Special',
            'description' => 'Bespoke roofing layout',
            'status' => 'active',
            'recipe' => $this->recipe(),
        ]);
        LayoutPreset::factory()->for($site)->create([
            'key' => 'draft-only',
            'label' => 'Hidden Draft',
            'status' => 'draft',
            'recipe' => $this->recipe(),
        ]);
        LayoutPreset::factory()->for($other)->create([
            'key' => 'other-site-key',
            'label' => 'Other Site',
            'status' => 'active',
            'recipe' => $this->recipe(),
        ]);

        $options = app(ServiceLayoutRegistry::class)->optionsFor($site);

        foreach (['classic', 'editorial', 'showcase', 'precision'] as $key) {
            $this->assertArrayHasKey($key, $options);
            $this->assertArrayHasKey('label', $options[$key]);
            $this->assertArrayHasKey('description', $options[$key]);
        }

        $this->assertSame('Roof Special', $options['roof-special']['label']);
        $this->assertSame('Bespoke roofing layout', $options['roof-special']['description']);
        $this->assertArrayNotHasKey('draft-only', $options);
        $this->assertArrayNotHasKey('other-site-key', $options);
        $this->assertCount(5, $options);
    }

    public function test_apply_services_layout_uses_registry_bespoke_recipe(): void
    {
        $site = Site::factory()->create(['services_layout' => 'roof-special']);
        LayoutPreset::factory()->for($site)->create([
            'key' => 'roof-special',
            'status' => 'active',
            'recipe' => $this->recipe([
                'variants' => ['intro' => 'split', 'features' => 'checklist'],
            ]),
        ]);

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

        $this->assertSame('split', $out[0]['variant']);
        $this->assertSame('checklist', $out[1]['variant']);
    }

    public function test_saving_a_layout_preset_invalidates_the_public_page_cache(): void
    {
        $site = Site::factory()->create();

        $this->mock(PublicPageCache::class)
            ->shouldReceive('invalidate')
            ->once()
            ->withArgs(fn (Site $s): bool => $s->id === $site->id);

        LayoutPreset::factory()->for($site)->create([
            'key' => 'roof-special',
            'status' => 'active',
            'recipe' => $this->recipe(),
        ]);
    }

    public function test_is_usable_rejects_hard_invalid_recipes_and_accepts_stock(): void
    {
        $registry = app(ServiceLayoutRegistry::class);

        $this->assertTrue($registry->isUsable($this->recipe()));
        $this->assertTrue($registry->isUsable(config('site_service_layouts.editorial')));
        $this->assertTrue($registry->isUsable(config('site_service_layouts.classic')));

        $this->assertFalse($registry->isUsable($this->recipe(['schema_version' => '1'])));
        $this->assertFalse($registry->isUsable($this->recipe(['schema_version' => 2])));
        $this->assertFalse($registry->isUsable($this->recipe(['eyebrow_policy' => 'none'])));
        $this->assertFalse($registry->isUsable($this->recipe([
            'variants' => ['intro' => 'this-name-is-way-too-long'],
        ])));
        $this->assertFalse($registry->isUsable($this->recipe([
            'variants' => ['Not Valid' => 'editorial'],
        ])));
    }

    public function test_is_usable_rejects_unknown_families_and_bad_values(): void
    {
        $registry = app(ServiceLayoutRegistry::class);

        $this->assertFalse($registry->isUsable($this->recipe([
            'variants' => ['phone_cta_strip' => 'editorial'],
        ])));
        $this->assertFalse($registry->isUsable($this->recipe([
            'variants' => ['case_study_highlights' => 'cards'],
        ])));

        $this->assertFalse($registry->isUsable($this->recipe([
            'variants' => ['intro' => 'Not Valid'],
        ])));
        $this->assertFalse($registry->isUsable($this->recipe([
            'variants' => ['intro' => 'this-name-is-way-too-long'],
        ])));
        $this->assertFalse($registry->isUsable($this->recipe([
            'variants' => ['Not Valid' => 'editorial'],
        ])));
    }

    public function test_validate_rejects_unsupported_schema_version_and_eyebrow_policy(): void
    {
        $registry = app(ServiceLayoutRegistry::class);

        $schemaErrors = $registry->validate($this->recipe(['schema_version' => 2]));
        $this->assertTrue(
            collect($schemaErrors)->contains(fn (string $e): bool => str_contains($e, 'schema_version')),
        );

        $policyErrors = $registry->validate($this->recipe(['eyebrow_policy' => 'none']));
        $this->assertTrue(
            collect($policyErrors)->contains(fn (string $e): bool => str_contains($e, 'eyebrow_policy')),
        );
    }

    public function test_hard_invalid_active_row_resolves_null_and_is_absent_from_options(): void
    {
        $site = Site::factory()->create(['services_layout' => 'broken-recipe']);
        LayoutPreset::factory()->for($site)->create([
            'key' => 'broken-recipe',
            'status' => 'active',
            'recipe' => $this->recipe(['schema_version' => '1']),
        ]);

        $registry = app(ServiceLayoutRegistry::class);

        $this->assertNull($registry->resolve($site));
        $this->assertArrayNotHasKey('broken-recipe', $registry->optionsFor($site));
        $this->assertArrayHasKey('editorial', $registry->optionsFor($site));
    }

    public function test_hard_invalid_active_row_shadowing_a_config_key_is_dropped_from_options(): void
    {
        $site = Site::factory()->create(['services_layout' => 'classic']);
        $other = Site::factory()->create(['services_layout' => 'classic']);

        LayoutPreset::factory()->for($site)->create([
            'key' => 'editorial',
            'status' => 'active',
            'recipe' => $this->recipe(['schema_version' => '1']),
        ]);

        $registry = app(ServiceLayoutRegistry::class);

        $this->assertArrayNotHasKey('editorial', $registry->optionsFor($site));
        $this->assertArrayHasKey('editorial', $registry->optionsFor($other));

        $site->services_layout = 'editorial';
        $this->assertNull($registry->resolve($site));
    }

    public function test_hard_invalid_active_row_is_identity_in_the_renderer(): void
    {
        $site = Site::factory()->create(['services_layout' => 'broken-recipe']);
        LayoutPreset::factory()->for($site)->create([
            'key' => 'broken-recipe',
            'status' => 'active',
            'recipe' => $this->recipe(['schema_version' => '1']),
        ]);

        $renderer = app(PageRenderer::class);
        $method = new \ReflectionMethod($renderer, 'applyServicesLayout');
        $method->setAccessible(true);
        $in = [['type' => 'intro'], ['type' => 'features']];
        $out = $method->invoke(
            $renderer,
            $site,
            new GeneratedPage(['page_type' => 'service']),
            $in,
            ['service'],
        );

        $this->assertSame($in, $out);
    }

    public function test_service_page_types_for_lists_service_pages_and_filters_empty(): void
    {
        $site = Site::factory()->create();
        GeneratedPage::factory()->for($site)->create([
            'page_type' => 'roofing',
            'kind' => PageKind::Service,
        ]);
        GeneratedPage::factory()->for($site)->create([
            'page_type' => '',
            'kind' => PageKind::Service,
        ]);
        GeneratedPage::factory()->for($site)->create([
            'page_type' => 'home',
            'kind' => PageKind::Core,
        ]);

        $types = app(ServiceLayoutRegistry::class)->servicePageTypesFor($site);

        $this->assertSame(['roofing'], array_values($types));
    }

    public function test_deleting_a_layout_preset_invalidates_the_public_page_cache(): void
    {
        $site = Site::factory()->create();
        $preset = LayoutPreset::factory()->for($site)->create([
            'key' => 'roof-special',
            'status' => 'active',
            'recipe' => $this->recipe(),
        ]);

        $this->mock(PublicPageCache::class)
            ->shouldReceive('invalidate')
            ->once()
            ->withArgs(fn (Site $s): bool => $s->id === $site->id);

        $preset->delete();
    }
}
