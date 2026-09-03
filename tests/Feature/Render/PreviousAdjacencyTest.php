<?php

namespace Tests\Feature\Render;

use App\Enums\PageKind;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\LayoutPreset;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageLayoutRegistry;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreviousAdjacencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_previous_surfaces_option_is_registry_validated_and_absent_is_valid(): void
    {
        $registry = app(PageLayoutRegistry::class);

        $absent = [
            'schema_version' => 1,
            'variants' => ['statistics' => 'classic'],
            'eyebrow_policy' => 'all',
        ];
        $this->assertTrue($registry->isUsable($absent, 'home'));
        $this->assertTrue($registry->isUsable($absent, 'about'));

        $on = [
            'schema_version' => 1,
            'variants' => ['statistics' => 'adjacent'],
            'options' => ['previous_surfaces' => true],
            'eyebrow_policy' => 'all',
        ];
        $this->assertTrue(
            $registry->isUsable($on, 'home'),
            implode('; ', $registry->hardErrors($on, 'home')),
        );

        $off = [
            'schema_version' => 1,
            'variants' => ['statistics' => 'classic'],
            'options' => ['previous_surfaces' => false],
            'eyebrow_policy' => 'all',
        ];
        $this->assertTrue($registry->isUsable($off, 'home'));

        $invalid = [
            'schema_version' => 1,
            'variants' => ['statistics' => 'classic'],
            'options' => ['previous_surfaces' => 'yes'],
            'eyebrow_policy' => 'all',
        ];
        $this->assertFalse($registry->isUsable($invalid, 'home'));
        $this->assertTrue(
            collect($registry->hardErrors($invalid, 'home'))
                ->contains(fn (string $e): bool => str_contains($e, 'previous_surfaces')),
            'Expected a hard error naming previous_surfaces',
        );
    }

    public function test_frozen_corpus_fixtures_contain_no_previous_class(): void
    {
        $fixtures = glob(base_path('tests/Fixtures/ByteIdentity/*.html')) ?: [];
        $this->assertNotEmpty($fixtures, 'Byte-identity fixtures must exist');

        foreach ($fixtures as $path) {
            $html = (string) file_get_contents($path);
            $this->assertStringNotContainsString(
                'previous-',
                $html,
                basename($path).' must not contain previous-* (flag absent)',
            );
        }
    }

    public function test_classic_render_emits_no_previous_class_even_with_persisted_stamps(): void
    {
        [$site, $home] = $this->publishHomeSite([
            ['type' => 'hero', 'title' => 'Welcome'],
            ['type' => 'services', 'title' => 'Our Core Services', '__surface' => 'contrast', '__previous_surface' => 'brand'],
            ['type' => 'statistics', 'variant' => 'adjacent', 'title' => 'By the numbers', 'items' => [
                ['value' => '25', 'label' => 'Years', 'suffix' => '+'],
            ]],
        ], recipe: null);

        $html = app(PageRenderer::class)->render($site, $home->id, mode: 'public');

        $this->assertStringNotContainsString('previous-', $html);
        $this->assertStringNotContainsString('__previous_surface', $html);
    }

    public function test_opted_in_consumer_emits_previous_class_from_last_emitted_surface(): void
    {
        [$site, $home] = $this->publishHomeSite([
            ['type' => 'hero', 'title' => 'Welcome'],
            ['type' => 'services', 'title' => 'Our Core Services', 'items' => [
                ['title' => 'Extensions', 'body' => 'Built to last.'],
            ]],
            ['type' => 'statistics', 'title' => 'By the numbers', 'items' => [
                ['value' => '25', 'label' => 'Years', 'suffix' => '+'],
            ]],
        ], recipe: $this->adjacencyRecipe());

        $html = app(PageRenderer::class)->render($site, $home->id, mode: 'public');

        $this->assertStringContainsString('previous-default', $html, 'First emitted neighbour (hero, no __surface) must stamp previous-default on the next section');
        $this->assertStringContainsString('previous-contrast', $html, 'Services contrast stamp must land on the following emitted section');
        $this->assertStringContainsString('data-svc-variant="adjacent"', $html);
        $this->assertStringContainsString('By the numbers', $html);
    }

    public function test_hidden_middle_section_is_bridged_not_used_as_neighbour(): void
    {
        [$site, $home] = $this->publishHomeSite([
            ['type' => 'hero', 'title' => 'Welcome'],
            ['type' => 'services', 'title' => 'Our Core Services', 'items' => [
                ['title' => 'Extensions', 'body' => 'Built to last.'],
            ]],
            [
                'type' => 'lead_form',
                'title' => 'HiddenLeadFormTitle',
                '__surface' => 'brand',
            ],
            ['type' => 'statistics', 'title' => 'By the numbers', 'items' => [
                ['value' => '12', 'label' => 'Towns', 'suffix' => ''],
            ]],
        ], recipe: $this->adjacencyRecipe());

        $html = app(PageRenderer::class)->render($site, $home->id, mode: 'public');

        $this->assertStringNotContainsString('HiddenLeadFormTitle', $html, 'lead_form must be skipped when policy is off');
        $this->assertStringNotContainsString('previous-brand', $html, 'Skipped lead_form surface must not become the neighbour');
        $this->assertStringContainsString('previous-contrast', $html, 'Adjacency must bridge the hidden section to the last emitted surface');
        $this->assertStringContainsString('By the numbers', $html);
    }

    /**
     * @return array<string, mixed>
     */
    private function adjacencyRecipe(): array
    {
        return [
            'schema_version' => 1,
            'label' => 'Adjacent demo',
            'description' => 'Opt-in previous-surface emission',
            'variants' => [
                'services' => 'classic',
                'statistics' => 'adjacent',
            ],
            'surfaces' => [
                'services' => 'contrast',
            ],
            'options' => [
                'previous_surfaces' => true,
            ],
            'eyebrow_policy' => 'all',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $homeSections
     * @param  array<string, mixed>|null  $recipe
     * @return array{0: Site, 1: GeneratedPage}
     */
    private function publishHomeSite(array $homeSections, ?array $recipe): array
    {
        $site = Site::factory()->create([
            'business_name' => 'Apex Developments',
            'theme' => 'trades-bold',
            'home_layout' => $recipe === null ? 'classic' : 'adjacent-demo',
            'services_layout' => 'classic',
            'about_layout' => 'classic',
        ]);

        BusinessProfile::factory()->for($site)->create([
            'profile_data' => [
                'archetype' => 'local_service',
                'lead_form_policy' => 'off',
                'contact' => ['phones' => ['0161 555 0199'], 'emails' => ['info@apex.test']],
                'geo' => ['service_area' => 'Cheshire'],
            ],
        ]);

        if ($recipe !== null) {
            LayoutPreset::factory()->for($site)->create([
                'page_kind' => 'home',
                'key' => 'adjacent-demo',
                'label' => 'Adjacent demo',
                'description' => 'Opt-in previous-surface emission',
                'status' => LayoutPreset::STATUS_ACTIVE,
                'recipe' => $recipe,
            ]);
        }

        $home = $this->makePage($site, 'home', $homeSections);

        $version = SiteVersion::create([
            'site_id' => $site->id,
            'version' => 1,
            'composition' => [
                'nav' => ['items' => []],
                'footer' => ['columns' => [], 'show_credit' => true],
                'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
                'homepage_page_id' => $home->id,
            ],
            'page_revisions' => [
                ['page_id' => $home->id, 'revision_id' => $home->published_revision_id],
            ],
            'published_at' => now(),
        ]);
        SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

        return [$site, $home];
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    private function makePage(Site $site, string $pageType, array $sections): GeneratedPage
    {
        $page = GeneratedPage::factory()->for($site)->create([
            'page_type' => $pageType,
            'kind' => PageKind::Core,
            'nav_label' => ucfirst($pageType),
        ]);
        $rev = PageRevision::factory()->for($page, 'page')->create([
            'content_data' => ['sections' => $sections],
        ]);
        $page->update(['published_revision_id' => $rev->id]);

        return $page->fresh();
    }
}
