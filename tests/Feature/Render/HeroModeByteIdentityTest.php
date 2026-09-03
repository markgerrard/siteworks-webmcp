<?php

namespace Tests\Feature\Render;

use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HeroModeByteIdentityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function stockRecipeHeroMatrix(): array
    {
        $cases = [];
        foreach (['classic', 'editorial', 'showcase', 'precision', 'banded'] as $recipe) {
            foreach (['scene', 'image', 'video', 'orphaned', 'none'] as $hero) {
                foreach (['absent', 'boxed-left', 'null', 'dead-token'] as $variant) {
                    $cases["{$recipe}-{$hero}-{$variant}"] = [$recipe, $hero, $variant];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('stockRecipeHeroMatrix')]
    public function test_stock_home_recipe_render_matches_pre_change_fixture(string $recipe, string $hero, string $variant): void
    {
        [$site, $home] = $this->makeHome($recipe, $hero, $variant);
        $html = $this->normalise(app(PageRenderer::class)->render($site, $home->id, mode: 'public'));
        $path = base_path("tests/Fixtures/HeroMode/{$recipe}-{$hero}-{$variant}.html");

        if ($this->shouldUpdateFixtures()) {
            @mkdir(dirname($path), 0775, true);
            file_put_contents($path, $html);
            $this->assertFileExists($path);

            return;
        }

        $this->assertFileExists($path, "missing fixture {$path} — re-run with HERO_MODE_UPDATE_FIXTURES=1");
        $this->assertSame(file_get_contents($path), $html, "{$recipe}-{$hero}-{$variant} drifted from pre-change fixture");
    }

    /**
     * @return array{0: Site, 1: GeneratedPage}
     */
    private function makeHome(string $recipe, string $hero, string $variant): array
    {
        $site = Site::factory()->create([
            'business_name' => 'Acme Plumbing',
            'business_type' => 'Plumber',
            'location' => 'Wigan',
            'theme' => 'trades-bold',
            'home_layout' => $recipe,
            'services_layout' => 'classic',
            'about_layout' => 'classic',
        ]);

        $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
        $rev = PageRevision::factory()->for($page, 'page')->create([
            'content_data' => ['sections' => $this->homeSections($variant)],
        ]);
        $page->update(['published_revision_id' => $rev->id]);

        $version = SiteVersion::create([
            'site_id' => $site->id,
            'version' => 1,
            'composition' => [
                'nav' => ['items' => []],
                'footer' => ['columns' => [], 'show_credit' => true],
                'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
                'homepage_page_id' => $page->id,
            ],
            'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev->id]],
            'published_at' => now(),
        ]);
        SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

        $this->attachHero($site, $hero);

        return [$site->fresh(), $page->fresh()];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function homeSections(string $variant): array
    {
        $hero = [
            'type' => 'hero',
            'title' => 'Welcome to Acme',
            'subtitle' => 'Plumbing in Wigan',
            'cta_label' => 'Get a quote',
        ];
        if ($variant !== 'absent') {
            $hero['variant'] = $variant === 'null' ? null : $variant;
        }

        return [
            $hero,
            [
                'type' => 'services',
                'title' => 'What We Do',
                'eyebrow' => 'Our Services',
                'items' => [
                    ['icon' => 'wrench', 'title' => 'Boiler repair', 'body' => 'Fast boiler fixes.'],
                    ['icon' => 'bath', 'title' => 'Bathroom fitting', 'body' => 'Full bathroom refits.'],
                ],
            ],
        ];
    }

    private function attachHero(Site $site, string $hero): void
    {
        if ($hero === 'none') {
            return;
        }

        if ($hero === 'image') {
            HeroVersion::factory()->for($site)->active()->create([
                'page_type' => 'home',
                'slot' => 'hero',
                'url' => 'https://cdn.example/legacy-hero.png',
                'watermark_url' => null,
                'prompt' => 'legacy hero',
            ]);

            return;
        }

        if ($hero === 'video') {
            Storage::fake('s3');
            Storage::disk('s3')->put('previews/hero.mp4', 'fake-bytes');
            $site->update([
                'home_hero_video_enabled' => true,
                'home_hero_video_path' => 'previews/hero.mp4',
            ]);

            return;
        }

        if ($hero === 'orphaned') {
            $site->update([
                'home_hero_scene' => [
                    'kind' => 'image',
                    'slides' => [],
                    'transitions' => [['type' => 'fade', 'duration_secs' => 1.0]],
                ],
            ]);

            return;
        }

        $slides = [];
        foreach ([1, 2] as $n) {
            $hv = HeroVersion::factory()->for($site)->create([
                'page_type' => 'home',
                'slot' => 'hero',
                'url' => "https://cdn.example/scene-slide-{$n}.webp",
                'watermark_url' => null,
                'prompt' => "slide {$n}",
                'is_active' => false,
            ]);
            $slides[] = [
                'asset_type' => 'hero_version',
                'asset_id' => $hv->id,
                'heading' => "Slide {$n}",
                'subheading' => null,
                'cta_label' => 'Get a quote',
                'text_zone' => 'middle-left',
                'text_color' => 'white',
                'overlay_strength' => 'light',
                'dwell_secs' => 7,
            ];
        }

        $site->update([
            'home_hero_video_enabled' => false,
            'home_hero_scene' => [
                'kind' => 'image',
                'slides' => $slides,
                'transitions' => [['type' => 'fade', 'duration_secs' => 1.0]],
                'motion' => 'ken_burns',
            ],
        ]);
    }

    private function normalise(string $html): string
    {
        $html = (string) preg_replace('/csrfToken:\s*"[^"]*"/', 'csrfToken: "__CSRF__"', $html);
        $html = (string) preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value="__CSRF__"', $html);
        $html = (string) preg_replace('/content="[^"]*"([^>]*name="csrf-token")/', 'content="__CSRF__"$1', $html);
        $html = (string) preg_replace('/\/build(?:-[a-z0-9]+)?\/assets\/[A-Za-z0-9._-]+\.(css|js)/', '/build/assets/HASH.$1', $html);

        return $html;
    }

    private function shouldUpdateFixtures(): bool
    {
        $raw = getenv('HERO_MODE_UPDATE_FIXTURES');
        if ($raw === false) {
            $raw = $_SERVER['HERO_MODE_UPDATE_FIXTURES'] ?? $_ENV['HERO_MODE_UPDATE_FIXTURES'] ?? '';
        }

        return filter_var($raw, FILTER_VALIDATE_BOOL) === true;
    }
}
