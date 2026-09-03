<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Render-level QA matrix: every non-classic about preset × edge fixture
 * through the real story/values dispatchers. No DB. Failures are fixed
 * in the variant partials, not by weakening these assertions.
 */
class AboutLayoutQaMatrixTest extends TestCase
{
    public function test_stock_precision_alternates_images_with_zero_options(): void
    {
        $fixture = $this->makeFixture();
        $storyVars = $this->storyVars('document', $fixture);
        $storyVars['introImageUrl'] = 'https://example.test/story.jpg';
        $story = \Illuminate\Support\Facades\View::make('site.sections.story', $storyVars)->render();
        // Branch-unique pairs (the bare order token appears in both branches):
        // image RIGHT -> prose carries border-r/pr-14; image LEFT would give
        // prose order-last + border-l instead.
        $this->assertStringContainsString('lg:border-r lg:pr-14', $story, 'stock precision story image sits RIGHT');
        $this->assertStringNotContainsString('lg:order-last lg:border-l', $story, 'and never renders the image-left prose pairing');

        $valuesVars = $this->valuesVars('markers', $fixture);
        $valuesVars['heroImageUrl'] = 'https://example.test/hero.jpg';
        $valuesVars['bandImageUrl'] = null;
        $values = \Illuminate\Support\Facades\View::make('site.sections.values', $valuesVars)->render();
        $this->assertStringNotContainsString('lg:order-last', $values, 'stock precision values image sits LEFT (never pushed right)');
        $this->assertStringContainsString('lg:border-l lg:pl-14', $values, 'list carries the divider on its left, beside the image');
    }

    public function test_image_led_values_variants_render_their_image_ladder(): void
    {
        $fixture = $this->makeFixture();
        foreach ([['statements', true], ['markers', true], ['ledger', false]] as [$variant, $expectImage]) {
            $vars = $this->valuesVars($variant, $fixture);
            $vars['heroImageUrl'] = 'https://example.test/hero.jpg';
            $vars['bandImageUrl'] = null;
            if ($variant === 'ledger') {
                $vars['section']['__options'] = ['side_image' => true];
                $expectImage = true;
            }
            $html = \Illuminate\Support\Facades\View::make('site.sections.values', $vars)->render();
            if ($expectImage) {
                $this->assertStringContainsString('https://example.test/hero.jpg', $html, "values/{$variant} must fall back to the hero image");
            }
        }
    }

    public function test_every_preset_story_variant_renders_the_full_six_paragraph_body(): void
    {
        $paras = [];
        foreach (range(1, 6) as $n) {
            $paras[] = "Paragraph {$n} of the story body.";
        }
        $body = implode("\n\n", $paras);
        foreach (['editorial', 'banner-overlap', 'document'] as $variant) {
            $html = \Illuminate\Support\Facades\View::make('site.sections.story', [
                'section' => ['type' => 'story', 'title' => 'Our Story', 'variant' => $variant, 'body' => $body],
                'sectionIndex' => 1, 'pageId' => 42, 'mode' => 'public',
                'emitMarkers' => false, 'emitFormMarkers' => false,
                'schema' => [], 'theme' => [], 'profile' => [],
                'introImageUrl' => null,
            ])->render();
            foreach ($paras as $ptext) {
                $this->assertStringContainsString($ptext, $html, "story/{$variant} dropped body copy");
            }
            if ($variant === 'banner-overlap') {
                // No-image branch must still carry exactly ONE rich body
                // container (the editor-truncation regression guard).
                $marked = \Illuminate\Support\Facades\View::make('site.sections.story', [
                    'section' => ['type' => 'story', 'title' => 'Our Story', 'variant' => $variant, 'body' => $body],
                    'sectionIndex' => 1, 'pageId' => 42, 'mode' => 'public',
                    'emitMarkers' => true, 'emitFormMarkers' => false,
                    'schema' => [], 'theme' => [], 'profile' => [],
                    'introImageUrl' => null,
                ])->render();
                $this->assertSame(1, substr_count($marked, 'data-editable-type="rich"'), 'banner-overlap no-image branch must keep one rich body container');
            }
        }
    }

