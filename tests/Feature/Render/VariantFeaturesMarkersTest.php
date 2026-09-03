<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantFeaturesMarkersTest extends TestCase
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
                'variant' => 'markers',
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

    public function test_six_items_emit_variant_tag_plus_markers_and_copy(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('data-svc-variant="markers"', $html);
        $this->assertSame(6, preg_match_all('/>\+<\/span>/', $html));

        foreach (range(1, 6) as $n) {
            $this->assertStringContainsString("Item {$n}", $html);
            $this->assertStringContainsString("Body {$n}.", $html);
        }
    }

    public function test_twelve_items_render_all(): void
    {
        $html = $this->render(false, 12);

        $this->assertStringContainsString('data-svc-variant="markers"', $html);
        $this->assertSame(12, preg_match_all('/>\+<\/span>/', $html));

        foreach (range(1, 12) as $n) {
            $this->assertStringContainsString("Item {$n}", $html);
            $this->assertStringContainsString("Body {$n}.", $html);
        }
    }

    public function test_three_items_render_all(): void
    {
        $html = $this->render(false, 3);

        $this->assertStringContainsString('data-svc-variant="markers"', $html);
        $this->assertSame(3, preg_match_all('/>\+<\/span>/', $html));

        foreach (range(1, 3) as $n) {
            $this->assertStringContainsString("Item {$n}", $html);
            $this->assertStringContainsString("Body {$n}.", $html);
        }
    }

    public function test_markers_cover_parity_fields(): void
    {
        $html = $this->render(true);

        $this->assertStringContainsString('data-svc-variant="markers"', $html);
        $this->assertStringContainsString('data-editable-field="eyebrow"', $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('data-editable-field="intro"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.title"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.body"', $html);
        $this->assertStringContainsString('data-editable-field="items.5.title"', $html);
        $this->assertStringContainsString('data-editable-field="items.5.body"', $html);
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
