<?php

namespace Tests\Feature\Render;

use App\Enums\PageKind;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\SiteMedia;
use App\Services\Site\PageLayoutRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class ProjectDetailFamilyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>  $extra
     */
    private function renderSection(string $type, array $section, GeneratedPage $page, Site $site, array $extra = []): string
    {
        return View::make("site.sections.{$type}", array_merge([
            'section' => array_merge(['type' => $type], $section),
            'sectionIndex' => 0,
            'pageId' => $page->id,
            'mode' => 'public',
            'emitMarkers' => false,
            'emitFormMarkers' => false,
            'schema' => [],
            'theme' => [],
            'profile' => [],
            'site' => $site,
            'page' => $page,
            'pagesBySlug' => [
                'home' => '/',
                'projects' => '/projects',
                'contact' => '/contact',
                $page->page_type => '/'.$page->page_type,
            ],
            'itemsById' => collect(),
            'mediaById' => collect(),
            'pinnedPages' => collect(),
        ], $extra))->render();
    }

    /**
     * @return array{0: Site, 1: GeneratedPage, 2: GeneratedPage}
     */
    private function nestedDetail(): array
    {
        $site = Site::factory()->create(['theme' => 'trades-bold']);
        $parent = GeneratedPage::factory()->for($site)->create([
            'page_type' => 'projects',
            'kind' => PageKind::Core,
            'nav_label' => 'Our Work',
            'status' => PageStatus::Published,
        ]);
        $page = GeneratedPage::factory()->for($site)->create([
            'page_type' => 'projects/loft-conversion-wigan',
            'parent_id' => $parent->id,
            'kind' => PageKind::ProjectDetail,
            'nav_label' => 'Loft Conversion Wigan',
            'status' => PageStatus::Published,
        ]);

        return [$site, $parent, $page];
    }

    public function test_hero_emits_caps_breadcrumb_and_json_ld_only_when_nested(): void
    {
        [$site, $parent, $page] = $this->nestedDetail();

        $nestedHtml = $this->renderSection('project_detail_hero', [
            'title' => 'A quiet extra storey',
            'intro' => 'Light, storage, and a room that earns its keep.',
        ], $page, $site);

        $this->assertStringContainsString('data-project-detail-hero', $nestedHtml);
        $this->assertMatchesRegularExpression('/class="[^"]*uppercase[^"]*tracking/i', $nestedHtml);
        $this->assertStringContainsString('Our Work', $nestedHtml);
        $this->assertStringContainsString('A quiet extra storey', $nestedHtml);
        $this->assertStringContainsString('Light, storage, and a room that earns its keep.', $nestedHtml);
        $this->assertStringContainsString('application/ld+json', $nestedHtml);
        $this->assertStringContainsString('BreadcrumbList', $nestedHtml);
        // Band design: projects-hero treatment — accent bar +
        // overlay-capable copy container, no title/intro grid split.
        $this->assertStringContainsString('background-color: var(--brand-accent)', $nestedHtml);
        $this->assertStringContainsString('flex flex-col justify-center', $nestedHtml);

        $root = GeneratedPage::factory()->for($site)->create([
            'page_type' => 'projects',
            'kind' => PageKind::Core,
            'nav_label' => 'Standalone',
            'parent_id' => null,
        ]);
        $rootHtml = $this->renderSection('project_detail_hero', [
            'title' => 'Standalone title',
            'intro' => 'Standalone intro',
        ], $root, $site);

        $this->assertStringNotContainsString('BreadcrumbList', $rootHtml);
        $this->assertStringNotContainsString('application/ld+json', $rootHtml);
        unset($parent);
    }

    public function test_meta_band_renders_three_hairline_columns(): void
    {
        [$site, , $page] = $this->nestedDetail();

        $html = $this->renderSection('project_meta_band', [
            'project_type' => 'Loft conversion',
            'areas_covered' => 'Wigan, Standish',
            'location' => 'Greater Manchester',
        ], $page, $site);

        $this->assertStringContainsString('data-project-meta-band', $html);
        $this->assertStringContainsString('Loft conversion', $html);
        $this->assertStringContainsString('Wigan, Standish', $html);
        $this->assertStringContainsString('Greater Manchester', $html);
        $this->assertSame(1, preg_match_all('/grid-cols-1 md:grid-cols-3/', $html));
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'border-r') + substr_count($html, 'border-right') + substr_count($html, '1px solid'));
    }

    public function test_photo_essay_renders_editorial_grid_with_caption_rows_and_index_numerals(): void
    {
        [$site, , $page] = $this->nestedDetail();

        $media = [];
        foreach (['one', 'two', 'three', 'four'] as $name) {
            $media[] = SiteMedia::factory()->for($site)->create([
                'url' => 'https://cdn.test/'.$name.'.jpg',
                'alt_text' => $name.' alt',
                'metadata' => ['caption' => ucfirst($name).' caption'],
            ]);
        }

        $html = $this->renderSection('project_photo_essay', [
            'title' => 'Project gallery',
            'category' => 'Domestic Paving',
            'image_ids' => array_map(fn (SiteMedia $m): int => $m->id, $media),
        ], $page, $site, [
            'mediaById' => collect($media)->keyBy('id'),
        ]);

        // Editorial grid (House Plus grammar): 12-col spans, uniform 4:3
        // crops, hairline caption rows with accent index numerals, count
        // line — never full-width dumps.
        $this->assertStringContainsString('md:grid-cols-12', $html);
        $this->assertStringContainsString('md:col-span-7', $html);
        $this->assertStringContainsString('md:col-span-5', $html);
        $this->assertStringContainsString('aspect-[4/3]', $html);
        $this->assertStringContainsString('One caption', $html);
        $this->assertStringContainsString('>04<', $html);
        $this->assertStringNotContainsString('data-essay-layout="full"', $html);
        $this->assertStringNotContainsString('one alt caption', $html);
    }

    public function test_photo_essay_reads_project_item_media_when_item_ids_are_set(): void
    {
        [$site, , $page] = $this->nestedDetail();
        $hero = SiteMedia::factory()->for($site)->create([
            'url' => 'https://cdn.test/item-hero.jpg',
            'alt_text' => 'hero caption',
        ]);
        $item = ProjectItem::factory()->published()->for($site)->create([
            'detail_page_id' => $page->id,
            'image_id' => $hero->id,
            'title' => 'Loft one',
        ]);
        $item->setRelation('image', $hero);
        $item->setRelation('galleryImages', collect());

        $html = $this->renderSection('project_photo_essay', [
            'item_ids' => [$item->id],
        ], $page, $site, [
            'itemsById' => collect([$item->id => $item]),
        ]);

        $this->assertStringContainsString('https://cdn.test/item-hero.jpg', $html);
        $this->assertStringContainsString('hero caption', $html);
    }

    public function test_about_split_carries_the_precision_ruled_header(): void
    {
        [$site, , $page] = $this->nestedDetail();

        $html = $this->renderSection('project_about', [
            'variant' => 'split',
            'title' => 'A quiet extra storey',
            'body' => 'Enough said about this project to earn its page.',
            'project_type' => 'Loft conversion',
            'location' => 'Wigan',
        ], $page, $site);

        // Precision's page-opening signature (intro/spec + story/document
        // grammar): accent top rule above the eyebrow. The detail About is
        // the detail page's opening content section and must match.
        $this->assertStringContainsString('border-top: 2px solid var(--brand-accent)', $html);
        $this->assertStringContainsString('ABOUT', strtoupper($html));
    }

    public function test_cta_row_is_conversational_and_links(): void
    {
        [$site, , $page] = $this->nestedDetail();

        $html = $this->renderSection('project_cta_row', [
            'title' => 'Planning a loft of your own?',
            'body' => 'Tell us the awkward bits — we have probably seen them.',
            'cta_label' => 'Have a chat',
            'cta_url' => '/contact',
        ], $page, $site);

        $this->assertStringContainsString('data-project-cta-row', $html);
        $this->assertStringContainsString('Planning a loft of your own?', $html);
        $this->assertStringContainsString('Have a chat', $html);
        $this->assertStringContainsString('href="/contact"', $html);
        $this->assertStringNotContainsString('Get a free', $html);
    }

    public function test_every_new_family_is_file_backed_and_schema_registered(): void
    {
        $families = [
            'project_detail_hero',
            'project_meta_band',
            'project_photo_essay',
            'project_cta_row',
            'similar_projects',
        ];

        foreach ($families as $family) {
            $this->assertContains($family, PageLayoutRegistry::FILE_BACKED_FAMILIES);
            $this->assertArrayHasKey($family, config('site_sections'));
            $this->assertTrue(view()->exists("site.sections.{$family}"));
            $this->assertTrue(view()->exists("site.sections.variants.{$family}.classic"));
        }
    }

    public function test_stock_classic_recipe_composes_the_detail_families_without_lead_form(): void
    {
        $recipe = config('site_project_detail_layouts.classic');
        $registry = app(PageLayoutRegistry::class);

        $this->assertIsArray($recipe);
        $this->assertSame([], $registry->validate($recipe, 'project_detail'));
        $this->assertTrue($registry->isUsable($recipe, 'project_detail'));
        $this->assertArrayNotHasKey('lead_form', $recipe['variants'] ?? []);
        foreach ([
            'project_detail_hero',
            'project_meta_band',
            'project_photo_essay',
            'project_cta_row',
            'similar_projects',
        ] as $family) {
            $this->assertContains($family, $recipe['eyebrow_sections'] ?? []);
        }
    }
    public function test_json_ld_breadcrumb_is_script_safe_against_hostile_labels(): void
    {
        [$site, $parent, $page] = $this->nestedDetail();
        $page->forceFill(['nav_label' => '</script><script>x'])->saveQuietly();

        $html = $this->renderSection('project_detail_hero', [
            'title' => 'Hostile',
        ], $page->refresh(), $site);

        // Raw </script> must never escape the JSON-LD element.
        $this->assertStringNotContainsString('</script><script>x', $html);
        $this->assertStringContainsString('\u003C', $html);
    }
    public function test_meta_band_suppresses_empty_columns_on_public_render(): void
    {
        [$site, $parent, $page] = $this->nestedDetail();

        $html = $this->renderSection('project_meta_band', [
            'project_type' => 'Domestic Paving',
            'areas_covered' => '',
            'location' => '',
        ], $page, $site);

        $this->assertStringContainsString('Project type', $html);
        $this->assertStringNotContainsString('Areas covered', $html);
        $this->assertStringNotContainsString('Location', $html);
        $this->assertStringContainsString('md:grid-cols-1', $html);
    }
}
