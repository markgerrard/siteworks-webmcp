<?php

namespace Tests\Feature\Render;

use App\Enums\ProjectItemStatus;
use App\Models\GeneratedPage;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\SiteMedia;
use App\Services\Site\PageLayoutRegistry;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HomeLayoutRegistryParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array<string, mixed>>
     */
    private function callHome(Site $site, GeneratedPage $page, array $sections, string $mode = 'public'): array
    {
        $renderer = app(PageRenderer::class);
        $method = new \ReflectionMethod($renderer, 'applyHomeLayout');
        $method->setAccessible(true);

        return $method->invoke($renderer, $site, $page, $sections, $mode);
    }

    /**
     * @param  array<int, mixed>  $sections
     * @return array<int, mixed>
     */
    private function canonicalize(array $sections): array
    {
        return array_map(function (mixed $section): mixed {
            if (! is_array($section)) {
                return $section;
            }
            if (isset($section['item_ids']) && is_array($section['item_ids'])) {
                $section['item_ids'] = ['__count' => count($section['item_ids'])];
            }

            return $section;
        }, $sections);
    }

    private function assertMatchesFixture(string $name, array $payload): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $path = base_path("tests/fixtures/home-layouts/{$name}.json");
        if (! file_exists($path)) {
            @mkdir(dirname($path), 0775, true);
            file_put_contents($path, $json);
            $this->markTestIncomplete("Snapshot created at {$path} — re-run to assert.");
        }
        $this->assertSame(file_get_contents($path), $json, "{$name} drifted from snapshot");
    }

    public function test_resolve_home_returns_usable_showcase_recipe(): void
    {
        $site = Site::factory()->create(['home_layout' => 'showcase']);
        $recipe = app(PageLayoutRegistry::class)->resolve($site, 'home');

        $this->assertIsArray($recipe);
        $this->assertTrue(app(PageLayoutRegistry::class)->isUsable($recipe, 'home'));
        $this->assertSame('boxed-left', $recipe['variants']['hero']);
        $this->assertSame(['portfolio_strip'], $recipe['insert_sections']);
        $this->assertSame(1, $recipe['schema_version']);
        $this->assertNotSame('', $recipe['label'] ?? '');
        $this->assertNotSame('', $recipe['description'] ?? '');
    }

    public function test_options_for_home_matches_enum_copy_fixture(): void
    {
        $site = Site::factory()->create();
        $options = app(PageLayoutRegistry::class)->optionsFor($site, 'home');
        $expected = json_decode(file_get_contents(base_path('tests/fixtures/home-layouts/enum-copy.json')), true);

        $this->assertSame(array_keys($expected), array_keys($options));
        foreach ($expected as $key => $copy) {
            $this->assertSame($copy['label'], $options[$key]['label']);
            $this->assertSame($copy['description'], $options[$key]['description']);
        }
    }

    public function test_home_layout_enum_is_gone_from_runtime_paths(): void
    {
        $hits = [];
        foreach (['app', 'resources', 'config', 'routes'] as $root) {
            $dir = base_path($root);
            if (! is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                if (! str_ends_with($path, '.php')) {
                    continue;
                }
                if (preg_match('/\bHomeLayout\b/', (string) file_get_contents($path))) {
                    $hits[] = str_replace(base_path().'/', '', $path);
                }
            }
        }
        $this->assertSame([], $hits, 'HomeLayout references remain: '.implode(', ', $hits));
    }

    public function test_home_layout_accepts_bespoke_keys_longer_than_32_chars(): void
    {
        $site = Site::factory()->create();
        $key = 'bespoke-home-key-that-exceeds-32ch';
        $this->assertGreaterThan(32, strlen($key));
        $site->update(['home_layout' => $key]);
        $this->assertSame($key, $site->fresh()->home_layout);
    }

    public static function characterizationCases(): array
    {
        return [
            'classic-identity' => ['classic-identity'],
            'showcase-stamps' => ['showcase-stamps'],
            'showcase-insert' => ['showcase-insert'],
            'showcase-admin-edit-skip' => ['showcase-admin-edit-skip'],
            'showcase-stacked-scope' => ['showcase-stacked-scope'],
        ];
    }

    #[DataProvider('characterizationCases')]
    public function test_apply_home_layout_matches_pre_cutover_snapshot(string $name): void
    {
        $home = new GeneratedPage(['page_type' => 'home']);
        $payload = match ($name) {
            'classic-identity' => $this->classicIdentity($home),
            'showcase-stamps' => $this->showcaseStamps($home),
            'showcase-insert' => $this->showcaseInsert(),
            'showcase-admin-edit-skip' => $this->showcaseAdminEditSkip(),
            'showcase-stacked-scope' => $this->showcaseStackedScope($home),
            default => throw new \InvalidArgumentException($name),
        };
        $this->assertMatchesFixture($name, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function classicIdentity(GeneratedPage $home): array
    {
        $site = new Site(['home_layout' => 'classic']);
        $in = [
            ['type' => 'hero'],
            ['type' => 'services'],
            ['type' => 'reviews_summary'],
            ['type' => 'cta'],
        ];

        return $this->canonicalize($this->callHome($site, $home, $in));
    }

    /**
     * @return array<string, mixed>
     */
    private function showcaseStamps(GeneratedPage $home): array
    {
        $site = new Site(['home_layout' => 'showcase']);
        $in = [
            ['type' => 'hero'],
            ['type' => 'services'],
            ['type' => 'reviews_summary'],
            ['type' => 'cta', 'variant' => 'accent-band'],
        ];

        return $this->canonicalize($this->callHome($site, $home, $in));
    }

    /**
     * @return array<string, mixed>
     */
    private function showcaseInsert(): array
    {
        $site = Site::factory()->create(['home_layout' => 'showcase']);
        $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
        $this->seedPublishedItems($site, 3);
        $in = [
            ['type' => 'hero'],
            ['type' => 'services'],
            ['type' => 'cta'],
        ];

        return $this->canonicalize($this->callHome($site, $home, $in, 'public'));
    }

    /**
     * @return array<string, mixed>
     */
    private function showcaseAdminEditSkip(): array
    {
        $site = Site::factory()->create(['home_layout' => 'showcase']);
        $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
        $this->seedPublishedItems($site, 3);
        $in = [
            ['type' => 'hero'],
            ['type' => 'services'],
            ['type' => 'cta'],
        ];

        return $this->canonicalize($this->callHome($site, $home, $in, 'admin-edit'));
    }

    /**
     * @return array<string, mixed>
     */
    private function showcaseStackedScope(GeneratedPage $home): array
    {
        $site = new Site(['home_layout' => 'showcase']);
        $in = [
            ['type' => 'hero', '__page_type' => 'home'],
            ['type' => 'services', '__page_type' => 'home'],
            ['type' => 'hero', '__page_type' => 'about'],
            ['type' => 'services', '__page_type' => 'service'],
            ['type' => 'values', '__page_type' => 'home'],
        ];

        return $this->canonicalize($this->callHome($site, $home, $in));
    }

    private function seedPublishedItems(Site $site, int $count): void
    {
        $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects']);
        foreach (range(1, $count) as $i) {
            $media = SiteMedia::factory()->create([
                'site_id' => $site->id,
                'url' => "https://test.example/project-{$i}.jpg",
            ]);
            ProjectItem::factory()->gallery()->published()->for($site)->create([
                'page_id' => $page->id,
                'image_id' => $media->id,
                'status' => ProjectItemStatus::Published,
            ]);
        }
    }
}
