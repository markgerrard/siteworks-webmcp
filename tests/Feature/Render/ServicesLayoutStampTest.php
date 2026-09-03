<?php

namespace Tests\Feature\Render;

use App\Enums\PageKind;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ServicesLayoutStampTest extends TestCase
{
    use RefreshDatabase;

    private function callStamp(Site $site, ?GeneratedPage $page, array $sections, array $serviceTypes = ['service']): array
    {
        $r = app(PageRenderer::class);
        $m = new \ReflectionMethod($r, 'applyServicesLayout');
        $m->setAccessible(true);
        return $m->invoke($r, $site, $page, $sections, $serviceTypes);
    }

    private function servicePage(): GeneratedPage
    {
        return new GeneratedPage(['page_type' => 'service']);
    }

    public function test_options_stamp_verbatim_to_all_varied_sections(): void
    {
        config()->set('site_about_layouts.opt-test', [
            'schema_version' => 1, 'label' => 'T', 'description' => 'T',
            'variants' => ['story' => 'document', 'values' => 'markers'],
            'eyebrow_policy' => 'all', 'eyebrow_sections' => ['story', 'values'],
            'options' => ['image_alignment' => 'left'],
        ]);
        $site = new Site(['about_layout' => 'opt-test']);
        $page = new GeneratedPage(['page_type' => 'about']);
        $r = app(\App\Services\Site\PageRenderer::class);
        $m = new \ReflectionMethod($r, 'applyPageKindLayout');
        $m->setAccessible(true);
        $out = $m->invoke($r, $site, $page, [['type' => 'story'], ['type' => 'values']], 'about', ['about']);
        // Verbatim pass-through: secondary-image sections derive their own
        // opposite default in-template; the renderer no longer inverts.
        $this->assertSame('left', $out[0]['__options']['image_alignment']);
        $this->assertSame('left', $out[1]['__options']['image_alignment']);
    }

    public function test_classic_is_identity(): void
    {
        $site = new Site(['services_layout' => 'classic']);
        $in = [['type' => 'intro'], ['type' => 'features']];
        $this->assertSame($in, $this->callStamp($site, $this->servicePage(), $in));
    }

    public function test_editorial_stamps_variants_without_overriding_explicit(): void
    {
        $site = new Site(['services_layout' => 'editorial']);
        $in = [['type' => 'intro', 'variant' => 'split'], ['type' => 'features']];
        $out = $this->callStamp($site, $this->servicePage(), $in);
        $this->assertSame('split', $out[0]['variant']);      // explicit wins
        $this->assertSame('numbered', $out[1]['variant']);   // stamped
    }

    public function test_non_service_pages_untouched(): void
    {
        $site = new Site(['services_layout' => 'editorial']);
        $home = new GeneratedPage(['page_type' => 'home']);
        $in = [['type' => 'intro']];
        $this->assertSame($in, $this->callStamp($site, $home, $in));
    }

    public function test_stacked_mode_stamps_service_tagged_sections_only(): void
    {
        // renderStacked passes the HOME page as the resolution page with
        // per-section __page_type tags (bug #8 precedent: a transform that
        // early-returns on $page->page_type never fires in one-page mode).
        $site = new Site(['services_layout' => 'editorial']);
        $home = new GeneratedPage(['page_type' => 'home']);
        $in = [
            ['type' => 'intro', '__page_type' => 'home'],
            ['type' => 'intro', '__page_type' => 'service-roofing'],
            ['type' => 'features', '__page_type' => 'service-roofing'],
        ];
        $out = $this->callStamp($site, $home, $in, ['service-roofing']);
        $this->assertArrayNotHasKey('variant', $out[0]);
        $this->assertSame('editorial', $out[1]['variant']);
        $this->assertSame('numbered', $out[2]['variant']);
    }

    public function test_unknown_key_falls_back_to_identity(): void
    {
        $site = new Site(['services_layout' => 'no-such-preset']);
        $in = [['type' => 'intro']];
        $this->assertSame($in, $this->callStamp($site, $this->servicePage(), $in));
    }

    public function test_first_only_eyebrow_policy_suppresses_after_first(): void
    {
        $site = new Site(['services_layout' => 'editorial']);
        $in = [['type' => 'intro'], ['type' => 'features']];
        $out = $this->callStamp($site, $this->servicePage(), $in);
        $this->assertArrayNotHasKey('__suppress_eyebrow', $out[0]);
        $this->assertTrue($out[1]['__suppress_eyebrow']);
    }

    public function test_first_only_eyebrow_resets_per_page_type_in_stacked_mode(): void
    {
        $site = new Site(['services_layout' => 'editorial']);
        $home = new GeneratedPage(['page_type' => 'home']);
        $in = [
            ['type' => 'intro', '__page_type' => 'home'],
            ['type' => 'intro', '__page_type' => 'roofing'],
            ['type' => 'features', '__page_type' => 'roofing'],
            ['type' => 'intro', '__page_type' => 'extensions'],
            ['type' => 'features', '__page_type' => 'extensions'],
        ];
        $out = $this->callStamp($site, $home, $in, ['roofing', 'extensions']);

        $this->assertArrayNotHasKey('variant', $out[0]);
        $this->assertArrayNotHasKey('__suppress_eyebrow', $out[0]);
        $this->assertSame('editorial', $out[1]['variant']);
        $this->assertArrayNotHasKey('__suppress_eyebrow', $out[1]);
        $this->assertTrue($out[2]['__suppress_eyebrow']);
        $this->assertSame('editorial', $out[3]['variant']);
        $this->assertArrayNotHasKey('__suppress_eyebrow', $out[3]);
        $this->assertTrue($out[4]['__suppress_eyebrow']);
    }

    public function test_service_page_types_query_selects_kind_and_page_type_only(): void
    {
        $site = Site::factory()->create(['services_layout' => 'editorial']);
        GeneratedPage::factory()->for($site)->create([
            'page_type' => 'roofing',
            'kind' => PageKind::Service,
            'content_data' => ['blob' => str_repeat('x', 2048)],
        ]);
        GeneratedPage::factory()->for($site)->create([
            'page_type' => 'home',
            'kind' => PageKind::Core,
            'content_data' => ['blob' => str_repeat('y', 2048)],
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $renderer = app(PageRenderer::class);
        $method = new \ReflectionMethod($renderer, 'servicePageTypesFor');
        $method->setAccessible(true);
        $types = $method->invoke($renderer, $site);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertContains('roofing', $types);
        $this->assertNotContains('home', $types);

        $pageQueries = collect($log)->filter(
            fn (array $query): bool => str_contains($query['query'], 'generated_pages'),
        );
        $this->assertNotEmpty($pageQueries);
        foreach ($pageQueries as $query) {
            $this->assertDoesNotMatchRegularExpression('/select\s+\*/i', $query['query']);
            $this->assertStringContainsString('page_type', $query['query']);
            $this->assertStringContainsString('kind', $query['query']);
        }
    }
}