    public function test_every_preset_fixture_renders_without_losing_content(): void
    {
        $presets = ['editorial', 'showcase', 'precision'];
        $cases = 0;

        foreach ($presets as $preset) {
            $recipe = config("site_about_layouts.{$preset}");
            $this->assertIsArray($recipe, "missing recipe for {$preset}");
            $storyVariant = $recipe['variants']['story'];
            $valuesVariant = $recipe['variants']['values'];

            foreach ($this->fixtures() as $name => $fixture) {
                $storyHtml = View::make('site.sections.story', $this->storyVars($storyVariant, $fixture))->render();
                $valuesHtml = View::make('site.sections.values', $this->valuesVars($valuesVariant, $fixture))->render();

                $this->assertNotSame('', trim($storyHtml), "{$preset}/{$name} story was empty");
                $this->assertNotSame('', trim($valuesHtml), "{$preset}/{$name} values was empty");
                $this->assertStringContainsString(
                    'data-svc-variant="'.$storyVariant.'"',
                    $storyHtml,
                    "{$preset}/{$name} story variant mismatch",
                );
                $this->assertStringContainsString(
                    'data-svc-variant="'.$valuesVariant.'"',
                    $valuesHtml,
                    "{$preset}/{$name} values variant mismatch",
                );

                $escapedTitle = e($fixture['title']);
                $this->assertStringContainsString($escapedTitle, $storyHtml, "{$preset}/{$name} story dropped title");
                $this->assertStringContainsString($escapedTitle, $valuesHtml, "{$preset}/{$name} values dropped title");

                foreach ($fixture['item_titles'] as $itemTitle) {
                    $this->assertStringContainsString(
                        $itemTitle,
                        $valuesHtml,
                        "{$preset}/{$name} dropped item title [{$itemTitle}]",
                    );
                }

                if ($preset !== 'editorial') {
                    // The ordinal ban is overridden for the
                    // editorial ledger: it numbers rows like the service
                    // page. Statements/markers stay ordinal-free.
                    $this->assertDoesNotMatchRegularExpression('/>\s*0[1-9]\s*</', $valuesHtml, "{$preset}/{$name} ordinal 01");
                    $this->assertStringNotContainsString('>01<', $valuesHtml);
                }
                $this->assertDoesNotMatchRegularExpression('/>\s*\d+\.\s*</', $valuesHtml, "{$preset}/{$name} ordinal 1.");

                $cases++;
            }
        }

        $this->assertSame(27, $cases);
    }

    public function test_designed_seam_pairs_declare_their_spacing_classes(): void
    {
        $pairs = [
            'editorial' => ['story' => ['pb-10', 'lg:pb-12'], 'values' => ['pt-10', 'lg:pt-12']],
            // showcase story v3 is a full-bleed brand panel — the background
            // CHANGES at the seam, so no tightened classes are required on
            // the story side (same rule as the service split -> checklist).
            'showcase' => ['story' => [], 'values' => ['pt-10', 'lg:pt-12']],
            'precision' => ['story' => ['pb-8', 'lg:pb-10'], 'values' => ['pt-10', 'lg:pt-12']],
        ];

        $fixture = $this->makeFixture();

        foreach ($pairs as $preset => $classes) {
            $recipe = config("site_about_layouts.{$preset}");
            $this->assertIsArray($recipe, "missing recipe for {$preset}");

            $storyHtml = View::make('site.sections.story', $this->storyVars($recipe['variants']['story'], $fixture))->render();
            $valuesHtml = View::make('site.sections.values', $this->valuesVars($recipe['variants']['values'], $fixture))->render();

            foreach ($classes['story'] as $class) {
                $this->assertStringContainsString($class, $storyHtml, "{$preset} story missing seam class {$class}");
            }
            foreach ($classes['values'] as $class) {
                $this->assertStringContainsString($class, $valuesHtml, "{$preset} values missing seam class {$class}");
            }
        }
    }

