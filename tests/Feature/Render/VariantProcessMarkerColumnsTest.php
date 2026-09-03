<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantProcessMarkerColumnsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $sectionOverrides
     * @return array<string, mixed>
     */
    private function processVars(string $variant, bool $markers, int $count = 4, array $sectionOverrides = []): array
    {
        $items = $sectionOverrides['items'] ?? $this->defaultItems($count);
        unset($sectionOverrides['items']);

        return [
            'section' => array_merge([
                'type' => 'process',
                'variant' => $variant,
                'title' => 'How it works',
                'eyebrow' => 'Our Process',
                'items' => $items,
            ], $sectionOverrides),
            'sectionIndex' => 1,
            'pageId' => 42,
            'mode' => 'public',
            'emitMarkers' => $markers,
            'emitFormMarkers' => false,
            'schema' => [],
            'theme' => [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function defaultItems(int $count): array
    {
        $items = [];
        foreach (range(1, $count) as $n) {
            $items[] = [
                'step' => (string) $n,
                'title' => "Step {$n}",
                'body' => "Detail {$n}.",
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $sectionOverrides
     */
    private function render(bool $markers, int $count = 4, array $sectionOverrides = []): string
    {
        return View::make('site.sections.process', $this->processVars('marker-columns', $markers, $count, $sectionOverrides))->render();
    }

    /**
     * @param  array<string, mixed>  $sectionOverrides
     */
    private function renderClassic(bool $markers, int $count = 4, array $sectionOverrides = []): string
    {
        return View::make('site.sections.process', $this->processVars('classic', $markers, $count, $sectionOverrides))->render();
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

    public function test_routes_to_marker_columns_and_keeps_all_content(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertStringContainsString('How it works', $html);
        $this->assertStringContainsString('Our Process', $html);
        $this->assertSame(4, preg_match_all('/>\d{2}<\/span>/', $html));

        foreach (range(1, 4) as $n) {
            $this->assertStringContainsString("Step {$n}", $html);
            $this->assertStringContainsString("Detail {$n}.", $html);
        }
    }

    public function test_renders_all_items(): void
    {
        $html = $this->render(false, 12);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertSame(12, preg_match_all('/>\d{2}<\/span>/', $html));

        foreach (range(1, 12) as $n) {
            $this->assertStringContainsString("Step {$n}", $html, "missing title {$n}");
            $this->assertStringContainsString("Detail {$n}.", $html, "missing body {$n}");
        }
    }

    public function test_three_items_render_all(): void
    {
        $html = $this->render(false, 3);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertSame(3, preg_match_all('/>\d{2}<\/span>/', $html));

        foreach (range(1, 3) as $n) {
            $this->assertStringContainsString("Step {$n}", $html);
            $this->assertStringContainsString("Detail {$n}.", $html);
        }
    }

    public function test_emits_positional_ordinals_without_circles(): void
    {
        $html = $this->render(false, 10);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertSame(10, preg_match_all('/>\d{2}<\/span>/', $html));
        $this->assertStringContainsString('>01<', $html);
        $this->assertStringContainsString('>10<', $html);
        $this->assertStringContainsString('tabular-nums', $html);
        $this->assertStringNotContainsString('rounded-full', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*\d+\.\s*</', $html);
    }

    public function test_stored_step_values_never_leak_into_the_positional_ordinals(): void
    {
        $html = $this->render(false, 2, [
            'items' => [
                ['step' => 'A', 'title' => 'Survey', 'body' => 'Visit first.'],
                ['step' => '99', 'title' => 'Quote', 'body' => 'Then price.'],
            ],
        ]);

        $this->assertStringContainsString('>01<', $html);
        $this->assertStringContainsString('>02<', $html);
        $this->assertStringNotContainsString('0A', $html);
        $this->assertStringNotContainsString('>99<', $html);
    }

    public function test_reuses_markers_chrome_columns_and_cluster_dividers(): void
    {
        $html = $this->render(false, 6);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertStringContainsString('md:columns-2', $html);
        $this->assertStringContainsString('md:gap-x-16', $html);
        $this->assertStringContainsString('break-inside-avoid', $html);
        $this->assertStringContainsString('grid-cols-[1.6rem_1fr]', $html);
        $this->assertStringContainsString('color-mix(in oklab, var(--color-text) 28%', $html);
        $this->assertStringContainsString('color-mix(in oklab, var(--color-text) 12%', $html);
    }

    public function test_emits_at_least_classic_editor_fields(): void
    {
        $html = $this->render(true, 4);
        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);

        $classic = $this->editableFields($this->renderClassic(true, 4));
        $actual = $this->editableFields($html);

        $this->assertNotEmpty($classic);
        foreach ($classic as $field) {
            $this->assertContains($field, $actual, "marker-columns missing classic field {$field}");
        }
    }

    public function test_twelve_items_emit_item_markers_including_step(): void
    {
        $html = $this->render(true, 12);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertStringContainsString('data-editable-field="eyebrow"', $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.step"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.title"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.body"', $html);
        $this->assertStringContainsString('data-editable-field="items.11.step"', $html);
        $this->assertStringContainsString('data-editable-field="items.11.title"', $html);
        $this->assertStringContainsString('data-editable-field="items.11.body"', $html);
        $this->assertMatchesRegularExpression(
            '/data-editable-field="items.0.body"[^>]*data-editable-type="rich"|data-editable-type="rich"[^>]*data-editable-field="items.0.body"/',
            $html,
        );
    }

    public function test_suppressed_eyebrow_emits_hidden_marker(): void
    {
        $html = $this->render(true, 4, ['__suppress_eyebrow' => true]);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertMatchesRegularExpression(
            '/<span class="hidden"[^>]*data-editable-field="eyebrow"/',
            $html,
        );
        $this->assertStringNotContainsString('Our Process</span>', $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('How it works', $html);
    }

    public function test_honours_surface_contrast(): void
    {
        $html = $this->render(false, 4, ['__surface' => 'contrast']);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertStringContainsString('background-color: var(--color-surface-contrast)', $html);
        $this->assertStringContainsString('var(--color-text-on-contrast)', $html);
        $this->assertStringContainsString('var(--color-text-muted-on-contrast)', $html);
        $this->assertStringContainsString('site-section-spacing', $html);
        $this->assertStringNotContainsString('background-color: var(--color-surface-alt)', $html);
    }

    public function test_absent_surface_uses_kit_surface_token(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertStringContainsString('background-color: var(--color-surface)', $html);
        $this->assertStringNotContainsString('var(--color-surface-contrast)', $html);
        $this->assertStringNotContainsString('bg-white', $html);
        $this->assertStringContainsString('pt-10 lg:pt-12', $html);
    }

    public function test_collapses_to_single_column_below_md(): void
    {
        $html = $this->render(false, 6);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertStringContainsString('md:columns-2', $html);
        $this->assertDoesNotMatchRegularExpression('/(?<!md:)columns-2/', $html);
        $this->assertStringNotContainsString('md:grid-cols-2', $html);
        $this->assertStringNotContainsString('md:grid-cols-3', $html);
    }
    public function test_markers_are_step_numerals_not_plus(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('>01</span>', $html);
        $this->assertStringContainsString('>04</span>', $html);
        $this->assertStringNotContainsString('>+</span>', $html, 'process disambiguates column reading order with numerals; "+" belongs to trust');
    }
}
