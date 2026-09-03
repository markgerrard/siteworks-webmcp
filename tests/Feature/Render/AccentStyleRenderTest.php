<?php

namespace Tests\Feature\Render;

use App\Enums\PageKind;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccentStyleRenderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * One case per caller template (12 files / 13 wrap sites). Inner hero
     * covers the second wrap in hero.blade.php; scene covers _hero_scene.
     *
     * @return array<string, array{0: string, 1: list<array<string, mixed>>, 2: array<string, mixed>}>
     */
    public static function callerTemplates(): array
    {
        $items = [
            ['title' => 'Flat roofs', 'body' => 'b'],
            ['title' => 'Pitched', 'body' => 'b'],
        ];

        $hero = ['type' => 'hero', 'title' => 'Roofing you can trust', 'accent_word' => 'trust', 'subtitle' => 's'];

        $services = static fn (string $variant): array => [[
            'type' => 'services',
            'variant' => $variant,
            'title' => 'Our Services',
            'accent_word' => 'Services',
            'items' => $items,
        ]];

        return [
            'hero' => ['home', [$hero], []],
            'hero-inner' => ['roofing', [$hero], []],
            'hero-scene' => ['home', [$hero], ['scene' => true]],
            'hero_compact' => ['planning', [[
                'type' => 'hero_compact',
                'title' => 'Roofing you can trust',
                'accent_word' => 'trust',
            ]], []],
            'projects_hero' => ['projects', [[
                'type' => 'projects_hero',
                'title' => 'Roofing you can trust',
                'accent_word' => 'trust',
            ]], []],
            'features-cards' => ['home', [[
                'type' => 'features',
                'variant' => 'cards',
                'title' => 'Our Services',
                'accent_word' => 'Services',
                'items' => $items,
            ]], []],
            'services-classic' => ['home', $services('classic'), []],
            'services-editorial-grid' => ['home', $services('editorial-grid'), []],
            'services-featured-ledger' => ['home', $services('featured-ledger'), []],
            'services-featured-stories' => ['home', $services('featured-stories'), []],
            'services-numbered-rows' => ['home', $services('numbered-rows'), []],
            'services-photo-cards' => ['home', $services('photo-cards'), []],
            'services-split-bands' => ['home', $services('split-bands'), []],
        ];
    }

    private const ITALIC_ACCENT_SPAN = 'class="accent-word" style="color: var(--color-accent); font-style: italic;"';

    #[DataProvider('callerTemplates')]
    public function test_italic_accent_style_emits_font_style_exactly_once(string $pageType, array $sections, array $opts): void
    {
        $html = $this->renderPage($pageType, $sections, $opts, 'italic');

        // Page chrome already emits `font-style: italic` on blockquote CSS
        // (page.blade.php). Count only the accent-word span the knob owns.
        $this->assertSame(1, substr_count($html, self::ITALIC_ACCENT_SPAN));
    }

    #[DataProvider('callerTemplates')]
    public function test_null_accent_style_emits_no_font_style(string $pageType, array $sections, array $opts): void
    {
        $html = $this->renderPage($pageType, $sections, $opts, null);

        $this->assertSame(0, substr_count($html, self::ITALIC_ACCENT_SPAN));
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @param  array<string, mixed>  $opts
     */
    private function renderPage(string $pageType, array $sections, array $opts, ?string $accentStyle): string
    {
        $site = Site::factory()->create([
            'business_name' => 'Acme Roofing',
            'theme' => 'trades-bold',
            'accent_style' => $accentStyle,
        ]);

        if ($opts['scene'] ?? false) {
            $this->attachTwoSlideScene($site);
        }

        $kind = match ($pageType) {
            'roofing' => PageKind::Service,
            default => PageKind::Core,
        };

        $page = GeneratedPage::factory()->for($site)->create([
            'page_type' => $pageType,
            'kind' => $kind,
        ]);

        $revision = PageRevision::factory()->for($page, 'page')->create([
            'content_data' => ['sections' => $sections],
        ]);
        $page->update(['published_revision_id' => $revision->id]);

        self::publish($site, $page->fresh());

        return app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');
    }

    private function attachTwoSlideScene(Site $site): void
    {
        $slides = [];
        foreach (['Roofing you can trust', 'Quality workmanship'] as $n => $heading) {
            $hv = HeroVersion::create([
                'site_id' => $site->id,
                'page_type' => 'home',
                'slot' => 'hero',
                'url' => 'https://cdn.example/scene-slide-'.($n + 1).'.webp',
                'source' => 'user_upload',
                'is_active' => false,
            ]);
            $slides[] = [
                'asset_type' => 'hero_version',
                'asset_id' => $hv->id,
                'heading' => $heading,
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
            ],
        ]);
    }

    private static function publish(Site $site, GeneratedPage $page): void
    {
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
                ['page_id' => $page->id, 'revision_id' => $page->published_revision_id],
            ],
            'published_at' => now(),
        ]);
        SiteVersionCurrent::create([
            'site_id' => $site->id,
            'version_id' => $version->id,
            'updated_at' => now(),
        ]);
    }
}
