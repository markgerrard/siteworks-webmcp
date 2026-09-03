<?php

namespace Tests\Feature\Hierarchy;

use App\Enums\ProjectItemStatus;
use App\Models\GeneratedPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SeedsHierarchyCorpus;
use Tests\TestCase;

class CorpusByteIdentityTest extends TestCase
{
    use RefreshDatabase;
    use SeedsHierarchyCorpus;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        SupportAutoInjectedAssets::$forceAssetInjection = false;
    }

    public static function corpusSites(): array
    {
        return [
            'Eden 51' => [51],
            'Hunt 52' => [52],
            'North House 53' => [53],
            'North House 54' => [54],
        ];
    }

    #[DataProvider('corpusSites')]
    public function test_every_public_corpus_page_matches_its_committed_baseline(int $corpusId): void
    {
        config([
            'site.use_versioned_renderer' => true,
            'site.public_cache_enabled' => false,
        ]);
        $corpus = $this->seedHierarchyCorpusSite($corpusId);
        $pageTypes = collect($corpus['pages'])->pluck('page_type');

        $this->assertEmpty(
            collect(['home', 'about', 'contact', 'projects'])
                ->diff($pageTypes)
                ->all(),
            "Corpus site {$corpusId} must cover every core page kind.",
        );
        $this->assertTrue(
            $pageTypes->contains(fn (string $pageType): bool => ! in_array($pageType, ['home', 'about', 'contact', 'projects'], true)),
            "Corpus site {$corpusId} must include a service page.",
        );
        $projectsPage = collect($corpus['pages'])->firstWhere('page_type', 'projects');
        $this->assertInstanceOf(GeneratedPage::class, $projectsPage);
        $this->assertContains(
            'project_gallery',
            collect($projectsPage->publishedRevision->content_data['sections'])->pluck('type')->all(),
        );
        $this->assertGreaterThan(
            0,
            $projectsPage->ownedProjectItems()->where('status', ProjectItemStatus::Published)->count(),
        );

        foreach ($corpus['pages'] as $page) {
            $path = $page->page_type === 'home' ? '/' : "/{$page->page_type}";
            $response = $this->get("http://{$corpus['host']}{$path}")->assertSuccessful();
            $html = $this->normaliseCorpusHtml((string) $response->getContent());
            $fixture = base_path("tests/Fixtures/HierarchyCorpus/{$corpusId}-{$page->page_type}.html");

            foreach ($corpus['palette'] as $token => $hex) {
                $this->assertStringContainsString(
                    $hex,
                    $html,
                    "Corpus site {$corpusId} page [{$page->page_type}] omitted palette token [{$token}] [{$hex}].",
                );
            }

            if ($this->shouldUpdateCorpusFixtures()) {
                @mkdir(dirname($fixture), 0775, true);
                file_put_contents($fixture, $html);
                $this->assertFileExists($fixture);

                continue;
            }

            $this->assertFileExists($fixture, "Missing corpus fixture [{$fixture}].");
            $this->assertSame(
                file_get_contents($fixture),
                $html,
                "Corpus site {$corpusId} page [{$page->page_type}] drifted.",
            );
        }
    }

    public function test_common_page_fixtures_are_distinct_documents_between_every_site(): void
    {
        foreach (['home', 'about', 'contact', 'projects'] as $pageType) {
            $documents = collect([51, 52, 53, 54])->mapWithKeys(function (int $corpusId) use ($pageType): array {
                $fixture = base_path("tests/Fixtures/HierarchyCorpus/{$corpusId}-{$pageType}.html");

                $this->assertFileExists($fixture, "Missing corpus fixture [{$fixture}].");

                return [$corpusId => file_get_contents($fixture)];
            });

            $this->assertCount(
                $documents->count(),
                $documents->uniqueStrict(),
                "Every site's [{$pageType}] fixture must be a distinct document.",
            );
        }
    }

    public function test_corpus_theme_and_layout_axes_are_distinct_and_validator_safe(): void
    {
        $definitions = $this->hierarchyCorpusDefinitions();
        $palettes = $this->hierarchyCorpusPalettes();

        $this->assertCount(4, collect($definitions)->pluck('layout')->uniqueStrict());
        $this->assertContains('banded', collect($definitions)->pluck('layout')->all());
        $this->assertCount(4, collect($palettes)->map(fn (array $palette): string => json_encode($palette, JSON_THROW_ON_ERROR))->uniqueStrict());

        foreach ($definitions as $corpusId => $definition) {
            $this->hierarchyDesignBrief($palettes[$corpusId], $definition['display_font']);
        }

        $darkSurface = $palettes[54]['surface_color'];
        $this->assertLessThanOrEqual(16, max(
            hexdec(substr($darkSurface, 1, 2)),
            hexdec(substr($darkSurface, 3, 2)),
            hexdec(substr($darkSurface, 5, 2)),
        ));
    }

    public function test_corpus_includes_a_flat_slug_at_least_as_long_as_the_real_corpus_maximum(): void
    {
        $corpus = $this->seedHierarchyCorpusSite(54);
        $longestSlug = collect($corpus['pages'])
            ->pluck('page_type')
            ->sortByDesc(fn (string $pageType): int => strlen($pageType))
            ->first();

        $this->assertIsString($longestSlug);
        $this->assertGreaterThanOrEqual(75, strlen($longestSlug));
    }

    private function normaliseCorpusHtml(string $html): string
    {
        $html = (string) preg_replace('/csrfToken:\s*"[^"]*"/', 'csrfToken: "__CSRF__"', $html);
        $html = (string) preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value="__CSRF__"', $html);
        $html = (string) preg_replace('/content="[^"]*"([^>]*name="csrf-token")/', 'content="__CSRF__"$1', $html);

        $html = (string) preg_replace(
            '/\/build(?:-[a-z0-9]+)?\/assets\/[A-Za-z0-9._-]+\.(css|js)/',
            '/build/assets/HASH.$1',
            $html,
        );

        return (string) preg_replace('/[ \t]+$/m', '', $html);
    }

    private function shouldUpdateCorpusFixtures(): bool
    {
        $raw = getenv('HIERARCHY_UPDATE_FIXTURES');
        if ($raw === false) {
            $raw = $_SERVER['HIERARCHY_UPDATE_FIXTURES'] ?? $_ENV['HIERARCHY_UPDATE_FIXTURES'] ?? '';
        }

        return filter_var($raw, FILTER_VALIDATE_BOOL) === true;
    }
}
