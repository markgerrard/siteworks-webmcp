<?php

namespace Tests\Feature\Render;

use App\Enums\Archetype;
use App\Enums\PageKind;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\Site;
use App\Services\Site\PageLayoutRegistry;
use App\Services\Site\PublicPageCache;
use App\Services\Site\ServiceLayoutAssigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ServiceLayoutAssignerTest extends TestCase
{
    use RefreshDatabase;

    public function test_trades_archetypes_weight_precision_highest(): void
    {
        foreach ([
            Archetype::EmergencyTrade,
            Archetype::TraditionalCraftsman,
            Archetype::LocalService,
        ] as $archetype) {
            $site = $this->siteWithArchetype($archetype);
            $weights = app(ServiceLayoutAssigner::class)->weightsFor($site);

            $this->assertSame(4, $weights['precision']);
            $this->assertSame(1, $weights['editorial']);
            $this->assertSame(0, $weights['showcase']);
            $this->assertArrayNotHasKey('classic', $weights);
        }
    }

    public function test_design_and_hospitality_weight_showcase_when_imagery_present(): void
    {
        foreach ([Archetype::PremiumSpecialist, Archetype::RetailVenue] as $archetype) {
            $site = $this->siteWithArchetype($archetype);
            $this->giveDedicatedIntroImage($site);
            $weights = app(ServiceLayoutAssigner::class)->weightsFor($site);

            $this->assertSame(4, $weights['showcase']);
            $this->assertSame(1, $weights['editorial']);
            $this->assertSame(1, $weights['precision']);
        }
    }

    public function test_intro_slot_alone_counts_as_dedicated_service_imagery(): void
    {
        $site = $this->siteWithArchetype(Archetype::PremiumSpecialist);
        $this->giveServiceIntroSlotOnly($site);

        $weights = app(ServiceLayoutAssigner::class)->weightsFor($site);

        $this->assertSame(4, $weights['showcase']);
    }

    public function test_dedicated_hero_alone_counts_as_dedicated_service_imagery(): void
    {
        $site = $this->siteWithArchetype(Archetype::PremiumSpecialist);
        $this->giveServiceDedicatedHeroOnly($site);

        $weights = app(ServiceLayoutAssigner::class)->weightsFor($site);

        $this->assertSame(4, $weights['showcase']);
    }

    public function test_professional_and_developer_weight_editorial_highest(): void
    {
        foreach ([Archetype::ProfessionalService, Archetype::SaasPlatform] as $archetype) {
            $site = $this->siteWithArchetype($archetype);
            $weights = app(ServiceLayoutAssigner::class)->weightsFor($site);

            $this->assertSame(4, $weights['editorial']);
            $this->assertSame(1, $weights['precision']);
            $this->assertSame(0, $weights['showcase']);
        }
    }

    public function test_unknown_archetype_is_uniform_without_showcase_when_imagery_poor(): void
    {
        $site = Site::factory()->create();
        $weights = app(ServiceLayoutAssigner::class)->weightsFor($site);

        $this->assertSame(1, $weights['editorial']);
        $this->assertSame(1, $weights['precision']);
        $this->assertSame(0, $weights['showcase']);
    }

    public function test_no_imagery_site_never_draws_showcase(): void
    {
        $site = $this->siteWithArchetype(Archetype::PremiumSpecialist);

        $key = app(ServiceLayoutAssigner::class)->assign($site);

        $this->assertNotSame('showcase', $key);
        $this->assertNotSame('classic', $key);
    }

    public function test_assign_is_deterministic_for_the_same_site(): void
    {
        $site = $this->siteWithArchetype(Archetype::LocalService);
        $assigner = app(ServiceLayoutAssigner::class);

        $this->assertSame($assigner->assign($site), $assigner->assign($site->fresh()));
    }

    public function test_assign_never_returns_classic(): void
    {
        $assigner = app(ServiceLayoutAssigner::class);

        foreach ([
            Archetype::EmergencyTrade,
            Archetype::PremiumSpecialist,
            Archetype::ProfessionalService,
            Archetype::SaasPlatform,
        ] as $archetype) {
            $site = $this->siteWithArchetype($archetype);
            $this->assertNotSame('classic', $assigner->assign($site));
        }
    }

    public function test_about_intro_slot_does_not_count_as_dedicated_service_imagery(): void
    {
        $site = $this->siteWithArchetype(Archetype::PremiumSpecialist);
        GeneratedPage::factory()->for($site)->create([
            'page_type' => 'about',
            'kind' => PageKind::Core,
        ]);
        HeroVersion::factory()->for($site)->active()->create([
            'page_type' => 'about',
            'slot' => 'intro',
        ]);

        $weights = app(ServiceLayoutAssigner::class)->weightsFor($site);

        $this->assertSame(0, $weights['showcase']);
    }

    public function test_dedicated_hero_on_core_page_does_not_count_as_service_imagery(): void
    {
        $site = $this->siteWithArchetype(Archetype::PremiumSpecialist);
        GeneratedPage::factory()->for($site)->create([
            'page_type' => 'about',
            'kind' => PageKind::Core,
            'hero_source' => 'dedicated',
        ]);

        $weights = app(ServiceLayoutAssigner::class)->weightsFor($site);

        $this->assertSame(0, $weights['showcase']);
    }

    public function test_assign_family_matches_assign_and_never_returns_classic(): void
    {
        $assigner = app(ServiceLayoutAssigner::class);

        foreach ([
            Archetype::EmergencyTrade,
            Archetype::PremiumSpecialist,
            Archetype::ProfessionalService,
            Archetype::SaasPlatform,
        ] as $archetype) {
            $site = $this->siteWithArchetype($archetype);
            $family = $assigner->assignFamily($site);

            $this->assertSame($assigner->assign($site), $family);
            $this->assertNotSame('classic', $family);
            $this->assertContains($family, ['editorial', 'showcase', 'precision']);
        }
    }

    public function test_assign_family_is_a_usable_key_for_service_about_and_home(): void
    {
        $site = $this->siteWithArchetype(Archetype::ProfessionalService);
        $family = app(ServiceLayoutAssigner::class)->assignFamily($site);
        $registry = app(PageLayoutRegistry::class);

        $this->assertArrayHasKey($family, $registry->optionsFor($site, 'service'));
        $this->assertArrayHasKey($family, $registry->optionsFor($site, 'about'));
        $this->assertArrayHasKey($family, $registry->optionsFor($site, 'home'));
    }

    public function test_assign_skips_family_whose_home_recipe_warns(): void
    {
        $recipe = config('site_home_layouts.editorial');
        $recipe['variants']['services'] = 'numbered-rows';
        $recipe['variants']['trust'] = 'numbered-rows';
        $recipe['surfaces'] = ['services' => 'contrast', 'trust' => 'contrast'];
        config(['site_home_layouts.editorial' => $recipe]);

        $site = $this->siteWithArchetype(Archetype::ProfessionalService);
        $assigner = app(ServiceLayoutAssigner::class);
        $weights = $assigner->weightsFor($site);
        $this->assertSame(4, $weights['editorial']);

        $method = new \ReflectionMethod(ServiceLayoutAssigner::class, 'withoutWarnedRecipes');
        $filtered = $method->invoke($assigner, $site, $weights);

        $this->assertSame(0, $filtered['editorial']);
        $this->assertSame(1, $filtered['precision']);
        $this->assertSame('precision', $assigner->assign($site));
    }

    public function test_assign_returns_classic_and_logs_when_every_family_warns(): void
    {
        foreach (['editorial', 'precision', 'showcase'] as $key) {
            $recipe = config("site_home_layouts.{$key}");
            $this->assertIsArray($recipe);
            $recipe['variants']['services'] = 'numbered-rows';
            $recipe['variants']['trust'] = 'numbered-rows';
            $recipe['surfaces'] = ['services' => 'contrast', 'trust' => 'contrast'];
            config(["site_home_layouts.{$key}" => $recipe]);
        }

        $site = $this->siteWithArchetype(Archetype::ProfessionalService);
        $assigner = app(ServiceLayoutAssigner::class);
        $method = new \ReflectionMethod(ServiceLayoutAssigner::class, 'withoutWarnedRecipes');
        $filtered = $method->invoke($assigner, $site, $assigner->weightsFor($site));
        $this->assertSame(0, $filtered['editorial']);
        $this->assertSame(0, $filtered['precision']);
        $this->assertSame(0, $filtered['showcase']);

        Log::spy();
        $family = $assigner->assignFamily($site);

        $this->assertSame('classic', $family);
        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($site): bool {
            return $message === 'layout-assign.all-warned'
                && $context['site_id'] === $site->id;
        });
    }

    public function test_artisan_assign_persists_the_key_and_invalidates_cache(): void
    {
        $site = $this->siteWithArchetype(Archetype::LocalService);

        $this->mock(PublicPageCache::class)
            ->shouldReceive('invalidate')
            ->once()
            ->withArgs(fn (Site $s): bool => $s->id === $site->id);

        $this->artisan('site:layout', ['site' => (string) $site->id, '--assign' => true])
            ->assertSuccessful();

        $fresh = $site->fresh();
        $this->assertNotSame('classic', $fresh->services_layout);
        $this->assertContains($fresh->services_layout, ['editorial', 'showcase', 'precision']);
        $this->assertSame($fresh->services_layout, $fresh->about_layout);
        $this->assertSame($fresh->services_layout, $fresh->home_layout);
    }

    private function siteWithArchetype(Archetype $archetype): Site
    {
        $site = Site::factory()->create(['services_layout' => 'classic']);
        BusinessProfile::factory()->create([
            'site_id' => $site->id,
            'profile_data' => ['archetype' => $archetype->value],
        ]);

        return $site->fresh();
    }

    private function giveDedicatedIntroImage(Site $site): void
    {
        $this->giveServiceIntroSlotOnly($site);
        GeneratedPage::query()
            ->where('site_id', $site->id)
            ->where('page_type', 'roofing')
            ->update(['hero_source' => 'dedicated']);
    }

    private function giveServiceIntroSlotOnly(Site $site): void
    {
        GeneratedPage::factory()->for($site)->create([
            'page_type' => 'roofing',
            'kind' => PageKind::Service,
            'hero_source' => 'shared',
        ]);
        HeroVersion::factory()->for($site)->active()->create([
            'page_type' => 'roofing',
            'slot' => 'intro',
        ]);
    }

    private function giveServiceDedicatedHeroOnly(Site $site): void
    {
        GeneratedPage::factory()->for($site)->create([
            'page_type' => 'roofing',
            'kind' => PageKind::Service,
            'hero_source' => 'dedicated',
        ]);
    }
}
