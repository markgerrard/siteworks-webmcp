<?php

namespace Tests\Feature\Render;

use App\Enums\PageKind;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Render-level QA matrix: every non-classic stock preset × edge fixture
 * through the real intro/features dispatchers. No DB. Failures are fixed
 * in the variant partials, not by weakening these assertions.
 */
class ServicesLayoutQaMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function overridePresets(): array
    {
        return [
            'editorial' => ['editorial'],
            'showcase' => ['showcase'],
            'precision' => ['precision'],
        ];
    }

    #[DataProvider('overridePresets')]
    public function test_per_page_override_stamps_preset_when_site_column_is_classic(string $preset): void
    {
        $site = Site::factory()->create([
            'business_name' => 'Acme',
            'theme' => 'trades-bold',
            'services_layout' => 'classic',
        ]);
        $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'kind' => PageKind::Core]);
        $page = GeneratedPage::factory()->for($site)->create([
            'page_type' => 'roofing',
            'kind' => PageKind::Service,
            'layout_preset_key' => $preset,
        ]);
        $homeRev = PageRevision::factory()->for($home, 'page')->create([
            'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Welcome']]],
        ]);
        $svcRev = PageRevision::factory()->for($page, 'page')->create([
            'content_data' => ['sections' => [
                ['type' => 'intro', 'title' => "What's Included", 'eyebrow' => 'About This Service', 'body' => 'Roofing intro prose.'],
                [
                    'type' => 'features',
                    'title' => "What's Included",
                    'eyebrow' => "What's Included",
                    'items' => [
                        ['icon' => 'hammer', 'title' => 'Item 1', 'body' => 'Body 1.'],
                        ['icon' => 'hammer', 'title' => 'Item 2', 'body' => 'Body 2.'],
                    ],
                ],
            ]],
        ]);
        $home->update(['published_revision_id' => $homeRev->id]);
        $page->update(['published_revision_id' => $svcRev->id]);
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
                ['page_id' => $home->id, 'revision_id' => $homeRev->id],
                ['page_id' => $page->id, 'revision_id' => $svcRev->id],
            ],
            'published_at' => now(),
        ]);
        SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

        $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');
        $recipe = config("site_service_layouts.{$preset}");
        $this->assertIsArray($recipe);
        $introVariant = $recipe['variants']['intro'];
        $featuresVariant = $recipe['variants']['features'];

        $this->assertNotSame('', trim($html));
        $this->assertStringContainsString('data-svc-variant="'.$introVariant.'"', $html);
        $this->assertStringContainsString('data-svc-variant="'.$featuresVariant.'"', $html);
        $this->assertStringContainsString('What&#039;s Included', $html);
        $this->assertStringContainsString('Item 1', $html);
    }

    public function test_every_preset_fixture_renders_without_losing_content(): void
    {
        $presets = ['editorial', 'showcase', 'precision'];
        $cases = 0;

        foreach ($presets as $preset) {
            $recipe = config("site_service_layouts.{$preset}");
            $this->assertIsArray($recipe, "missing recipe for {$preset}");
            $introVariant = $recipe['variants']['intro'];
            $featuresVariant = $recipe['variants']['features'];

            foreach ($this->fixtures() as $name => $fixture) {
                $introHtml = View::make('site.sections.intro', $this->introVars($introVariant, $fixture))->render();
                $featuresHtml = View::make('site.sections.features', $this->featuresVars($featuresVariant, $fixture))->render();

                $this->assertNotSame('', trim($introHtml), "{$preset}/{$name} intro was empty");
                $this->assertNotSame('', trim($featuresHtml), "{$preset}/{$name} features was empty");
                $this->assertStringContainsString(
                    'data-svc-variant="'.$introVariant.'"',
                    $introHtml,
                    "{$preset}/{$name} intro variant mismatch",
                );
                $this->assertStringContainsString(
                    'data-svc-variant="'.$featuresVariant.'"',
                    $featuresHtml,
                    "{$preset}/{$name} features variant mismatch",
                );

                $escapedTitle = e($fixture['title']);
                $this->assertStringContainsString($escapedTitle, $introHtml, "{$preset}/{$name} intro dropped title");
                $this->assertStringContainsString($escapedTitle, $featuresHtml, "{$preset}/{$name} features dropped title");

                foreach ($fixture['item_titles'] as $itemTitle) {
                    $this->assertStringContainsString(
                        $itemTitle,
                        $featuresHtml,
                        "{$preset}/{$name} dropped item title [{$itemTitle}]",
                    );
                }

                $cases++;
            }
        }

        $this->assertSame(21, $cases);
    }

    /**
     * @return array<string, array{title: string, body: string, item_count: int, empty_item_body: bool, intro_image: ?string, item_titles: list<string>}>
     */
    private function fixtures(): array
    {
        $longTitle = str_repeat('Extensions And Loft Conversions X', 4);
        $this->assertGreaterThanOrEqual(120, strlen($longTitle));

        return [
            'no-image' => $this->makeFixture(introImage: null),
            '2-items' => $this->makeFixture(itemCount: 2),
            '12-items' => $this->makeFixture(itemCount: 12),
            'one-sentence-body' => $this->makeFixture(body: 'A single sentence of intro copy.'),
            'six-paragraph-body' => $this->makeFixture(body: implode("\n\n", [
                'Paragraph one of the service intro.',
                'Paragraph two adds scope.',
                'Paragraph three covers access.',
                'Paragraph four notes materials.',
                'Paragraph five mentions finish.',
                'Paragraph six closes the brief.',
            ])),
            '120-char-title' => $this->makeFixture(title: $longTitle),
            'empty-item-body' => $this->makeFixture(emptyItemBody: true),
        ];
    }

    /**
     * @return array{title: string, body: string, item_count: int, empty_item_body: bool, intro_image: ?string, item_titles: list<string>}
     */
    private function makeFixture(
        ?string $title = null,
        ?string $body = null,
        int $itemCount = 6,
        bool $emptyItemBody = false,
        ?string $introImage = 'https://example.test/intro.jpg',
    ): array {
        $title ??= "What's Included";
        $itemTitles = [];
        foreach (range(1, $itemCount) as $n) {
            $itemTitles[] = "Item {$n}";
        }

        return [
            'title' => $title,
            'body' => $body ?? "First paragraph of prose.\n\nSecond paragraph with detail.",
            'item_count' => $itemCount,
            'empty_item_body' => $emptyItemBody,
            'intro_image' => $introImage,
            'item_titles' => $itemTitles,
        ];
    }

    /**
     * @param  array{title: string, body: string, intro_image: ?string}  $fixture
     * @return array<string, mixed>
     */
    private function introVars(string $variant, array $fixture): array
    {
        return [
            'section' => [
                'type' => 'intro',
                'variant' => $variant,
                'title' => $fixture['title'],
                'eyebrow' => 'About This Service',
                'body' => $fixture['body'],
            ],
            'sectionIndex' => 1,
            'pageId' => 42,
            'mode' => 'public',
            'emitMarkers' => true,
            'emitFormMarkers' => false,
            'schema' => [],
            'theme' => [],
            'profile' => ['watermark_enabled' => false],
            'introImageUrl' => $fixture['intro_image'],
        ];
    }

    /**
     * @param  array{title: string, item_count: int, empty_item_body: bool, intro_image: ?string}  $fixture
     * @return array<string, mixed>
     */
    private function featuresVars(string $variant, array $fixture): array
    {
        $items = [];
        foreach (range(1, $fixture['item_count']) as $n) {
            $items[] = [
                'icon' => 'hammer',
                'title' => "Item {$n}",
                'body' => $fixture['empty_item_body'] ? '' : "Body {$n}.",
            ];
        }

        return [
            'section' => [
                'type' => 'features',
                'variant' => $variant,
                'title' => $fixture['title'],
                'eyebrow' => "What's Included",
                'intro' => 'Scope intro line.',
                'items' => $items,
            ],
            'sectionIndex' => 2,
            'pageId' => 42,
            'mode' => 'public',
            'emitMarkers' => true,
            'emitFormMarkers' => false,
            'schema' => [],
            'theme' => [],
            'introImageUrl' => $fixture['intro_image'],
        ];
    }
}
