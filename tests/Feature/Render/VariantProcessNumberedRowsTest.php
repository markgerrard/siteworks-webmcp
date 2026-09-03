<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantProcessNumberedRowsTest extends TestCase
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
        return View::make('site.sections.process', $this->processVars('numbered-rows', $markers, $count, $sectionOverrides))->render();
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

    public function test_routes_to_numbered_rows_and_keeps_all_content(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringContainsString('How it works', $html);
        $this->assertStringContainsString('Our Process', $html);
        $this->assertStringContainsString('>01<', $html);
        $this->assertStringContainsString('>04<', $html);

        foreach (range(1, 4) as $n) {
            $this->assertStringContainsString("Step {$n}", $html);
            $this->assertStringContainsString("Detail {$n}.", $html);
        }
    }

    public function test_renders_all_items(): void
    {
        $html = $this->render(false, 6);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringContainsString('>01<', $html);
        $this->assertStringContainsString('>06<', $html);

        foreach (range(1, 6) as $n) {
            $this->assertStringContainsString("Step {$n}", $html, "missing title {$n}");
            $this->assertStringContainsString("Detail {$n}.", $html, "missing body {$n}");
        }
    }

    public function test_two_items_render_without_error(): void
    {
        $html = $this->render(false, 2);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringContainsString('>01<', $html);
        $this->assertStringContainsString('>02<', $html);
        $this->assertStringContainsString('Step 1', $html);
        $this->assertStringContainsString('Step 2', $html);
        $this->assertStringContainsString('Detail 1.', $html);
        $this->assertStringContainsString('Detail 2.', $html);
    }

    public function test_indexes_come_from_position_not_stored_step(): void
    {
        $html = $this->render(false, 2, [
            'items' => [
                ['step' => 'A', 'title' => 'Survey', 'body' => 'Visit first.'],
                ['step' => '99', 'title' => 'Quote', 'body' => 'Then price.'],
            ],
        ]);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringContainsString('>01<', $html);
        $this->assertStringContainsString('>02<', $html);
        $this->assertStringContainsString('Survey', $html);
        $this->assertStringContainsString('Quote', $html);
        $this->assertStringNotContainsString('>A<', $html);
        $this->assertStringNotContainsString('>99<', $html);
    }

    public function test_emits_at_least_classic_editor_fields(): void
    {
        $html = $this->render(true, 4);
        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);

        $classic = $this->editableFields($this->renderClassic(true, 4));
        $actual = $this->editableFields($html);

        $this->assertNotEmpty($classic);
        foreach ($classic as $field) {
            $this->assertContains($field, $actual, "numbered-rows missing classic field {$field}");
        }
    }

    public function test_six_items_emit_item_markers_including_step(): void
    {
        $html = $this->render(true, 6);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringContainsString('data-editable-field="eyebrow"', $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.step"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.title"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.body"', $html);
        $this->assertStringContainsString('data-editable-field="items.5.step"', $html);
        $this->assertStringContainsString('data-editable-field="items.5.title"', $html);
        $this->assertStringContainsString('data-editable-field="items.5.body"', $html);
        $this->assertMatchesRegularExpression(
            '/data-editable-field="items.0.body"[^>]*data-editable-type="rich"|data-editable-type="rich"[^>]*data-editable-field="items.0.body"/',
            $html,
        );
    }

    public function test_suppressed_eyebrow_emits_hidden_marker(): void
    {
        $html = $this->render(true, 4, ['__suppress_eyebrow' => true]);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertMatchesRegularExpression(
            '/<span class="hidden"[^>]*data-editable-field="eyebrow"/',
            $html,
        );
        $this->assertStringNotContainsString('Our Process</span>', $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('How it works', $html);
    }

    public function test_does_not_emit_ordinal_circles(): void
    {
        $html = $this->render(false, 4);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringContainsString('tabular-nums', $html);
        $this->assertStringNotContainsString('rounded-full', $html);
        $this->assertStringNotContainsString('w-20 h-20 rounded-full', $html);
    }

    public function test_reuses_numbered_row_chrome(): void
    {
        $html = $this->render(false, 4);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringContainsString('grid-cols-[3.5rem_1fr]', $html);
        $this->assertStringContainsString('md:grid-cols-[5rem_minmax(0,1fr)_minmax(0,1.4fr)]', $html);
        $this->assertStringContainsString('font-light tabular-nums', $html);
    }

    public function test_honours_surface_contrast(): void
    {
        $html = $this->render(false, 4, ['__surface' => 'contrast']);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringContainsString('background-color: var(--color-surface-contrast)', $html);
        $this->assertStringContainsString('var(--color-text-on-contrast)', $html);
        $this->assertStringContainsString('var(--color-text-muted-on-contrast)', $html);
        $this->assertStringContainsString('site-section-spacing', $html);
        $this->assertStringNotContainsString('background-color: var(--color-surface-alt)', $html);
    }

    public function test_absent_surface_uses_kit_surface_token(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringContainsString('background-color: var(--color-surface)', $html);
        $this->assertStringNotContainsString('var(--color-surface-contrast)', $html);
        $this->assertStringNotContainsString('bg-white', $html);
        $this->assertStringContainsString('pt-10 lg:pt-12', $html);
    }

    public function test_collapses_to_single_content_column_below_md(): void
    {
        $html = $this->render(false, 4);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringContainsString('grid-cols-[3.5rem_1fr]', $html);
        $this->assertStringContainsString('md:grid-cols-[5rem_minmax(0,1fr)_minmax(0,1.4fr)]', $html);
        $this->assertStringNotContainsString('md:columns-2', $html);
        $this->assertStringNotContainsString('md:grid-cols-3', $html);
    }
}
