<?php

namespace Tests\Feature\Render;

use App\Enums\PageKind;
use App\Enums\PageStatus;
use App\Enums\PreviewLayout;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PageLayoutOverrideStackedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{sections: list<array<string, mixed>>}
     */
    private function sections(string $label): array
    {
        return ['sections' => [
            ['type' => 'intro', 'title' => "{$label} Intro", 'body' => 'p'],
            ['type' => 'features', 'title' => "{$label} Features", 'items' => [['icon' => 'hammer', 'title' => 'i', 'body' => 'b']]],
        ]];
    }

    /**
     * @param  list<GeneratedPage>  $pages
     */
    private function publishAll(Site $site, array $pages): void
    {
        $pageRevisions = [];
        foreach ($pages as $page) {
            $revision = $page->revisions()->latest('id')->firstOrFail();
            $page->update(['published_revision_id' => $revision->id]);
            $pageRevisions[] = ['page_id' => $page->id, 'revision_id' => $revision->id];
        }

        $home = collect($pages)->firstWhere('page_type', 'home');
        $this->assertNotNull($home);

        $version = SiteVersion::create([
            'site_id' => $site->id,
            'version' => 1,
            'composition' => [
                'nav' => ['items' => []],
                'footer' => ['columns' => [], 'show_credit' => true],
                'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
                'homepage_page_id' => $home->id,
            ],
            'page_revisions' => $pageRevisions,
            'published_at' => now(),
        ]);
        SiteVersionCurrent::create([
            'site_id' => $site->id,
            'version_id' => $version->id,
            'updated_at' => now(),
        ]);
    }

    public function test_two_service_pages_with_different_overrides_stamp_differently(): void
    {
        $site = Site::factory()->create([
            'preview_layout' => PreviewLayout::OnePage->value,
            'services_layout' => 'classic',
        ]);
        $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'kind' => PageKind::Core]);
        $a = GeneratedPage::factory()->for($site)->create([
            'page_type' => 'roofing',
            'kind' => PageKind::Service,
            'layout_preset_key' => 'editorial',
        ]);
        $b = GeneratedPage::factory()->for($site)->create([
            'page_type' => 'extensions',
            'kind' => PageKind::Service,
            'layout_preset_key' => 'precision',
        ]);
        foreach ([$home, $a, $b] as $p) {
            PageRevision::factory()->for($p, 'page')->create(['content_data' => $this->sections($p->page_type)]);
        }
        $this->publishAll($site, [$home, $a, $b]);

        $html = app(PageRenderer::class)->renderStacked($site, mode: 'public');

        $editorialIntro = config('site_service_layouts.editorial.variants.intro');
        $precisionIntro = config('site_service_layouts.precision.variants.intro');
        $this->assertIsString($editorialIntro);
        $this->assertIsString($precisionIntro);

        $roofingStart = strpos($html, 'id="roofing"');
        $extensionsStart = strpos($html, 'id="extensions"');
        $this->assertNotFalse($roofingStart);
        $this->assertNotFalse($extensionsStart);
        $this->assertGreaterThan($roofingStart, $extensionsStart);

        $homeChunk = substr($html, 0, $roofingStart);
        $roofingChunk = substr($html, $roofingStart, $extensionsStart - $roofingStart);
        $extensionsChunk = substr($html, $extensionsStart);

        $this->assertStringContainsString('data-svc-variant="'.$editorialIntro.'"', $roofingChunk);
        $this->assertStringContainsString('data-svc-variant="'.$precisionIntro.'"', $extensionsChunk);
        $this->assertStringNotContainsString('data-svc-variant=', $homeChunk);
    }

    public function test_home_override_does_not_leak_onto_service_sections(): void
    {
        $site = Site::factory()->create(['preview_layout' => PreviewLayout::OnePage->value]);
        $home = GeneratedPage::factory()->for($site)->create([
            'page_type' => 'home',
            'kind' => PageKind::Core,
            'layout_preset_key' => 'editorial',
        ]);
        $svc = GeneratedPage::factory()->for($site)->create(['page_type' => 'roofing', 'kind' => PageKind::Service]);
        foreach ([$home, $svc] as $p) {
            PageRevision::factory()->for($p, 'page')->create(['content_data' => $this->sections($p->page_type)]);
        }
        $this->publishAll($site, [$home, $svc]);

        $html = app(PageRenderer::class)->renderStacked($site, mode: 'public');

        $roofingStart = strpos($html, 'id="roofing"');
        $this->assertNotFalse($roofingStart);
        $roofingChunk = substr($html, $roofingStart);

        $this->assertStringNotContainsString('data-svc-variant=', $roofingChunk);
    }

    public function test_apply_page_kind_layout_memoises_the_page_map_and_skips_archived_overrides(): void
    {
        $site = Site::factory()->create(['services_layout' => 'classic']);
        $archived = GeneratedPage::factory()->for($site)->create([
            'page_type' => 'plumbing',
            'kind' => PageKind::Service,
            'status' => PageStatus::Archived,
            'layout_preset_key' => 'editorial',
        ]);
        $this->assertNotNull($archived->fresh()->archived_at);

        $live = GeneratedPage::factory()->for($site)->create([
            'page_type' => 'plumbing',
            'kind' => PageKind::Service,
            'layout_preset_key' => 'precision',
        ]);

        $renderer = app(PageRenderer::class);
        $method = new \ReflectionMethod($renderer, 'applyPageKindLayout');
        $method->setAccessible(true);
        $sections = [
            ['type' => 'intro', 'title' => 'Live', '__page_type' => 'plumbing'],
        ];

        DB::flushQueryLog();
        DB::enableQueryLog();
        $out = $method->invoke($renderer, $site, $live, $sections, 'service');
        $first = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'generated_pages'))
            ->count();
        $method->invoke($renderer, $site, $live, $sections, 'service');
        $second = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'generated_pages'))
            ->count();

        $precisionIntro = config('site_service_layouts.precision.variants.intro');
        $editorialIntro = config('site_service_layouts.editorial.variants.intro');
        $this->assertIsString($precisionIntro);
        $this->assertIsString($editorialIntro);
        $this->assertSame($precisionIntro, $out[0]['variant']);
        $this->assertNotSame($editorialIntro, $out[0]['variant']);
        $this->assertSame($first, $second);
        $this->assertGreaterThan(0, $first);
    }

    public function test_page_kind_map_prefers_lowest_sort_order_then_lowest_id(): void
    {
        $site = Site::factory()->create(['services_layout' => 'classic']);
        $lowerSort = GeneratedPage::factory()->for($site)->create([
            'page_type' => 'plumbing',
            'kind' => PageKind::Service,
            'sort_order' => 1,
            'layout_preset_key' => 'editorial',
        ]);
        GeneratedPage::factory()->for($site)->create([
            'page_type' => 'plumbing',
            'kind' => PageKind::Service,
            'sort_order' => 5,
            'layout_preset_key' => 'precision',
        ]);
        $this->assertGreaterThan($lowerSort->id, GeneratedPage::query()->where('site_id', $site->id)->max('id'));

        $tiedFirst = GeneratedPage::factory()->for($site)->create([
            'page_type' => 'roofing',
            'kind' => PageKind::Service,
            'sort_order' => 2,
            'layout_preset_key' => 'editorial',
        ]);
        GeneratedPage::factory()->for($site)->create([
            'page_type' => 'roofing',
            'kind' => PageKind::Service,
            'sort_order' => 2,
            'layout_preset_key' => 'precision',
        ]);

        $renderer = app(PageRenderer::class);
        $method = new \ReflectionMethod($renderer, 'applyPageKindLayout');
        $method->setAccessible(true);

        $plumbing = $method->invoke($renderer, $site, $lowerSort, [
            ['type' => 'intro', 'title' => 'P', '__page_type' => 'plumbing'],
        ], 'service');
        $roofing = $method->invoke($renderer, $site, $tiedFirst, [
            ['type' => 'intro', 'title' => 'R', '__page_type' => 'roofing'],
        ], 'service');

        $editorialIntro = config('site_service_layouts.editorial.variants.intro');
        $this->assertIsString($editorialIntro);
        $this->assertSame($editorialIntro, $plumbing[0]['variant']);
        $this->assertSame($editorialIntro, $roofing[0]['variant']);
    }

    public function test_apply_page_kind_layout_shares_the_page_map_and_does_not_hydrate_content_data(): void
    {
        $site = Site::factory()->create(['services_layout' => 'classic', 'about_layout' => 'classic']);
        $live = GeneratedPage::factory()->for($site)->create([
            'page_type' => 'plumbing',
            'kind' => PageKind::Service,
            'layout_preset_key' => 'precision',
            'content_data' => ['sections' => [['type' => 'intro', 'title' => str_repeat('x', 200)]]],
        ]);
        GeneratedPage::factory()->for($site)->create([
            'page_type' => 'about',
            'kind' => PageKind::Core,
            'layout_preset_key' => 'editorial',
        ]);

        $renderer = app(PageRenderer::class);
        $kindPages = new \ReflectionMethod($renderer, 'kindPagesByType');
        $kindPages->setAccessible(true);
        $apply = new \ReflectionMethod($renderer, 'applyPageKindLayout');
        $apply->setAccessible(true);

        $map = $kindPages->invoke($renderer, $site, ['plumbing']);
        $this->assertArrayNotHasKey('content_data', $map->get('plumbing')->getAttributes());

        DB::flushQueryLog();
        DB::enableQueryLog();
        $apply->invoke($renderer, $site, $live, [
            ['type' => 'intro', 'title' => 'Live', '__page_type' => 'plumbing'],
        ], 'service');
        $afterService = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'generated_pages'))
            ->count();
        $apply->invoke($renderer, $site, $live, [
            ['type' => 'story', 'title' => 'About', '__page_type' => 'about'],
        ], 'about');
        $afterAbout = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'generated_pages'))
            ->count();

        $this->assertSame($afterService, $afterAbout);
    }
}
