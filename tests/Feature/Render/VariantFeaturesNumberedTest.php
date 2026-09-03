<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantFeaturesNumberedTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $sectionOverrides
     * @return array<string, mixed>
     */
    private function featuresVars(bool $markers, int $count = 6, array $sectionOverrides = []): array
    {
        $items = [];
        foreach (range(1, $count) as $n) {
            $items[] = ['icon' => 'hammer', 'title' => "Item {$n}", 'body' => "Body {$n}."];
        }

        return [
            'section' => array_merge([
                'type' => 'features',
                'variant' => 'numbered',
                'title' => "What's Included",
                'eyebrow' => "What's Included",
                'intro' => 'Scope intro line.',
                'items' => $items,
            ], $sectionOverrides),
            'sectionIndex' => 2,
            'pageId' => 42,
            'mode' => 'public',
            'emitMarkers' => $markers,
            'emitFormMarkers' => false,
            'schema' => [],
            'theme' => [],
        ];
    }

    private function render(bool $markers, int $count = 6, array $sectionOverrides = []): string
    {
        return View::make('site.sections.features', $this->featuresVars($markers, $count, $sectionOverrides))->render();
    }

    public function test_six_items_emit_variant_tag_zero_padded_indexes_and_copy(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('data-svc-variant="numbered"', $html);
        $this->assertStringContainsString('01', $html);
        $this->assertStringContainsString('06', $html);

        foreach (range(1, 6) as $n) {
            $this->assertStringContainsString("Item {$n}", $html);
            $this->assertStringContainsString("Body {$n}.", $html);
        }
    }

    public function test_markers_cover_section_fields_and_first_last_item_fields(): void
    {
        $html = $this->render(true);

        $this->assertStringContainsString('data-svc-variant="numbered"', $html);
        $this->assertStringContainsString('data-editable-field="eyebrow"', $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('data-editable-field="intro"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.title"', $html);
        $this->assertStringContainsString('data-editable-field="items.5.body"', $html);
    }

    public function test_two_items_render_without_error(): void
    {
        $html = $this->render(false, 2);

        $this->assertStringContainsString('data-svc-variant="numbered"', $html);
        $this->assertStringContainsString('01', $html);
        $this->assertStringContainsString('02', $html);
        $this->assertStringContainsString('Item 1', $html);
        $this->assertStringContainsString('Item 2', $html);
        $this->assertStringContainsString('Body 1.', $html);
        $this->assertStringContainsString('Body 2.', $html);
    }

    public function test_suppressed_eyebrow_emits_hidden_marker(): void
    {
        $html = $this->render(true, 6, ['__suppress_eyebrow' => true]);

        $this->assertMatchesRegularExpression(
            '/<span class="hidden"[^>]*data-editable-field="eyebrow"/',
            $html,
        );
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('data-editable-field="intro"', $html);
    }
}