    public function test_about_variant_files_have_no_wide_fixed_pixel_widths(): void
    {
        $files = array_values(array_filter(
            array_merge(
                glob(resource_path('views/site/sections/variants/story/*.blade.php')) ?: [],
                glob(resource_path('views/site/sections/variants/values/*.blade.php')) ?: [],
            ),
            fn (string $path): bool => ! str_ends_with($path, '/classic.blade.php'),
        ));

        $this->assertNotEmpty($files, 'expected non-classic story/values variant partials');

        foreach ($files as $path) {
            $contents = File::get($path);
            preg_match_all('/w-\[(\d+)px\]/', $contents, $matches);
            foreach ($matches[1] as $px) {
                $this->assertLessThan(
                    480,
                    (int) $px,
                    basename($path).' has overflow-forcing w-['.$px.'px]',
                );
            }
            $this->assertDoesNotMatchRegularExpression(
                '/(?<!md:)(?<!lg:)(?<!sm:)(?<!xl:)columns-2/',
                $contents,
                basename($path).' declares columns-2 without an md: (or larger) prefix',
            );
        }
    }

    /**
     * @return array<string, array{title: string, body: string, item_count: int, empty_item_body: bool, intro_image: mixed, item_titles: list<string>}>
     */
    private function fixtures(): array
    {
        $longTitle = str_repeat('Extensions And Loft Conversions X', 4);
        $this->assertGreaterThanOrEqual(120, strlen($longTitle));

        $productionImage = [
            'url' => 'https://example.test/raw.jpg',
            'watermark_url' => 'https://example.test/wm.jpg',
        ];

        return [
            'no-image' => $this->makeFixture(introImage: null),
            '2-items' => $this->makeFixture(itemCount: 2),
            '3-items' => $this->makeFixture(itemCount: 3),
            '5-items' => $this->makeFixture(itemCount: 5),
            'one-sentence-story' => $this->makeFixture(body: 'A single sentence of about copy.'),
            'six-paragraph-story' => $this->makeFixture(body: implode("\n\n", [
                'Paragraph one of the about story.',
                'Paragraph two adds scope.',
                'Paragraph three covers access.',
                'Paragraph four notes materials.',
                'Paragraph five mentions finish.',
                'Paragraph six closes the brief.',
            ])),
            '120-char-title' => $this->makeFixture(title: $longTitle),
            'empty-item-body' => $this->makeFixture(emptyItemBody: true),
            'production-image-array' => $this->makeFixture(introImage: $productionImage),
        ];
    }

    /**
     * @return array{title: string, body: string, item_count: int, empty_item_body: bool, intro_image: mixed, item_titles: list<string>}
     */
    private function makeFixture(
        ?string $title = null,
        ?string $body = null,
        int $itemCount = 5,
        bool $emptyItemBody = false,
        mixed $introImage = 'https://example.test/story.jpg',
    ): array {
        $title ??= 'What We Stand For';
        $itemTitles = [];
        foreach (range(1, $itemCount) as $n) {
            $itemTitles[] = "Value {$n}";
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
     * @param  array{title: string, body: string, intro_image: mixed}  $fixture
     * @return array<string, mixed>
     */
    private function storyVars(string $variant, array $fixture): array
    {
        return [
            'section' => [
                'type' => 'story',
                'variant' => $variant,
                'title' => $fixture['title'],
                'eyebrow' => 'About Us',
                'body' => $fixture['body'],
            ],
            'sectionIndex' => 1,
            'pageId' => 42,
            'mode' => 'public',
            'emitMarkers' => true,
            'emitFormMarkers' => false,
            'schema' => [],
            'theme' => [],
            'profile' => ['watermark_enabled' => true],
            'introImageUrl' => $fixture['intro_image'],
        ];
    }

    /**
     * @param  array{title: string, item_count: int, empty_item_body: bool, item_titles: list<string>}  $fixture
     * @return array<string, mixed>
     */
    private function valuesVars(string $variant, array $fixture): array
    {
        $items = [];
        foreach ($fixture['item_titles'] as $n => $itemTitle) {
            $items[] = [
                'title' => $itemTitle,
                'body' => $fixture['empty_item_body'] ? '' : 'Conviction '.($n + 1).'.',
            ];
        }

        return [
            'section' => [
                'type' => 'values',
                'variant' => $variant,
                'title' => $fixture['title'],
                'eyebrow' => 'Our Values',
                'items' => $items,
            ],
            'sectionIndex' => 2,
            'pageId' => 42,
            'mode' => 'public',
            'emitMarkers' => true,
            'emitFormMarkers' => false,
            'schema' => [],
            'theme' => [],
        ];
    }
}
