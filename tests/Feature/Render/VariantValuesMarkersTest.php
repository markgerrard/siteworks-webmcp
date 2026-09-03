<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantValuesMarkersTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $sectionOverrides
     * @return array<string, mixed>
     */
    private function valuesVars(string $variant, bool $markers, int $count = 5, array $sectionOverrides = []): array
    {
        $items = [];
        foreach (range(1, $count) as $n) {
            $items[] = ['title' => "Value {$n}", 'body' => "Conviction {$n}."];
        }

        return [
            'section' => array_merge([
                'type' => 'values',
                'variant' => $variant,
                'title' => 'What We Stand For',
                'eyebrow' => 'Our Values',
                'items' => $items,
            ], $sectionOverrides),
            'sectionIndex' => 2,
            'pageId' => 42,
            'mode' => 'public',
            'emitMarkers' => $markers,
            'emitFormMarkers' => false,
            'schema' => [],
            'theme' => [],
            'profile' => ['watermark_enabled' => false],
            'heroImageUrl' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $sectionOverrides
     * @param  array<string, mixed>  $viewOverrides
     */
    private function render(bool $markers, int $count = 5, array $sectionOverrides = [], array $viewOverrides = []): string
    {
        return View::make(
            'site.sections.values',
            array_merge($this->valuesVars('markers', $markers, $count, $sectionOverrides), $viewOverrides),
        )->render();
    }

    /**
     * @param  array<string, mixed>  $sectionOverrides
     */
    private function renderClassic(bool $markers, int $count = 5, array $sectionOverrides = []): string
    {
        return View::make('site.sections.values', $this->valuesVars('classic', $markers, $count, $sectionOverrides))->render();
    }

    /**
     * @return list<string>
     */
    private function editableFields(string $html): array
    {
        preg_match_all('/data-editable-field="([^"]+)"/', $html, $matches);

        $fields = array_values(array_unique($matches[1] ?? []));
        sort($fields);

        return $fields;
    }

    public function test_routes_to_markers_and_keeps_all_content(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('data-svc-variant="markers"', $html);
        $this->assertStringContainsString('What We Stand For', $html);
        $this->assertStringContainsString('Our Values', $html);

        foreach (range(1, 5) as $n) {
            $this->assertStringContainsString("Value {$n}", $html);
            $this->assertStringContainsString("Conviction {$n}.", $html);
        }
    }

    public function test_three_items_render_all(): void
    {
        $html = $this->render(false, 3);

        $this->assertStringContainsString('data-svc-variant="markers"', $html);
        $this->assertSame(3, preg_match_all('/>\+<\/span>/', $html));

        foreach (range(1, 3) as $n) {
            $this->assertStringContainsString("Value {$n}", $html);
            $this->assertStringContainsString("Conviction {$n}.", $html);
        }
    }

    public function test_ten_items_render_all_without_clamping(): void
    {
        $html = $this->render(false, 10);

        $this->assertStringContainsString('data-svc-variant="markers"', $html);
        $this->assertSame(10, preg_match_all('/>\+<\/span>/', $html));

        foreach (range(1, 10) as $n) {
            $this->assertStringContainsString("Value {$n}", $html, "missing title {$n}");
            $this->assertStringContainsString("Conviction {$n}.", $html, "missing body {$n}");
        }
    }

    public function test_does_not_emit_ordinal_markers(): void
    {
        $html = $this->render(false, 10);

        $this->assertStringContainsString('data-svc-variant="markers"', $html);
        $this->assertSame(10, preg_match_all('/>\+<\/span>/', $html));
        $this->assertStringNotContainsString('>01<', $html);
        $this->assertStringNotContainsString('>02<', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*0[1-9]\s*</', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*\d+\.\s*</', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*\d+\s*</', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*[A-Z]\s*</', $html);
    }

    public function test_hero_image_sits_in_the_left_column_with_markers_in_one_column(): void
    {
        $html = $this->render(false, 5, [], [
            'heroImageUrl' => 'https://example.test/about-hero.jpg',
        ]);

        $this->assertStringContainsString('data-svc-variant="markers"', $html);
        $this->assertStringContainsString('lg:grid-cols-5', $html);
        $this->assertStringContainsString('lg:col-span-2', $html);
        $this->assertStringContainsString('lg:col-span-3', $html);
        $this->assertStringContainsString('src="https://example.test/about-hero.jpg"', $html);
        $this->assertStringContainsString('aspect-ratio: 4 / 3', $html);
        $this->assertStringContainsString('border: 1px solid', $html);
        $this->assertSame(5, preg_match_all('/>\+<\/span>/', $html));

        $this->assertMatchesRegularExpression(
            '/lg:col-span-2[\s\S]+src="https:\/\/example\.test\/about-hero\.jpg"[\s\S]+lg:col-span-3/',
            $html,
            'image (col-span-2) must precede the markers list (col-span-3) so the image sits LEFT on lg',
        );

        $this->assertStringNotContainsString('columns-2', $html);
        $this->assertStringNotContainsString('mx-auto', $html);
        $this->assertStringNotContainsString('rounded', $html);
        $this->assertStringNotContainsString('>01<', $html);
    }

    public function test_no_image_falls_back_to_two_column_marker_density(): void
    {
        $html = $this->render(false, 5);

        $this->assertStringContainsString('data-svc-variant="markers"', $html);
        $this->assertStringContainsString('md:columns-2', $html);
        $this->assertStringContainsString('What We Stand For', $html);
        $this->assertSame(5, preg_match_all('/>\+<\/span>/', $html));

        foreach (range(1, 5) as $n) {
            $this->assertStringContainsString("Value {$n}", $html);
            $this->assertStringContainsString("Conviction {$n}.", $html);
        }

        $this->assertStringNotContainsString('lg:grid-cols-5', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<figure', $html);
        $this->assertStringNotContainsString('mx-auto', $html);
        $this->assertStringNotContainsString('md:grid-cols-2', $html);
        $this->assertStringNotContainsString('md:grid-cols-3', $html);
    }

    public function test_production_array_hero_uses_watermark_url_when_present(): void
    {
        $html = $this->render(false, 3, [], [
            'profile' => ['watermark_enabled' => true],
            'heroImageUrl' => [
                'url' => 'https://example.test/hero-raw.jpg',
                'watermark_url' => 'https://example.test/hero-wm.jpg',
            ],
        ]);

        $this->assertStringContainsString('data-svc-variant="markers"', $html);
        $this->assertStringContainsString('src="https://example.test/hero-wm.jpg"', $html);
        $this->assertStringContainsString('lg:col-span-2', $html);
        $this->assertStringNotContainsString('https://example.test/hero-raw.jpg', $html);
    }

    public function test_emits_at_least_classic_editor_fields(): void
    {
        $html = $this->render(true, 5);
        $this->assertStringContainsString('data-svc-variant="markers"', $html);

        $classic = $this->editableFields($this->renderClassic(true, 5));
        $markers = $this->editableFields($html);

        $this->assertNotEmpty($classic);
        foreach ($classic as $field) {
            $this->assertContains($field, $markers, "markers missing classic field {$field}");
        }
    }

    public function test_ten_items_emit_item_markers_classic_would_clamp(): void
    {
        $html = $this->render(true, 10);

        $this->assertStringContainsString('data-svc-variant="markers"', $html);
        $this->assertStringContainsString('data-editable-field="eyebrow"', $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.title"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.body"', $html);
        $this->assertStringContainsString('data-editable-field="items.9.title"', $html);
        $this->assertStringContainsString('data-editable-field="items.9.body"', $html);
    }

    public function test_suppressed_eyebrow_emits_hidden_marker(): void
    {
        $html = $this->render(true, 5, ['__suppress_eyebrow' => true]);

        $this->assertMatchesRegularExpression(
            '/<span class="hidden"[^>]*data-editable-field="eyebrow"/',
            $html,
        );
        $this->assertStringNotContainsString('Our Values</span>', $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('What We Stand For', $html);
    }

    public function test_uses_token_surface_not_bg_white(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('data-svc-variant="markers"', $html);
        $this->assertStringContainsString('var(--color-surface)', $html);
        $this->assertStringNotContainsString('bg-white', $html);
    }
}
