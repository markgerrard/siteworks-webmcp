<?php

namespace Tests\Feature\Render;

use App\Enums\PageKind;
use App\Enums\ProjectItemSource;
use App\Models\GeneratedPage;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VariantProjectGalleryFilterTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unvaried_projects_gallery_matches_task_0_fixture(): void
    {
        $site = Site::factory()->create(['business_name' => 'Acme Roofing', 'theme' => 'trades-bold']);
        $page = GeneratedPage::factory()->for($site)->create([
            'page_type' => 'projects',
            'kind' => PageKind::Core,
        ]);

        $sections = [
            ['type' => 'projects_hero', 'title' => 'Projects'],
            ['type' => 'project_gallery', 'title' => 'Recent Work', 'item_ids' => []],
        ];

        $items = [];
        foreach (['Kitchens', 'Extensions', 'kitchens '] as $index => $category) {
            $items[] = ProjectItem::factory()->gallery()->published()->for($site)->create([
                'category' => $category,
                'title' => 'Project '.trim($category).' '.$index,
                'description' => 'd',
                'sort_order' => $index,
            ]);
        }
        $sections[1]['item_ids'] = array_map(fn (ProjectItem $item): int => $item->id, $items);

        $this->publish($site, $page, $sections);

        $html = $this->normalise(app(PageRenderer::class)->render($site, $page->id, mode: 'public'));
        $fixture = base_path('tests/Fixtures/ChromeSnapshots/projects-gallery.html');

        $this->assertFileExists($fixture);
        $this->assertSame(file_get_contents($fixture), $html);
    }

    public function test_filter_tabs_groups_categories_and_keeps_blank_tiles_under_all(): void
    {
        $html = $this->renderFilterTabs([
            'Kitchens',
            'Extensions',
            'kitchens ',
            '',
        ]);

        $this->assertSame(2, preg_match_all('/<article\b[^>]*\bdata-cat="kitchens"/', $html));
        $this->assertSame(1, preg_match_all('/<button\b[^>]*\bdata-cat="kitchens"/', $html));
        $this->assertSame(1, preg_match_all('/<article\b[^>]*\bdata-cat="extensions"/', $html));
        $this->assertSame(1, preg_match_all('/<button\b[^>]*\bdata-cat="extensions"/', $html));
        $this->assertSame(1, substr_count($html, 'data-cat=""'));
        $this->assertSame(4, preg_match_all('/<article\b[^>]*\bdata-cat="/', $html));

        $this->assertStringContainsString('role="group"', $html);
        $this->assertStringContainsString('aria-label="Filter projects"', $html);
        $this->assertStringContainsString('x-cloak', $html);
        $this->assertStringContainsString('aria-pressed', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('Showing 4 projects', $html);
        $this->assertStringContainsString('All 4', $html);
        $this->assertStringContainsString('KITCHENS 2', $html);
        $this->assertStringContainsString('EXTENSIONS 1', $html);

        $this->assertSame(1, preg_match('/<div[^>]*role="group"[^>]*>(.*?)<\/div>/s', $html, $group));
        $this->assertSame(3, preg_match_all('/<button\b/', $group[1]));

        $this->assertSame(3, preg_match_all('/<button\b([^>]*)>/', $group[1], $buttons));
        foreach ($buttons[1] as $attrs) {
            $this->assertMatchesRegularExpression('/\bdata-cat="/', $attrs);
            $this->assertSame(1, preg_match('/\bdata-cat="([^"]*)"/', $attrs, $cat));
            $this->assertSame(1, preg_match('/@click="([^"]*)"/', $attrs, $click));
            $this->assertSame(1, preg_match('/:aria-pressed="([^"]*)"/', $attrs, $pressed));
            if ($cat[1] === 'all') {
                continue;
            }
            $this->assertStringNotContainsString($cat[1], $click[1]);
            $this->assertStringNotContainsString($cat[1], $pressed[1]);
        }
    }

    public function test_filter_tabs_honest_framing_renders_example_vocabulary_in_the_count_line(): void
    {
        config()->set('site.honest_project_framing', false);
        $html = $this->renderFilterTabs(
            ['Kitchens', 'Extensions'],
            ['honest_project_framing' => true],
            ProjectItemSource::AiGenerated,
        );

        $this->assertStringContainsString('Showing 2 example projects', $html);
        $this->assertStringContainsString('aria-label="Filter example projects"', $html);
        $this->assertStringContainsString('Example Projects', $html);
    }

    public function test_filter_tabs_hides_button_group_when_only_one_category(): void
    {
        $html = $this->renderFilterTabs(['Kitchens', 'kitchens ']);

        $this->assertStringNotContainsString('role="group"', $html);
        $this->assertStringContainsString('data-cat="kitchens"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('Showing 2 projects', $html);
    }

    public function test_filter_tabs_caption_row_is_present_and_mobile_scrim_is_not(): void
    {
        $html = $this->renderFilterTabs(['Kitchens', 'Extensions']);

        $this->assertStringContainsString('flex justify-between text-xs uppercase tracking-[0.18em] mt-3', $html);
        $this->assertStringNotContainsString('absolute inset-x-0 bottom-0 p-4 md:hidden', $html);
    }

    public function test_filter_tabs_uppercases_multibyte_category_labels(): void
    {
        $html = $this->renderFilterTabs(['Rénovation', 'Extensions']);

        $this->assertStringContainsString('RÉNOVATION', $html);
        $this->assertStringNotContainsString('>RéNOVATION ', $html);
    }

    public function test_filter_tabs_renders_plain_eyebrow_on_non_ruled_path_and_ruled_wrapper_on_ruled_path(): void
    {
        $plain = $this->renderFilterTabs(['Kitchens', 'Extensions']);

        $this->assertStringContainsString('<div class="mb-3">', $plain);
        $this->assertStringContainsString(
            'text-xs font-bold tracking-[0.18em] uppercase',
            $plain,
        );
        $this->assertStringContainsString('style="color: var(--brand-accent-text);"', $plain);
        $this->assertStringContainsString('Our Work', $plain);
        $this->assertStringNotContainsString(
            'border-top: 2px solid var(--brand-accent)',
            $plain,
        );

        $ruled = $this->renderFilterTabs(
            ['Kitchens', 'Extensions'],
            ['services_layout' => 'precision'],
        );

        $this->assertStringContainsString(
            'border-top: 2px solid var(--brand-accent)',
            $ruled,
        );
        $this->assertStringContainsString(
            'flex items-baseline justify-between gap-6 pt-4 mb-10',
            $ruled,
        );
        $this->assertStringContainsString('Our Work', $ruled);
        $this->assertStringNotContainsString('<div class="mb-3">', $ruled);
    }

    /**
     * @param  list<string>  $categories
     * @param  array<string, mixed>  $siteAttrs
     */
    private function renderFilterTabs(
        array $categories,
        array $siteAttrs = [],
        ProjectItemSource $source = ProjectItemSource::AiGenerated,
    ): string {
        $site = Site::factory()->create($siteAttrs);
        $page = GeneratedPage::factory()->for($site)->create([
            'page_type' => 'projects',
            'kind' => PageKind::Core,
        ]);

        $items = [];
        foreach ($categories as $index => $category) {
            $items[] = ProjectItem::factory()->gallery()->published()->for($site)->create([
                'category' => $category,
                'title' => 'Project '.($category === '' ? 'Uncategorised' : trim($category)).' '.$index,
                'description' => 'd',
                'sort_order' => $index,
                'source' => $source,
            ]);
        }

        $sections = [
            ['type' => 'projects_hero', 'title' => 'Projects'],
            [
                'type' => 'project_gallery',
                'title' => 'Recent Work',
                'variant' => 'filter-tabs',
                'item_ids' => array_map(fn (ProjectItem $item): int => $item->id, $items),
            ],
        ];

        $this->publish($site, $page, $sections);

        return app(PageRenderer::class)->render($site, $page->id, mode: 'public');
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    private function publish(Site $site, GeneratedPage $page, array $sections): void
    {
        $revision = PageRevision::factory()->for($page, 'page')->create([
            'content_data' => ['sections' => $sections],
        ]);
        $page->update(['published_revision_id' => $revision->id]);

        $version = SiteVersion::create([
            'site_id' => $site->id,
            'version' => 1,
            'composition' => [
                'nav' => ['items' => []],
                'footer' => ['columns' => [], 'show_credit' => true],
                'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
                'homepage_page_id' => $page->id,
            ],
            'page_revisions' => [
                ['page_id' => $page->id, 'revision_id' => $revision->id],
            ],
            'published_at' => now(),
        ]);
        SiteVersionCurrent::create([
            'site_id' => $site->id,
            'version_id' => $version->id,
            'updated_at' => now(),
        ]);
    }

    private function normalise(string $html): string
    {
        $html = preg_replace('/name="_token" value="[^"]+"/', 'name="_token" value="X"', $html) ?? $html;
        $html = preg_replace('/\?v=\d+/', '?v=N', $html) ?? $html;
        $html = preg_replace('/build\/assets\/[a-z0-9-]+\.[a-f0-9]{8}\./', 'build/assets/X.', $html) ?? $html;
        $html = preg_replace('/build\/assets\/[A-Za-z0-9._-]+/', 'build/assets/X', $html) ?? $html;
        $html = preg_replace('/\b(lf|cf)-\d+-\d+/', '$1-N-N', $html) ?? $html;

        return preg_replace('/wire:id="[^"]+"/', 'wire:id="X"', $html) ?? $html;
    }
}
