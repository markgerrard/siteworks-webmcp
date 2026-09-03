<?php

namespace Tests\Feature\Render;

use App\Enums\PageKind;
use App\Models\GeneratedPage;
use App\Models\LayoutPreset;
use App\Models\Site;
use App\Services\Site\PageLayoutRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageLayoutOverrideResolveTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_key_wins_over_site_column(): void
    {
        $site = Site::factory()->create(['services_layout' => 'classic']);
        $page = GeneratedPage::factory()->for($site)->create([
            'page_type' => 'roofing', 'kind' => PageKind::Service, 'layout_preset_key' => 'editorial',
        ]);

        $recipe = app(PageLayoutRegistry::class)->resolveForPage($site, $page, 'service');

        $this->assertIsArray($recipe);
        $this->assertSame(config('site_service_layouts.editorial.variants'), $recipe['variants']);
    }

    public function test_null_key_falls_back_to_site_column(): void
    {
        $site = Site::factory()->create(['services_layout' => 'precision']);
        $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'roofing', 'kind' => PageKind::Service]);

        $recipe = app(PageLayoutRegistry::class)->resolveForPage($site, $page, 'service');

        $this->assertSame(config('site_service_layouts.precision.variants'), $recipe['variants']);
    }

    public function test_classic_key_on_page_forces_identity_even_when_site_has_preset(): void
    {
        $site = Site::factory()->create(['services_layout' => 'precision']);
        $page = GeneratedPage::factory()->for($site)->create([
            'page_type' => 'roofing', 'kind' => PageKind::Service, 'layout_preset_key' => 'classic',
        ]);

        $this->assertNull(app(PageLayoutRegistry::class)->resolveForPage($site, $page, 'service'));
    }

    public function test_tier1_key_resolves_only_when_active_and_same_site(): void
    {
        $site = Site::factory()->create();
        $other = Site::factory()->create();
        LayoutPreset::factory()->for($other)->create(['page_kind' => 'service', 'key' => 'mine', 'status' => 'active']);
        LayoutPreset::factory()->for($site)->create(['page_kind' => 'service', 'key' => 'retired', 'status' => 'retired']);
        $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'roofing', 'kind' => PageKind::Service]);

        $registry = app(PageLayoutRegistry::class);
        $page->layout_preset_key = 'mine';
        $this->assertNull($registry->resolveForPage($site, $page, 'service'));
        $page->layout_preset_key = 'retired';
        $this->assertNull($registry->resolveForPage($site, $page, 'service'));
    }

    public function test_layout_kind_for_page(): void
    {
        $site = Site::factory()->create();
        $registry = app(PageLayoutRegistry::class);
        $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'kind' => PageKind::Core]);
        $about = GeneratedPage::factory()->for($site)->create(['page_type' => 'about', 'kind' => PageKind::Core]);
        $svc = GeneratedPage::factory()->for($site)->create(['page_type' => 'roofing', 'kind' => PageKind::Service]);
        $guide = GeneratedPage::factory()->for($site)->create(['page_type' => 'guide-x', 'kind' => PageKind::Guide]);

        $this->assertSame('home', $registry->layoutKindForPage($home));
        $this->assertSame('about', $registry->layoutKindForPage($about));
        $this->assertSame('service', $registry->layoutKindForPage($svc));
        $this->assertNull($registry->layoutKindForPage($guide));
    }

    public function test_layout_kind_for_page_is_null_when_page_type_is_empty_or_null(): void
    {
        $site = Site::factory()->create();
        $registry = app(PageLayoutRegistry::class);
        $empty = GeneratedPage::factory()->for($site)->create(['page_type' => '', 'kind' => PageKind::Service]);
        $nullType = GeneratedPage::factory()->for($site)->create(['page_type' => 'roofing', 'kind' => PageKind::Service]);
        $nullType->page_type = null;

        $this->assertNull($registry->layoutKindForPage($empty));
        $this->assertNull($registry->layoutKindForPage($nullType));
    }
}
